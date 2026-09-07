<?php

namespace App\Actions\Errand;

use App\Enums\ErrandStatus;
use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\ErrandRequest;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Every state change an errand can undergo, in one place.
 *
 * The five transitions are small enough that splitting them into five action classes
 * would spread one lock discipline across five files. The lock is the substance here:
 * an errand is published to *everybody*, so two captains pressing «اقبل» within the same
 * second is the normal case, not the edge case.
 */
class ErrandLifecycleAction
{
    public function __construct(private readonly NotificationDispatcher $notifications) {}

    /**
     * A captain takes the job.
     *
     * `lockForUpdate` inside a transaction, and the status is re-read **after** the lock.
     * Checking `status` on the instance the controller resolved would let both captains
     * pass the check and both write, and the loser would find an errand assigned to
     * somebody else on their own screen. The loser gets a 409 they can act on instead.
     */
    public function accept(Driver $driver, ErrandRequest $errand): ErrandRequest
    {
        if ($driver->active_order_id !== null) {
            throw new ApiException('driver_busy', 'لا يمكنك قبول مشوار وأنت على طلب آخر', 422);
        }

        $fresh = DB::transaction(function () use ($driver, $errand): ErrandRequest {
            $locked = ErrandRequest::query()->lockForUpdate()->find($errand->id);

            if ($locked === null || $locked->status !== ErrandStatus::Open || $locked->driver_id !== null) {
                throw new ApiException('errand_taken', 'سبقك كابتن آخر إلى هذا المشوار', 409);
            }

            $locked->update([
                'driver_id' => $driver->id,
                'status' => ErrandStatus::Assigned,
                'assigned_by' => 'driver',
                'assigned_by_user_id' => $driver->user_id,
                'assigned_at' => now(),
            ]);

            $locked->record(ErrandStatus::Assigned, $driver->user, 'driver');

            return $locked;
        });

        // Outside the transaction: a push must never hold a row lock open, and a failed
        // one must never roll back an accept the captain has already been shown.
        $this->announceAssignment($fresh, $driver);

        return $fresh;
    }

    /** An operator hands the job to a captain after nobody took it (doc 40 §3). */
    public function assign(User $admin, ErrandRequest $errand, Driver $driver, ?string $note = null): ErrandRequest
    {
        $fresh = DB::transaction(function () use ($admin, $errand, $driver, $note): ErrandRequest {
            $locked = ErrandRequest::query()->lockForUpdate()->find($errand->id);

            if ($locked === null || $locked->status->isTerminal()) {
                throw new ApiException('errand_closed', 'هذا المشوار منتهٍ ولا يقبل الإسناد', 422);
            }

            $previousDriver = $locked->driver;

            $locked->update([
                'driver_id' => $driver->id,
                'status' => ErrandStatus::Assigned,
                'assigned_by' => 'admin',
                'assigned_by_user_id' => $admin->id,
                'assigned_at' => now(),
            ]);

            $locked->record(ErrandStatus::Assigned, $admin, 'admin', $note);

            // Told before the new captain is, so a reassignment cannot leave the old one
            // riding to a pickup that is no longer theirs.
            if ($previousDriver !== null && $previousDriver->id !== $driver->id) {
                $this->notifications->send($previousDriver->user, 'errand.cancelled_driver', [
                    'reference' => $locked->reference,
                ]);
            }

            return $locked;
        });

        AuditLogger::record($admin, 'errands.assign', $fresh);
        $this->announceAssignment($fresh, $driver);

        return $fresh;
    }

    public function markPickedUp(Driver $driver, ErrandRequest $errand): ErrandRequest
    {
        $this->assertOwnedBy($driver, $errand);

        if ($errand->status !== ErrandStatus::Assigned) {
            throw new ApiException('invalid_transition', 'لا يمكن تأكيد الاستلام في هذه المرحلة', 422);
        }

        $errand->update(['status' => ErrandStatus::PickedUp, 'picked_up_at' => now()]);
        $errand->record(ErrandStatus::PickedUp, $driver->user, 'driver');

        $this->notifications->send($errand->customer, 'errand.picked_up_customer', [
            'total' => number_format((float) $errand->total_due, 2).' ج.م',
        ], deepLink: 'cr7://errands/'.$errand->id, orderId: null);

        return $errand->fresh(['driver.user']);
    }

    public function markDelivered(Driver $driver, ErrandRequest $errand): ErrandRequest
    {
        $this->assertOwnedBy($driver, $errand);

        if ($errand->status !== ErrandStatus::PickedUp) {
            throw new ApiException('invalid_transition', 'أكّد الاستلام أولًا', 422);
        }

        $errand->update(['status' => ErrandStatus::Delivered, 'delivered_at' => now()]);
        $errand->record(ErrandStatus::Delivered, $driver->user, 'driver');

        $this->notifications->send($errand->customer, 'errand.delivered_customer', [],
            deepLink: 'cr7://errands/'.$errand->id);

        return $errand->fresh(['driver.user']);
    }

    /**
     * Cancelling.
     *
     * A customer may pull an errand back until a captain has collected it — after that
     * the captain may already have paid for the goods, and «ألغيت» would leave them out
     * of pocket with no recourse. An admin may cancel at any non-terminal point, which is
     * the escape hatch for exactly that argument.
     */
    public function cancel(ErrandRequest $errand, User $actor, string $role, ?string $reason = null): ErrandRequest
    {
        if ($errand->status->isTerminal()) {
            throw new ApiException('errand_closed', 'هذا المشوار منتهٍ بالفعل', 422);
        }

        if ($role === 'customer' && $errand->status === ErrandStatus::PickedUp) {
            throw new ApiException(
                'errand_already_collected',
                'استلم الكابتن الطلب بالفعل — تواصل مع الدعم لإلغائه',
                422,
            );
        }

        $driver = $errand->driver;

        $errand->update([
            'status' => ErrandStatus::Cancelled,
            'cancelled_by' => $role,
            'cancel_reason' => $reason,
            'cancelled_at' => now(),
        ]);

        $errand->record(ErrandStatus::Cancelled, $actor, $role, $reason);

        if ($driver !== null && $driver->user_id !== $actor->id) {
            $this->notifications->send($driver->user, 'errand.cancelled_driver', [
                'reference' => $errand->reference,
            ]);
        }

        if ($role !== 'customer') {
            AuditLogger::record($actor, 'errands.cancel', $errand);
        }

        return $errand->fresh(['driver.user']);
    }

    /** The captain records what they paid, and the proof. Editable until delivery. */
    public function recordPurchases(Driver $driver, ErrandRequest $errand, float $amount, ?string $note, array $receiptUrls): ErrandRequest
    {
        $this->assertOwnedBy($driver, $errand);

        if ($errand->status->isTerminal()) {
            throw new ApiException('errand_closed', 'لا يمكن تعديل مشوار منتهٍ', 422);
        }

        $errand->update([
            'purchases_amount' => $amount,
            'purchases_note' => $note,
            // Appended, never replaced: a second receipt is a second photo, and a captain
            // who uploads one at the pharmacy and another at the till must not lose the
            // first.
            'receipt_urls' => array_values(array_merge($errand->receipt_urls ?? [], $receiptUrls)),
        ]);

        return $errand->fresh(['driver.user']);
    }

    private function assertOwnedBy(Driver $driver, ErrandRequest $errand): void
    {
        abort_unless($errand->driver_id === $driver->id, 404);
    }

    private function announceAssignment(ErrandRequest $errand, Driver $driver): void
    {
        $this->notifications->send($driver->user, 'errand.taken', [
            'pickup' => $errand->pickup_address_text,
            'reference' => $errand->reference,
        ], deepLink: 'cr7://errands/'.$errand->id);

        $this->notifications->send($errand->customer, 'errand.assigned_customer', [
            'driver' => $driver->user?->name ?? 'الكابتن',
            'pickup' => $errand->pickup_address_text,
        ], deepLink: 'cr7://errands/'.$errand->id);
    }
}
