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

            if ($locked === null || ! $locked->status->isUnclaimed() || $locked->driver_id !== null) {
                throw new ApiException('errand_taken', 'سبقك كابتن آخر إلى هذا المشوار', 409);
            }

            // An errand an operator handed to somebody belongs to that conversation until
            // it is answered. Letting a second captain take it off the board while the
            // first is still looking at «اقبل / ارفض» is how two captains ride to the same
            // pickup, and the operator is told a name that is not the one they chose.
            if ($locked->status === ErrandStatus::Offered && $locked->offered_driver_id !== $driver->id) {
                throw new ApiException('errand_offered_elsewhere', 'هذا المشوار مُسند إلى كابتن آخر', 409);
            }

            // The operator who sent the offer keeps the credit for the assignment; the
            // captain pressing «اقبل» is answering it, not taking it off the board.
            $wasOffered = $locked->status === ErrandStatus::Offered;

            $locked->update([
                'driver_id' => $driver->id,
                'status' => ErrandStatus::Assigned,
                'assigned_by' => $wasOffered ? 'admin' : 'driver',
                'assigned_by_user_id' => $wasOffered ? $locked->offered_by_user_id : $driver->user_id,
                'assigned_at' => now(),
                'offered_driver_id' => null,
                'offered_at' => null,
            ]);

            $locked->record(ErrandStatus::Assigned, $driver->user, 'driver');

            return $locked;
        });

        // Outside the transaction: a push must never hold a row lock open, and a failed
        // one must never roll back an accept the captain has already been shown.
        $this->announceAssignment($fresh, $driver);

        return $fresh;
    }

    /**
     * An operator offers the job to a captain (doc 40 §3, revised 2026-09-07).
     *
     * This used to write `driver_id` and be done — the captain learned they owned an
     * errand from a push that told them where to go. Operator ruling: «إسناد الأوردر
     * وإسناد المشوار وإسناد أي إسناد في الدنيا لازم يتبعت notification وصفحة قبول ورفض».
     * A food order has worked this way since 2026-09-08; this is the errand half of the
     * same rule, and it is the reason `offered_driver_id` is a separate column from
     * `driver_id` rather than a flag beside it.
     */
    public function assign(User $admin, ErrandRequest $errand, Driver $driver, ?string $note = null): ErrandRequest
    {
        if ($driver->active_order_id !== null) {
            throw new ApiException('driver_busy', 'هذا الكابتن على طلب آخر', 422);
        }

        $fresh = DB::transaction(function () use ($admin, $errand, $driver, $note): ErrandRequest {
            $locked = ErrandRequest::query()->lockForUpdate()->find($errand->id);

            if ($locked === null || $locked->status->isTerminal()) {
                throw new ApiException('errand_closed', 'هذا المشوار منتهٍ ولا يقبل الإسناد', 422);
            }

            if ($locked->status === ErrandStatus::PickedUp) {
                throw new ApiException('errand_already_collected', 'استلم الكابتن الطلب بالفعل', 422);
            }

            $previousDriver = $locked->driver;

            $locked->update([
                // Cleared, not kept. Between the offer and the answer this errand has no
                // captain, and a stale `driver_id` here is a captain who still sees it on
                // «مشاويري» while somebody else is being asked to take it.
                'driver_id' => null,
                'status' => ErrandStatus::Offered,
                'offered_driver_id' => $driver->id,
                'offered_by_user_id' => $admin->id,
                'offered_at' => now(),
                'assigned_by' => null,
                'assigned_by_user_id' => null,
                'assigned_at' => null,
            ]);

            $locked->record(ErrandStatus::Offered, $admin, 'admin', $note);

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

        // Outside the transaction, for the same reason `accept` is: a push must never
        // hold a row lock, and a provider timeout must never roll back an offer the
        // operator has already been shown as sent.
        $this->notifications->send($driver->user, 'errand.offered', [
            'reference' => $fresh->reference,
            'pickup' => $fresh->pickup_address_text,
            'fee' => number_format((float) $fresh->offered_fee, 2).' ج.م',
        ], deepLink: 'cr7://errands/offer/'.$fresh->id);

        return $fresh;
    }

    /**
     * «ارفض» on the offer screen.
     *
     * Back to the open board, not to nobody: the customer is still waiting, and the
     * captain who declined is recorded so neither the sweeper nor the next assignment
     * asks them again. The operator who sent it is told by name — the whole point of
     * `offered_by_user_id`.
     */
    public function declineOffer(Driver $driver, ErrandRequest $errand, ?string $reason = null): ErrandRequest
    {
        $fresh = DB::transaction(function () use ($driver, $errand, $reason): ErrandRequest {
            $locked = ErrandRequest::query()->lockForUpdate()->find($errand->id);

            if ($locked === null || $locked->status !== ErrandStatus::Offered || $locked->offered_driver_id !== $driver->id) {
                throw new ApiException('errand_not_offered', 'لم يعد هذا العرض متاحًا', 409);
            }

            $declined = array_values(array_unique([...($locked->declined_driver_ids ?? []), $driver->id]));

            $locked->update([
                'status' => ErrandStatus::Open,
                'offered_driver_id' => null,
                'offered_at' => null,
                'declined_driver_ids' => $declined,
                // Re-published from scratch: the captains told about it before the offer
                // went out have all seen it, and the ones who came online since have not.
                'notified_driver_ids' => null,
                'last_published_at' => null,
            ]);

            $locked->record(ErrandStatus::Open, $driver->user, 'driver', $reason ?? 'رفض الكابتن العرض');

            return $locked;
        });

        $operator = $errand->offered_by_user_id === null ? null : User::query()->find($errand->offered_by_user_id);

        if ($operator !== null) {
            $this->notifications->send($operator, 'errand.offer_declined', [
                'reference' => $fresh->reference,
                'driver' => $driver->user?->name ?? 'الكابتن',
            ], deepLink: 'cr7://errands/'.$fresh->id);
        }

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
