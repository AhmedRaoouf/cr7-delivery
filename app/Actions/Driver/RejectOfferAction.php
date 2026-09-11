<?php

namespace App\Actions\Driver;

use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\OrderDriverAttempt;
use App\Support\DispatchService;
use App\Support\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * doc 05 §5 — rejection excludes the driver from every future round; if it was the last
 * open offer, start the next round immediately.
 *
 * **The operator hears about it.** Since 2026-09-08 an admin can hand an order to a named
 * captain as an offer rather than a fact ([OfferOrderToDriverAction]), and an assignment
 * that comes back is the one outcome the operator has to know about — otherwise the order
 * silently rejoins the pool they had just taken it out of. `assigned_by` is who to tell.
 */
class RejectOfferAction
{
    /** The five codes `Driver\OrderController` validates, as an operator reads them. */
    private const REASONS = [
        'too_far' => 'المسافة بعيدة',
        'busy' => 'مشغول بطلب آخر',
        'vehicle_issue' => 'عطل في المركبة',
        'restaurant_issue' => 'مشكلة مع المكان',
        'other' => 'سبب آخر',
    ];

    public function __construct(
        private readonly DispatchService $dispatch,
        private readonly NotificationDispatcher $notifications,
    ) {}

    public function execute(Driver $driver, int $orderId, string $reasonCode): void
    {
        $attempt = $driver->driverAttempts()->where('order_id', $orderId)->where('result', 'sent')->first();

        if (! $attempt) {
            throw new ApiException('not_eligible', 'هذا العرض غير متاح لك', 403);
        }

        DB::transaction(function () use ($driver, $attempt, $reasonCode) {
            $attempt->update(['result' => 'rejected', 'reason_code' => $reasonCode, 'responded_at' => now()]);
            $driver->increment('rejected_offers');
        });

        $this->tellTheOperator($attempt, $driver, $reasonCode);

        $this->dispatch->advanceIfRoundExhausted($attempt->order()->lockForUpdate()->first());

        // «ولو رفضه، ابعت التاني اللي لسه مش لاقي ليه كابتن، وهكذا» — a captain
        // who says no to one order is still on shift and still free, so the next
        // unclaimed order goes to them in the same breath rather than waiting
        // for a sweep. Their `rejected` row on *this* order keeps it from coming
        // straight back.
        $this->dispatch->offerBacklogTo($driver);
    }

    private function tellTheOperator(OrderDriverAttempt $attempt, Driver $driver, string $reasonCode): void
    {
        if ($attempt->assigned_by === null) {
            return;
        }

        try {
            $order = $attempt->order;

            $this->notifications->send(
                $attempt->assignedBy,
                'offer.assignment_declined',
                [
                    'driver' => $driver->user?->name,
                    'order_number' => $order?->order_number,
                    // The raw code («too_far») was printed to an operator as the reason.
                    'reason' => self::REASONS[$reasonCode] ?? $reasonCode,
                ],
                deepLink: '/orders/'.$attempt->order_id,
                orderId: $attempt->order_id,
                // Without this the push had nothing to open: the app routes on `order_id`.
                data: ['order_id' => (string) $attempt->order_id],
            );
        } catch (\Throwable $e) {
            // A captain's «رفض» must land whether or not anyone could be told about it.
            Log::warning('assignment decline notice failed', [
                'order_id' => $attempt->order_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
