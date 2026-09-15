<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderDriverAttempt;
use App\Support\Notifications\OfferContext;
use Illuminate\Support\Facades\DB;

/**
 * doc 63 §3.4 — the one way an order becomes a captain's.
 *
 * A captain's «قبول», D-115's basket rule, the office's forced reassignment and the siblings
 * an accept carries along all write an assignment, and each used to write its own: the
 * accept snapshotted the money and the office did not (doc 62 §4.6), the accept cleared
 * `dispatch_admin_notified_at` and nothing else did. One routine now writes every column,
 * the attempt row, the history row and the rollup, and the model write it ends in is the
 * one `OrderObserver` announces — so the customer, the place and the captain hear the same
 * thing whichever way the order was assigned, and `DriverTrip::refresh` runs on it.
 */
final class TripAssignment
{
    /** A captain pressed «قبول» on an offer. */
    public const VIA_ACCEPT = 'accept';

    /** D-115 — a ready sibling handed to the captain already carrying the basket. */
    public const VIA_BASKET = 'basket';

    /** An operator made it theirs (`ReassignDriverAction`). */
    public const VIA_OFFICE = 'office';

    /**
     * @param  OrderDriverAttempt|null  $attempt  the offer being answered; without one an
     *                                            `accepted` row is written, because unassign, reassign and cancel-trip all work by
     *                                            marking it — and the holder exclusion of §1.2 reads what they mark
     * @param  array{reason_code?: string|null, note?: string|null, meta?: array<string, mixed>|null}  $history
     * @param  array<string, mixed>  $columns  anything else the caller's path writes onto the order
     */
    public static function assign(
        Order $order,
        Driver $driver,
        OrderStatusActorType $actor,
        ?int $actorId,
        string $via = self::VIA_ACCEPT,
        ?OrderDriverAttempt $attempt = null,
        array $history = [],
        array $columns = [],
        ?int $round = null,
    ): Order {
        return DB::transaction(function () use ($order, $driver, $actor, $actorId, $via, $attempt, $history, $columns, $round) {
            $from = $order->status;

            if ($attempt !== null) {
                $attempt->update(['result' => 'accepted', 'responded_at' => now()]);
            } else {
                $order->driverAttempts()->create([
                    'driver_id' => $driver->id,
                    'round' => $round ?? max(1, (int) $order->dispatch_round),
                    'distance_km' => OfferContext::toPlaceKm($order, $driver) ?? 0,
                    'result' => 'accepted',
                    'reason_code' => $history['reason_code'] ?? null,
                    'sent_at' => now(),
                    'responded_at' => now(),
                ]);
            }

            // A `driver` relation loaded before this write is the previous captain's, and
            // the announcement below reads the relation, not the column.
            $order->unsetRelation('driver');

            // Read by `OrderNotifications` while this very write is announced, and only then.
            $order->assignedVia = $via;

            try {
                $order->update([
                    ...$columns,
                    'driver_id' => $driver->id,
                    'status' => OrderStatus::DriverAssigned,
                    'driver_assigned_at' => now(),
                    // D-70 — both halves of the delivery fee, snapshotted together, the same
                    // way on every path. D-105: a waived fee pays nobody.
                    'driver_earning' => DriverEarningCalculator::calculate($order, $driver),
                    'platform_delivery_margin' => DriverEarningCalculator::platformMargin($order, $driver),
                    // A taken order is no longer stranded, so a second stranding — this
                    // captain cancels, an operator unassigns — reaches the operators again.
                    'dispatch_admin_notified_at' => null,
                ]);
            } finally {
                $order->assignedVia = null;
            }

            // Every other captain's open offer on this order is over.
            $order->driverAttempts()
                ->where('driver_id', '!=', $driver->id)
                ->where('result', 'sent')
                ->update(['result' => 'superseded', 'responded_at' => now()]);

            $order->statusHistory()->create([
                'from_status' => $from->value,
                'to_status' => OrderStatus::DriverAssigned->value,
                'actor_type' => $actor,
                'actor_id' => $actorId,
                'reason_code' => $history['reason_code'] ?? null,
                'note' => $history['note'] ?? null,
                'meta' => $history['meta'] ?? null,
            ]);

            $group = $order->orderGroup;
            $group?->update(['rollup_status' => RollupStatus::compute($group->orders()->get()->pluck('status'))]);

            return $order;
        });
    }
}
