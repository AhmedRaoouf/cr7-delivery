<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The time of every stage an order went through — what the admin's order detail shows
 * as «توقيت المراحل» on the web and in the app (doc 100). Both clients render this block
 * as it is and do no time arithmetic of their own, so the two can never disagree.
 *
 * **Where each time comes from.** The order's own `*_at` column, written in the same
 * request as the transition, is the time. It is written *before* the history row — the
 * observer announcing the write runs between the two — so on production the row can be
 * a second or two late (order 1642: column 17:45:20, row 17:45:22). The history row is
 * the fallback when the column is empty although the transition happened; the only
 * writer that does that is `ForceStatusAction`, which writes the status and the row and
 * no column. A stage with neither a column nor a row did not happen and is absent:
 * nothing is guessed.
 *
 * **The rules that are not «read the column»:**
 *
 * - `confirmed` — on a transfer the column is written twice: when the customer confirms
 *   (→ `awaiting_payment`) and again when the office verifies the receipt (→ `confirmed`),
 *   which overwrites the first. So for an order that went through `awaiting_payment` the
 *   customer's confirmation is that history row, and the verification is its own stage,
 *   `payment_verified`, from `payments.verified_at`.
 * - A **direct** order is created by the place already accepted and confirmed, both at the
 *   placement instant; neither is a stage of its own.
 * - **The captain.** `driver_assigned_at` and `arrived_restaurant_at` are not cleared when
 *   a captain is taken off an order, so on an order without a captain they are the last
 *   captain's and are not shown as current. The current captain's stages are the columns
 *   when they are not older than his assignment, else the first history row after his
 *   assignment row. Every earlier captain is listed in `earlier_captains` from the history:
 *   assigned when, arrived when, and when and how the assignment ended.
 * - `ready` can come *after* the captain's assignment (auto-assign ten minutes before the
 *   kitchen is due, doc 99 §3), so stages are listed in the order they happened, not in the
 *   machine's order.
 *
 * Times are emitted as ISO-8601 on the business clock (`Africa/Cairo`, its offset
 * included), which is the clock both clients display regardless of the device's zone.
 */
final class OrderStages
{
    /** The machine's order — the tie-breaker when two stages share a second. */
    private const ORDER = [
        'placed', 'accepted', 'confirmed', 'payment_verified', 'preparing', 'ready',
        'driver_assigned', 'arrived_restaurant', 'picked_up', 'arrived_customer',
        'delivered', 'cancelled',
    ];

    /** The states in which a captain holds the order before pickup. */
    private const HELD = ['driver_assigned', 'arrived_restaurant', 'waiting_at_restaurant'];

    /** The states an unassign, a captain stepping off, or an office offer moves an order to. */
    private const DETACHED = ['searching_driver', 'waiting_for_driver', 'ready', 'preparing'];

    /**
     * @return array{
     *     timezone: string,
     *     stages: list<array<string, mixed>>,
     *     earlier_captains: list<array<string, mixed>>,
     *     durations: array<string, int|null>,
     * }
     */
    public static function for(Order $order): array
    {
        $order->loadMissing(['statusHistory', 'driver.user', 'orderGroup.payment']);

        /** @var Collection<int, OrderStatusHistory> $history */
        $history = $order->statusHistory->sortBy('id')->values();

        $stages = [];
        $add = function (string $key, ?Carbon $at, array $extra = []) use (&$stages): void {
            if ($at !== null) {
                $stages[$key] = ['key' => $key, 'at' => $at->copy(), ...$extra];
            }
        };

        $direct = $order->isDirect();

        // ---------------------------------------------------------------- the kitchen
        $placedRow = $history->first(fn ($r) => $r->from_status === null);
        $add('placed', $order->placed_at ?? $placedRow?->created_at ?? $order->created_at, self::actorOf($placedRow));

        if (! $direct) {
            $acceptedRow = self::firstTo($history, 'accepted');
            $add('accepted', $order->accepted_at ?? $acceptedRow?->created_at, self::actorOf($acceptedRow));

            $customerRow = self::firstTo($history, 'awaiting_payment');
            if ($customerRow !== null) {
                // A transfer: the customer confirmed here; `confirmed_at` is the verification.
                $add('confirmed', $customerRow->created_at, self::actorOf($customerRow));

                $verifiedRow = $history->first(fn ($r) => $r->to_status === 'confirmed' && $r->reason_code === 'payment_verified');
                $payment = $order->orderGroup?->payment;
                $verifiedAt = $payment?->verified_at ?? $verifiedRow?->created_at;
                $add('payment_verified', $verifiedAt, $verifiedRow !== null ? self::actorOf($verifiedRow) : ['actor' => 'admin', 'forced' => false]);
            } else {
                $confirmedRow = self::firstTo($history, 'confirmed');
                $add('confirmed', $order->confirmed_at ?? $confirmedRow?->created_at, self::actorOf($confirmedRow));
            }
        }

        $preparingRow = self::firstTo($history, 'preparing');
        $add('preparing', $order->preparing_at ?? $preparingRow?->created_at, self::actorOf($preparingRow));

        // `ready_at` is written without a history row when the food is ready on a captain's
        // trip, so the source column says who: the place's hand, its timer, or the office.
        $readyRow = self::firstTo($history, 'ready');
        $readySource = $order->ready_source?->value;
        $add('ready', $order->ready_at ?? $readyRow?->created_at, $order->ready_at !== null && $readySource !== null
            ? ['actor' => match ($readySource) {
                'timer' => 'system',
                'admin' => 'admin',
                default => 'restaurant',
            }, 'forced' => false, 'source' => $readySource]
            : self::actorOf($readyRow));

        // ---------------------------------------------------------------- the captain
        $assignments = self::assignments($order, $history);
        $current = null;

        if ($order->driver_id !== null) {
            $currentRow = $assignments->isEmpty() ? null : $assignments->pop()['row'];
            $assignedAt = $order->driver_assigned_at ?? $currentRow?->created_at;

            if ($assignedAt !== null) {
                $add('driver_assigned', $assignedAt, [
                    ...self::actorOf($currentRow),
                    'driver' => self::driverBlock($order->driver),
                    'via' => $order->assigned_via,
                ]);

                $afterId = $currentRow?->id ?? 0;
                foreach ([
                    'arrived_restaurant' => 'arrived_restaurant_at',
                    'picked_up' => 'picked_up_at',
                    'arrived_customer' => 'arrived_customer_at',
                ] as $key => $column) {
                    $row = $history->first(fn ($r) => $r->id > $afterId && $r->to_status === $key);
                    $value = $order->{$column};
                    // A column older than this captain's assignment is an earlier captain's.
                    $at = $value !== null && $value->getTimestamp() >= $assignedAt->getTimestamp() ? $value : $row?->created_at;
                    $add($key, $at, self::actorOf($row));
                }
            }
        } else {
            // No captain now: a pickup the office forced without one is still a pickup.
            $pickedRow = self::firstTo($history, 'picked_up');
            $add('picked_up', $order->picked_up_at ?? $pickedRow?->created_at, self::actorOf($pickedRow));
        }

        // ---------------------------------------------------------------- the end
        $deliveredRow = self::firstTo($history, 'delivered');
        $add('delivered', $order->delivered_at ?? $deliveredRow?->created_at, self::actorOf($deliveredRow));

        $cancelledRow = self::firstTo($history, 'cancelled');
        $add('cancelled', $order->cancelled_at ?? $cancelledRow?->created_at, [
            ...self::actorOf($cancelledRow),
            'reason_code' => $order->cancellation_reason_code,
        ]);

        // ---------------------------------------------------------------- in time order
        $rank = array_flip(self::ORDER);
        $list = array_values($stages);
        usort($list, fn ($a, $b) => [$a['at']->getTimestamp(), $rank[$a['key']]] <=> [$b['at']->getTimestamp(), $rank[$b['key']]]);

        $previous = null;
        $out = [];
        foreach ($list as $stage) {
            $out[] = [
                ...$stage,
                'at' => self::iso($stage['at']),
                'since_previous_seconds' => $previous === null ? null : $stage['at']->getTimestamp() - $previous->getTimestamp(),
            ];
            $previous = $stage['at'];
        }

        return [
            'timezone' => BusinessTime::timezone(),
            'stages' => $out,
            'earlier_captains' => $assignments->map(fn (array $a) => [
                'driver' => $a['driver'] === null ? null : self::driverBlock($a['driver']),
                'assigned_at' => self::iso($a['row']->created_at),
                'arrived_restaurant_at' => self::iso($a['arrived']?->created_at),
                'ended_at' => self::iso($a['ended']?->created_at),
                'ended_by' => $a['ended_by'],
                'reason_code' => $a['ended']?->reason_code,
            ])->values()->all(),
            'durations' => self::durations($order, $stages),
        ];
    }

    /**
     * @param  array<string, array{key: string, at: Carbon}>  $stages
     * @return array<string, int|null>
     */
    private static function durations(Order $order, array $stages): array
    {
        $between = function (string $from, string $to) use ($stages): ?int {
            if (! isset($stages[$from], $stages[$to])) {
                return null;
            }

            $seconds = $stages[$to]['at']->getTimestamp() - $stages[$from]['at']->getTimestamp();

            return $seconds >= 0 ? $seconds : null;
        };

        $preparingAt = $stages['preparing']['at'] ?? null;
        $promised = $preparingAt !== null && $order->prep_due_at !== null
            ? $order->prep_due_at->getTimestamp() - $preparingAt->getTimestamp()
            : null;
        $actual = $between('preparing', 'ready');

        return [
            'prep_actual_seconds' => $actual,
            // What the kitchen promised, extensions included (`prep_due_at`)…
            'prep_promised_seconds' => $promised !== null && $promised >= 0 ? $promised : null,
            // …and what it first said, before any extension.
            'prep_initial_promised_seconds' => $preparingAt !== null && $order->prep_minutes ? (int) $order->prep_minutes * 60 : null,
            'prep_over_by_seconds' => $actual !== null && $promised !== null && $promised >= 0 ? $actual - $promised : null,
            'wait_at_place_seconds' => $between('arrived_restaurant', 'picked_up'),
            'delivery_leg_seconds' => $between('picked_up', 'delivered'),
            'total_seconds' => $between('placed', 'delivered'),
        ];
    }

    /**
     * Every time the order was handed to a captain, oldest first, each with the captain,
     * his arrival at the place and how the hand-over ended. The last one is the current
     * assignment when the order has a captain.
     *
     * @param  Collection<int, OrderStatusHistory>  $history
     * @return Collection<int, array{row: OrderStatusHistory, driver: Driver|null, arrived: OrderStatusHistory|null, ended: OrderStatusHistory|null, ended_by: string|null}>
     */
    private static function assignments(Order $order, Collection $history): Collection
    {
        // `ForceStatusAction` writes a status and nothing else: a forced `driver_assigned`
        // assigned nobody, and a forced move does not take the captain off.
        $isAssignment = fn ($r) => $r->to_status === OrderStatus::DriverAssigned->value && $r->reason_code !== 'force_status';
        $isDetach = fn ($r) => $r->reason_code !== 'force_status'
            && in_array($r->actor_type?->value, ['admin', 'driver'], true)
            && in_array($r->to_status, self::DETACHED, true)
            && (in_array($r->from_status, self::HELD, true) || $r->actor_type?->value === 'admin');

        $rows = $history->filter($isAssignment)->values();
        $drivers = self::driversFor($order, $rows);

        return $rows->map(function ($row, $i) use ($history, $rows, $isAssignment, $isDetach, $drivers) {
            $next = $rows->get($i + 1);
            $ended = $history->first(fn ($r) => $r->id > $row->id && ($isAssignment($r) || $isDetach($r)));
            $arrived = $history->first(fn ($r) => $r->id > $row->id
                && ($ended === null || $r->id < $ended->id)
                && $r->to_status === OrderStatus::ArrivedRestaurant->value);

            $endedBy = null;
            if ($ended !== null) {
                $endedBy = $next !== null && $ended->id === $next->id
                    ? 'replaced'
                    : ($ended->actor_type?->value === 'driver' ? 'captain_stepped_off' : 'removed_by_admin');
            }

            return [
                'row' => $row,
                'driver' => $drivers[$row->id] ?? null,
                'arrived' => $arrived,
                'ended' => $ended,
                'ended_by' => $endedBy,
            ];
        });
    }

    /**
     * Which captain each assignment row handed the order to.
     *
     * A captain's own «قبول» is written with his user as the actor — exact. A row written
     * by the system (basket, auto-assign) or the office names the captain in `meta` since
     * doc 100; an older one is matched to the one captain whose assignment attempt was
     * written in the same seconds, and left unnamed when that is not exactly one.
     *
     * @param  Collection<int, OrderStatusHistory>  $rows
     * @return array<int, Driver|null>
     */
    private static function driversFor(Order $order, Collection $rows): array
    {
        $order->loadMissing('driverAttempts.driver.user');
        $attempts = $order->driverAttempts;

        $result = [];
        foreach ($rows as $row) {
            $driver = null;
            $metaId = is_array($row->meta) ? ($row->meta['driver_id'] ?? null) : null;

            if ($metaId !== null) {
                $driver = $attempts->firstWhere('driver_id', (int) $metaId)?->driver
                    ?? ((int) $order->driver_id === (int) $metaId ? $order->driver : Driver::query()->with('user')->find($metaId));
            } elseif ($row->actor_type?->value === 'driver' && $row->actor_id !== null) {
                $driver = $attempts->first(fn ($a) => (int) $a->driver?->user_id === (int) $row->actor_id)?->driver
                    ?? ((int) $order->driver?->user_id === (int) $row->actor_id ? $order->driver : Driver::query()->with('user')->where('user_id', $row->actor_id)->first());
            } else {
                $at = $row->created_at->getTimestamp();
                $candidates = $attempts->filter(fn ($a) => ! in_array($a->result?->value ?? $a->result, ['sent', 'rejected', 'expired', 'superseded'], true)
                    && $a->sent_at !== null && abs($a->sent_at->getTimestamp() - $at) <= 2)
                    ->pluck('driver')->filter()->unique('id');
                $driver = $candidates->count() === 1 ? $candidates->first() : null;
            }

            $result[$row->id] = $driver;
        }

        return $result;
    }

    /** @param Collection<int, OrderStatusHistory> $history */
    private static function firstTo(Collection $history, string $status): ?OrderStatusHistory
    {
        return $history->first(fn ($r) => $r->to_status === $status);
    }

    /** @return array{actor: string|null, forced: bool} */
    private static function actorOf(?OrderStatusHistory $row): array
    {
        return [
            'actor' => $row?->actor_type?->value,
            'forced' => $row?->reason_code === 'force_status',
        ];
    }

    /** @return array{id: int, name: string|null, phone: string|null} */
    private static function driverBlock(?Driver $driver): ?array
    {
        if ($driver === null) {
            return null;
        }

        return [
            'id' => (int) $driver->id,
            'name' => $driver->user?->name,
            'phone' => $driver->user?->phone,
        ];
    }

    private static function iso(?Carbon $at): ?string
    {
        return $at?->copy()->setTimezone(BusinessTime::timezone())->toIso8601String();
    }
}
