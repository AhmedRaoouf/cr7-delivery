<?php

namespace App\Support;

use App\Enums\ErrandStatus;
use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\ErrandRequest;
use App\Models\Order;
use App\Models\OrderDriverAttempt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * doc 63 D-115…D-117 — the trip, derived from `orders` and never stored.
 *
 * Operator, 2026-09-15: «خلّي الـ Delivery الواحد أصلًا ممكن يجيله أكتر من order مع بعض
 * طالما الأماكن قريبة من بعض … والـ Delivery الواحد يبقى معاه maximum تلت orders for one
 * trip». Until today a captain carried exactly one order, and that rule lived in four
 * `whereNull('active_order_id')` clauses and an `already_on_a_trip` guard.
 *
 * A captain's **trip** is every order with `driver_id` = the captain in a post-assignment,
 * non-terminal state ({@see self::STATES}). Nothing records it: `drivers.active_order_id`
 * is the trip's **focus** — the order of the first stop the captain can act on — and
 * `drivers.is_available` means «carries nothing». Both are recomputed from the rows by
 * {@see self::refresh()}, which `OrderObserver` calls on every write that moves an order's
 * status or captain, so a leftover focus cannot be written and there is nothing left to
 * sweep — which is all `DispatchService::releaseIfStale()` ever did.
 *
 * A captain **holds** an unassigned sibling of a basket they carry (D-115): a sub-order
 * still in the kitchen whose captain will be them the moment it is ready. It is not theirs
 * yet — `driver_id` is null — but it counts against their room and it is on their stop
 * list with its countdown.
 */
final class DriverTrip
{
    /** §2.1 — in a captain's hands: assigned and not over. `delivery_issue` counts until resolved. */
    public const STATES = [
        'driver_assigned', 'arrived_restaurant', 'waiting_at_restaurant',
        'picked_up', 'on_the_way', 'arrived_customer', 'delivery_issue',
    ];

    /** Assigned and not collected yet — a pickup still to make. */
    public const PICKUP_STATES = ['driver_assigned', 'arrived_restaurant', 'waiting_at_restaurant'];

    /** Unassigned with the food already on the counter. */
    public const READY_STATES = ['ready', 'searching_driver', 'waiting_for_driver'];

    /**
     * §1.2 — a captain who refused an order, stepped off it or had it taken off them is
     * never its holder. Without this, the retry that follows each of those paths would hand
     * the order straight back to the captain it had just left.
     */
    public const EXCLUDING_RESULTS = ['rejected', 'cancelled_after_accept', 'unassigned_by_admin', 'reassigned_by_admin'];

    /** §2.4 — what the accept path answers, and what the office's list prints on a row. */
    public const TRIP_FULL = 'trip_full';

    public const TOO_FAR = 'too_far_for_trip';

    /** Gate 4 — errands stay exclusive, both ways. */
    public const ON_AN_ERRAND = 'on_an_errand';

    // ------------------------------------------------------------------ settings

    public static function oneCaptainPerBasket(): bool
    {
        return SettingsCatalog::bool('dispatch.one_captain_per_basket');
    }

    public static function batching(): bool
    {
        return SettingsCatalog::bool('dispatch.batching_enabled');
    }

    /**
     * The most orders one trip may carry. With batching off a trip is one order — plus the
     * rest of its basket, which is never split and never capped for a captain carrying
     * nothing.
     */
    public static function max(): int
    {
        return self::batching() ? max(1, SettingsCatalog::int('dispatch.max_orders_per_trip')) : 1;
    }

    public static function radiusKm(): float
    {
        return max(0.0, SettingsCatalog::float('dispatch.batch_radius_km'));
    }

    // ---------------------------------------------------------------- the trip

    /** @return Builder<Order> */
    public static function query(Driver|int $driver): Builder
    {
        return Order::query()
            ->where('driver_id', $driver instanceof Driver ? $driver->id : $driver)
            ->whereIn('status', self::STATES);
    }

    public static function count(Driver|int $driver): int
    {
        return self::query($driver)->count();
    }

    /**
     * Gate 4. An errand never wrote `active_order_id` or `is_available`, so until doc 63 a
     * captain mid-errand was offered food orders. This reads the errand's own row.
     */
    public static function onErrand(Driver|int $driver): bool
    {
        return ErrandRequest::query()
            ->where('driver_id', $driver instanceof Driver ? $driver->id : $driver)
            ->whereIn('status', [ErrandStatus::Assigned->value, ErrandStatus::PickedUp->value])
            ->exists();
    }

    /**
     * §1.2 — who carries this basket, if anyone: the captain of the **earliest-assigned**
     * live sibling of `$order`, skipping any captain who refused, cancelled or was removed
     * from `$order` itself, and any captain who has been deactivated.
     */
    public static function holderFor(Order $order): ?Driver
    {
        if ($order->order_group_id === null || ! self::oneCaptainPerBasket()) {
            return null;
        }

        $siblings = Order::query()
            ->where('order_group_id', $order->order_group_id)
            ->whereKeyNot($order->id)
            ->whereNotNull('driver_id')
            ->whereIn('status', self::STATES)
            ->orderBy('driver_assigned_at')
            ->orderBy('id')
            ->with('driver.user')
            ->get();

        if ($siblings->isEmpty()) {
            return null;
        }

        $excluded = OrderDriverAttempt::query()
            ->where('order_id', $order->id)
            ->whereIn('result', self::EXCLUDING_RESULTS)
            ->pluck('driver_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($siblings as $sibling) {
            $driver = $sibling->driver;

            if ($driver === null || ! $driver->is_active || $driver->user?->is_active === false) {
                continue;
            }

            if (! in_array((int) $driver->id, $excluded, true)) {
                return $driver;
            }
        }

        return null;
    }

    /**
     * The sub-orders a captain takes by taking `$order`: itself, and every live,
     * unassigned sibling nobody else holds. `$order` first.
     *
     * @return Collection<int, Order>
     */
    public static function basketOrders(Order $order): Collection
    {
        $members = collect([$order]);

        if ($order->order_group_id === null || ! self::oneCaptainPerBasket()) {
            return $members;
        }

        $siblings = Order::query()
            ->where('order_group_id', $order->order_group_id)
            ->whereKeyNot($order->id)
            ->whereNull('driver_id')
            ->whereNotIn('status', [OrderStatus::Delivered->value, OrderStatus::Cancelled->value])
            ->orderBy('group_sequence')
            ->orderBy('id')
            ->get();

        return $members->concat($siblings->filter(fn (Order $sibling) => self::holderFor($sibling) === null))->values();
    }

    /** §2.1's `need` — the live, unheld sub-orders of the basket, at least one. */
    public static function need(Order $order): int
    {
        return max(1, self::basketOrders($order)->count());
    }

    /**
     * The siblings this captain holds: unassigned sub-orders of the baskets in their trip
     * whose holder is this captain.
     *
     * @param  Collection<int, Order>|null  $trip
     * @return EloquentCollection<int, Order>
     */
    public static function held(Driver $driver, ?Collection $trip = null, ?int $exceptOrderId = null): EloquentCollection
    {
        if (! self::oneCaptainPerBasket()) {
            return new EloquentCollection;
        }

        $trip ??= self::query($driver)->get();
        $groupIds = $trip->pluck('order_group_id')->filter()->unique()->values()->all();

        if ($groupIds === []) {
            return new EloquentCollection;
        }

        return Order::query()
            ->whereIn('order_group_id', $groupIds)
            ->whereNull('driver_id')
            ->whereNotIn('status', [OrderStatus::Delivered->value, OrderStatus::Cancelled->value])
            ->when($exceptOrderId !== null, fn ($q) => $q->whereKeyNot($exceptOrderId))
            ->get()
            ->filter(fn (Order $sibling) => (int) self::holderFor($sibling)?->id === (int) $driver->id)
            ->values();
    }

    // ------------------------------------------------------------------ gates

    /**
     * `canTake` — gates 2–4 of §2.1 for one captain and one order: null when they may take
     * it, otherwise the refusal code of §2.4.
     *
     * @param  bool  $enforceRadius  gate 3 — false for an order the office handed this
     *                               captain by name, where distance is the operator's call
     */
    public static function refusal(Driver $driver, Order $order, bool $enforceRadius = true): ?string
    {
        return self::refusals(collect([$driver]), $order, $enforceRadius)[(int) $driver->id] ?? null;
    }

    /**
     * The same gates for a whole pool in a handful of queries — what dispatch calls.
     *
     * @param  Collection<int, Driver>  $drivers
     * @return array<int, string|null> keyed by driver id
     */
    public static function refusals(Collection $drivers, Order $order, bool $enforceRadius = true): array
    {
        if ($drivers->isEmpty()) {
            return [];
        }

        $ids = $drivers->pluck('id')->map(fn ($id) => (int) $id)->all();

        $trips = Order::query()
            ->whereIn('driver_id', $ids)
            ->whereIn('status', self::STATES)
            ->whereKeyNot($order->id)
            ->with('restaurant:id,latitude,longitude')
            ->get()
            ->groupBy('driver_id');

        $onErrand = ErrandRequest::query()
            ->whereIn('driver_id', $ids)
            ->whereIn('status', [ErrandStatus::Assigned->value, ErrandStatus::PickedUp->value])
            ->pluck('driver_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $need = null;
        $max = self::max();
        $refusals = [];

        foreach ($drivers as $driver) {
            $id = (int) $driver->id;

            if (in_array($id, $onErrand, true)) {
                $refusals[$id] = self::ON_AN_ERRAND;

                continue;
            }

            /** @var Collection<int, Order> $trip */
            $trip = $trips->get($id, collect());

            // An empty-handed captain is never capped: a basket bigger than the maximum
            // still goes to somebody, so no setting can make an order undeliverable.
            if ($trip->isEmpty()) {
                $refusals[$id] = null;

                continue;
            }

            $need ??= self::need($order);
            $load = $trip->count() + self::held($driver, $trip, $order->id)->count();

            if (! self::batching() || $load + $need > $max) {
                $refusals[$id] = self::TRIP_FULL;

                continue;
            }

            $refusals[$id] = $enforceRadius && ! self::nearTrip($order, $trip) ? self::TOO_FAR : null;
        }

        return $refusals;
    }

    /**
     * §3.6 — the office's gate is capacity only. Moving one sub-order to a captain adds
     * one bag to their trip; how far it is from the rest is the operator's call.
     */
    public static function capacityRefusal(Driver $driver, Order $order): ?string
    {
        $trip = self::query($driver)->whereKeyNot($order->id)->get();

        if ($trip->isEmpty()) {
            return null;
        }

        $load = $trip->count() + self::held($driver, $trip, $order->id)->count();

        return ! self::batching() || $load + 1 > self::max() ? self::TRIP_FULL : null;
    }

    /** Gate 3 — `$order`'s place within the batch radius of a place already in the trip. */
    private static function nearTrip(Order $order, Collection $trip): bool
    {
        $place = $order->relationLoaded('restaurant')
            ? $order->restaurant
            : $order->restaurant()->first(['id', 'latitude', 'longitude']);
        $radius = self::radiusKm();

        foreach ($trip as $carried) {
            if ((int) $carried->restaurant_id === (int) $order->restaurant_id) {
                return true;
            }

            $other = $carried->restaurant;

            if ($place?->latitude === null || $place->longitude === null
                || $other?->latitude === null || $other->longitude === null) {
                continue;
            }

            if (Geo::haversineKm((float) $place->latitude, (float) $place->longitude, (float) $other->latitude, (float) $other->longitude) <= $radius) {
                return true;
            }
        }

        return false;
    }

    /** The sentence the accept path answers each refusal with (§2.4). */
    public static function refusalMessage(string $code): string
    {
        return match ($code) {
            self::TRIP_FULL => 'رحلتك مكتملة — '.self::ordersAtMost(self::max()).' كحدٍّ أقصى',
            self::TOO_FAR => 'مكان هذا الطلب بعيد عن أماكن رحلتك الحالية',
            self::ON_AN_ERRAND => 'أنت في مشوار الآن — أنهِه أولًا',
            default => 'هذا العرض غير متاح لك',
        };
    }

    /** «طلب واحد» · «طلبان» · «‪3‬ طلبات» — the count said the way Arabic counts it. */
    private static function ordersAtMost(int $max): string
    {
        return match (true) {
            $max <= 1 => 'طلب واحد',
            $max === 2 => 'طلبان',
            $max <= 10 => '‪'.$max.'‬ طلبات',
            default => '‪'.$max.'‬ طلبًا',
        };
    }

    // --------------------------------------------------------------- the stops

    /**
     * §3.2 — pickups first, then drop-offs. Pickups: those whose food is ready before
     * those still in the kitchen, then nearest to the captain; drop-offs nearest first.
     * Advice, not a rule: every per-order endpoint checks only `driver_id`.
     *
     * @param  Collection<int, Order>  $assigned
     * @param  Collection<int, Order>  $held
     * @return list<array{kind: string, order: Order, ready: bool, km: float|null}>
     */
    private static function ordered(Driver $driver, Collection $assigned, Collection $held): array
    {
        $pickups = $assigned
            ->filter(fn (Order $order) => in_array($order->status->value, self::PICKUP_STATES, true))
            ->concat($held)
            ->map(fn (Order $order) => [
                'kind' => 'pickup',
                'order' => $order,
                'ready' => self::isReady($order),
                'km' => self::kmTo($driver, $order->restaurant?->latitude, $order->restaurant?->longitude),
            ])
            ->sortBy([
                fn ($a, $b) => ($a['ready'] ? 0 : 1) <=> ($b['ready'] ? 0 : 1),
                fn ($a, $b) => ($a['km'] ?? INF) <=> ($b['km'] ?? INF),
                fn ($a, $b) => $a['order']->id <=> $b['order']->id,
            ]);

        $dropoffs = $assigned->concat($held)
            ->map(fn (Order $order) => [
                'kind' => 'dropoff',
                'order' => $order,
                'ready' => true,
                'km' => self::kmTo($driver, $order->delivery_latitude, $order->delivery_longitude),
            ])
            ->sortBy([
                fn ($a, $b) => ($a['km'] ?? INF) <=> ($b['km'] ?? INF),
                fn ($a, $b) => $a['order']->id <=> $b['order']->id,
            ]);

        return $pickups->concat($dropoffs)->values()->all();
    }

    /**
     * @param  Collection<int, Order>  $assigned
     * @param  Collection<int, Order>  $held
     * @return list<array<string, mixed>>
     */
    public static function stops(Driver $driver, Collection $assigned, Collection $held): array
    {
        return array_map(function (array $row) use ($driver) {
            /** @var Order $order */
            $order = $row['order'];
            $common = [
                'kind' => $row['kind'],
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'basket_id' => $order->order_group_id,
                'assigned' => (int) $order->driver_id === (int) $driver->id,
                'distance_km' => $row['km'] === null ? null : round($row['km'], 2),
            ];

            if ($row['kind'] === 'pickup') {
                return $common + [
                    'place' => OrderParties::place($order),
                    'state' => $row['ready'] ? 'ready' : 'preparing',
                    'ready_in_seconds' => $row['ready'] ? null : self::readyInSeconds($order),
                ];
            }

            return $common + [
                'customer_area' => $order->delivery_address_text,
                'address' => OrderParties::customer($order)['address'],
            ];
        }, self::ordered($driver, $assigned, $held));
    }

    /**
     * D-117 — `active_order_id` is the order of the first stop the captain can act on, and
     * `is_available` is «carries nothing». Written only when either changed, through the
     * query builder — so it fires no model event and cannot recurse into the observer that
     * calls it — and never by hand anywhere else.
     */
    public static function refresh(Driver|int|null $driver): void
    {
        $id = $driver instanceof Driver ? (int) $driver->id : $driver;

        if ($id === null || $id === 0) {
            return;
        }

        $row = Driver::withTrashed()->whereKey($id)->first(['id', 'active_order_id', 'is_available', 'current_lat', 'current_lng']);

        if ($row === null) {
            return;
        }

        $trip = self::query($id)->with('restaurant:id,latitude,longitude')->get();
        $focus = self::focusOf($row, $trip);

        $values = ['active_order_id' => $focus?->id, 'is_available' => $trip->isEmpty()];

        if ($row->active_order_id !== $values['active_order_id'] || (bool) $row->is_available !== $values['is_available']) {
            Driver::withTrashed()->whereKey($id)->update($values);
        }

        // The caller's instance is told too, so it cannot write a stale value back.
        if ($driver instanceof Driver) {
            $driver->forceFill($values)->syncOriginalAttributes(array_keys($values));
        }
    }

    /** The focus, without writing it. */
    public static function focus(Driver $driver): ?Order
    {
        return self::focusOf($driver, self::query($driver)->with('restaurant:id,latitude,longitude')->get());
    }

    /** @param Collection<int, Order> $trip */
    private static function focusOf(Driver $driver, Collection $trip): ?Order
    {
        // Held siblings are never the focus — nobody can act on an order that is not
        // theirs yet — so the stop order without them is enough to find it.
        return self::ordered($driver, $trip, collect())[0]['order'] ?? null;
    }

    /**
     * §3.3 — `GET /driver/trip`.
     *
     * @return array<string, mixed>
     */
    public static function present(Driver $driver): array
    {
        $assigned = self::query($driver)->with('restaurant')->get();
        $held = self::held($driver, $assigned)->load('restaurant');
        $stops = self::stops($driver, $assigned, $held);

        $firstStop = [];
        foreach ($stops as $index => $stop) {
            $firstStop[$stop['order_id']] ??= $index;
        }

        $all = $assigned->concat($held)
            ->sortBy(fn (Order $order) => $firstStop[$order->id] ?? PHP_INT_MAX)
            ->values();

        // D-103 — the cash the trip still needs: what the captain hands each place he has
        // not collected from yet, and everything he takes at the doors.
        $payPlaces = $all
            ->filter(fn (Order $order) => $order->driver_id === null || in_array($order->status->value, self::PICKUP_STATES, true))
            ->sum(fn (Order $order) => $order->placePaidAtPickup());

        return [
            'orders' => $all->map(fn (Order $order) => OrderParties::presentForCaptain($order) + [
                'basket_id' => $order->order_group_id,
                'stop_index' => $firstStop[$order->id] ?? null,
                'assigned' => (int) $order->driver_id === (int) $driver->id,
            ])->all(),
            'stops' => $stops,
            'capacity' => [
                'count' => $assigned->count(),
                'held' => $held->count(),
                'max' => self::max(),
                'batching' => self::batching(),
                'radius_km' => self::radiusKm(),
            ],
            'cash' => [
                'pay_places_total' => round((float) $payPlaces, 2),
                'collect_total' => round((float) $all->sum(fn (Order $order) => $order->cashToCollect()), 2),
            ],
        ];
    }

    // ---------------------------------------------------------------- the offer

    /**
     * §2.3 — «معك الآن ‪{count}‬ · ستصبح ‪{count+need}‬ من ‪{max}‬». `after` also counts the
     * siblings the captain already holds, because those will be on the trip too.
     *
     * @return array<string, mixed>
     */
    public static function offerTrip(Driver $driver, Order $order): array
    {
        $trip = self::query($driver)->whereKeyNot($order->id)->get();
        $count = $trip->count();
        $held = $count > 0 ? self::held($driver, $trip, $order->id)->count() : 0;
        $need = self::need($order);
        $max = self::max();
        $after = $count + $held + $need;

        return [
            'count' => $count,
            'held' => $held,
            'need' => $need,
            'after' => $after,
            'max' => $max,
            'label' => 'معك الآن ‪'.$count.'‬ · ستصبح ‪'.$after.'‬ من ‪'.$max.'‬',
            'label_en' => "You carry {$count} · it becomes {$after} of {$max}",
        ];
    }

    /**
     * §1.3 — the basket a captain is offered when they are offered any sub-order of it:
     * every sibling they would carry, with its place, its distance from the captain now,
     * whether its food is ready, and what they earn on it. Null for a one-place basket.
     *
     * @return array<string, mixed>|null
     */
    public static function offerBasket(Driver $driver, Order $order): ?array
    {
        $members = self::basketOrders($order);

        if ($members->count() < 2) {
            return null;
        }

        $members = EloquentCollection::make($members->all())->loadMissing(['restaurant', 'orderGroup']);
        $open = $driver->driverAttempts()
            ->whereIn('order_id', $members->pluck('id'))
            ->where('result', 'sent')
            ->pluck('order_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $orders = $members->map(function (Order $member) use ($driver, $open) {
            $ready = self::isReady($member);
            $km = self::kmTo($driver, $member->restaurant?->latitude, $member->restaurant?->longitude);

            return [
                'order_id' => $member->id,
                'order_number' => $member->order_number,
                'state' => $ready ? 'ready' : 'preparing',
                'status' => $member->status->value,
                'place' => [
                    'id' => $member->restaurant?->id,
                    'name' => $member->restaurant?->name_ar,
                    'name_en' => $member->restaurant?->name_en,
                    'logo' => $member->restaurant?->logo,
                    'latitude' => $member->restaurant?->latitude === null ? null : (float) $member->restaurant->latitude,
                    'longitude' => $member->restaurant?->longitude === null ? null : (float) $member->restaurant->longitude,
                ],
                'distance_km' => $km === null ? null : round($km, 2),
                'ready_in_seconds' => $ready ? null : self::readyInSeconds($member),
                'your_earning' => DriverEarningCalculator::calculate($member, $driver),
                'free_delivery_reason' => $member->free_delivery_reason,
                // D-103, per stop: what this captain hands this place at its counter and
                // what they collect at the door for it — the offer's own two fields, for
                // every sibling and not only the lead.
                'paid_at_pickup' => $member->placePaidAtPickup(),
                'cash_to_collect' => $member->cashToCollect(),
                'offered' => in_array((int) $member->id, $open, true),
            ];
        })->values();

        return [
            'id' => $order->order_group_id,
            'group_number' => $order->orderGroup?->group_number,
            'size' => $orders->count(),
            'lead_order_id' => $order->id,
            'orders' => $orders->all(),
            'total_earning' => round((float) $orders->sum('your_earning'), 2),
            'pay_places_total' => round((float) $orders->sum('paid_at_pickup'), 2),
            'cash_to_collect_total' => round((float) $orders->sum('cash_to_collect'), 2),
        ];
    }

    /**
     * Is the food on the counter? An assigned order is, unless an operator handed it over
     * before the kitchen finished — then its countdown is still running.
     */
    private static function isReady(Order $order): bool
    {
        $status = $order->status->value;

        if (in_array($status, self::READY_STATES, true)) {
            return true;
        }

        if (in_array($status, self::PICKUP_STATES, true)) {
            return $order->ready_at !== null || $order->prep_due_at === null || ! $order->prep_due_at->isFuture();
        }

        return in_array($status, self::STATES, true);
    }

    private static function readyInSeconds(Order $order): ?int
    {
        return $order->prep_due_at === null ? null : max(0, (int) now()->diffInSeconds($order->prep_due_at, false));
    }

    private static function kmTo(Driver $driver, mixed $lat, mixed $lng): ?float
    {
        if (! $driver->hasFix() || $lat === null || $lng === null) {
            return null;
        }

        return Geo::haversineKm((float) $driver->current_lat, (float) $driver->current_lng, (float) $lat, (float) $lng);
    }
}
