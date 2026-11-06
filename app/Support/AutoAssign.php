<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Models\Driver;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * doc 99 §3 — D-171. The nearest captain, ten minutes before the food is ready.
 *
 * Owners: «إلغاء نظام عرض المشوار على جميع الكباتن وانتظار قبول أحدهم واستبداله بنظام يحدد
 * أقرب كابتن لموقع المطعم عند جاهزية الطلب ثم يتم إسناد الطلب إليه مباشرة» + «قبل نهاية وقت
 * التحضير بـ10 دقائق».
 *
 * Only while `dispatch.strategy = auto_assign`; with `broadcast` (the default) nothing here
 * runs and dispatch is exactly what it was. Errands are not touched (doc 95).
 *
 * **When** — a `preparing` order with no captain at `prep_due_at − lead` (at once when
 * less than `lead` remains), an unassigned order at `ready`, a direct order made ready
 * now, a requeue, and every `cr7:tick` pass while it is still unassigned.
 *
 * **Who** — online, active, not a review account, not on an errand, **connected** (an
 * open presence session heard from within {@see DriverPresence::CONNECTED_SECONDS}), a fix
 * no older than `dispatch.auto_fix_max_age_seconds` (a parked phone stops sending fixes,
 * and its last one is still where it is), through `DriverTrip`'s capacity and trip-radius
 * gates, and never a captain who refused, stepped off or was taken off this very order.
 * The basket's holder comes first (D-115). Rank: the order's own area, then distance to
 * the **place**, then rating. A captain with no fix is never ranked and never treated as
 * standing at 0,0.
 *
 * **Atomic** — the same cache lock `AcceptOfferAction` takes, then `lockForUpdate` on the
 * captain and the order, every gate checked again, and `TripAssignment::assign(VIA_AUTO)`.
 * Two orders cannot take one captain past his capacity, one order cannot get two captains,
 * and a tick that runs twice changes nothing the second time.
 *
 * **The kitchen is not the captain.** An order assigned before its food is ready is
 * `driver_assigned` with `ready_at = null`; the place keeps it on «قيد التحضير» with its
 * countdown, and «جاهز» or the timer sets `ready_at` and tells the captain.
 */
final class AutoAssign
{
    public const STRATEGY = 'auto_assign';

    /**
     * 2026-11-06 — the owners' correction of D-171: the same captains at the same moment
     * (nearest, free, connected, `prep_due_at − lead`), but as an **offer** they accept or
     * refuse, not an assignment. See {@see EarlyOffer}.
     */
    public const STRATEGY_OFFER = 'auto_offer';

    /** The states in which an unassigned order is waiting for a captain with its food done. */
    public const READY_STATES = ['ready', 'searching_driver', 'waiting_for_driver'];

    public static function strategy(): string
    {
        return (string) SettingsCatalog::get('dispatch.strategy');
    }

    public static function enabled(): bool
    {
        return self::strategy() === self::STRATEGY;
    }

    /** `auto_offer` — captains are offered the order while it cooks (EarlyOffer). */
    public static function offers(): bool
    {
        return self::strategy() === self::STRATEGY_OFFER;
    }

    /**
     * Either mode in which a captain may hold an order before its food is ready: a cooking
     * order a captain steps off goes back to the kitchen, and one past its due time with
     * nobody is stranded.
     */
    public static function beforeReady(): bool
    {
        return self::enabled() || self::offers();
    }

    public static function leadMinutes(): int
    {
        return max(0, SettingsCatalog::int('dispatch.auto_assign_lead_minutes'));
    }

    public static function fixMaxAgeSeconds(): int
    {
        return max(60, SettingsCatalog::int('dispatch.auto_fix_max_age_seconds'));
    }

    /**
     * When the order is to get its captain: `prep_due_at − lead`, or — with no countdown —
     * the moment its food was ready.
     */
    public static function dueAt(Order $order): ?Carbon
    {
        if ($order->prep_due_at === null) {
            return $order->ready_at?->copy();
        }

        return $order->prep_due_at->copy()->subMinutes(self::leadMinutes());
    }

    public static function isDue(Order $order): bool
    {
        if ($order->driver_id !== null) {
            return false;
        }

        $status = $order->status->value;

        if (in_array($status, self::READY_STATES, true)) {
            return true;
        }

        if ($status !== OrderStatus::Preparing->value) {
            return false;
        }

        return $order->prep_due_at === null || ! self::dueAt($order)->isFuture();
    }

    /**
     * Since when nobody could be found for an order still without a captain — the admin's
     * «dispatch» line. `dispatch_started_at` is stamped on the first pass that found nobody.
     */
    public static function noCandidateSince(Order $order): ?Carbon
    {
        if (! self::enabled() || $order->driver_id !== null || $order->dispatch_started_at === null) {
            return null;
        }

        return in_array($order->status->value, [...self::READY_STATES, OrderStatus::Preparing->value], true)
            ? $order->dispatch_started_at
            : null;
    }

    /**
     * Give the order its captain, if it is due and somebody fits.
     *
     * @return Driver|null the captain it went to
     */
    public function run(Order $order): ?Driver
    {
        if (! self::enabled()) {
            return null;
        }

        $order = $order->fresh() ?? $order;

        if (! self::isDue($order)) {
            return null;
        }

        // D-115 — a sibling of a basket somebody carries is theirs. Ready, it is handed to
        // them now; still cooking, it is already on their trip as a held stop, and the
        // basket rule hands it over at `ready`.
        $holder = DriverTrip::holderFor($order);

        if ($holder !== null) {
            if (in_array($order->status->value, self::READY_STATES, true)
                && app(DispatchService::class)->assignToHolder($order)) {
                return $holder;
            }

            return null;
        }

        foreach ($this->candidates($order) as $candidate) {
            if ($this->attempt($order, $candidate['driver'])) {
                $this->bringTheReadySiblings($order);

                return $candidate['driver'];
            }
        }

        $this->noCandidate($order);

        return null;
    }

    /**
     * Every unassigned order that is due — what `cr7:tick` and a captain coming free run.
     * `ready` itself is left out: an order is only there without a captain because the
     * office took him off without a requeue, which is the office asking for it to wait.
     *
     * @return int how many orders got a captain
     */
    public function sweep(int $limit = 25): int
    {
        if (! self::enabled()) {
            return 0;
        }

        $orders = Order::query()
            ->whereNull('driver_id')
            ->where('created_at', '>=', now()->subHours(24))
            ->where(fn ($q) => $q
                ->whereIn('status', [OrderStatus::SearchingDriver->value, OrderStatus::WaitingForDriver->value])
                ->orWhere(fn ($p) => $p
                    ->where('status', OrderStatus::Preparing->value)
                    ->where(fn ($d) => $d
                        ->whereNull('prep_due_at')
                        ->orWhere('prep_due_at', '<=', now()->addMinutes(self::leadMinutes())))))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $assigned = 0;

        foreach ($orders as $order) {
            try {
                if ($this->run($order) !== null) {
                    $assigned++;
                }
            } catch (\Throwable $e) {
                // One order must not cost the others their captain.
                Log::warning('auto-assign failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }
        }

        return $assigned;
    }

    /**
     * The eligible captains for this order, best first.
     *
     * @return Collection<int, array{driver: Driver, distance_km: float, in_zone: bool}>
     */
    public function candidates(Order $order, bool $connectedOnly = true): Collection
    {
        $order->loadMissing('restaurant');
        $place = $order->restaurant;

        if ($place?->latitude === null || $place->longitude === null) {
            return collect();
        }

        $excluded = $order->driverAttempts()
            ->whereIn('result', DriverTrip::EXCLUDING_RESULTS)
            ->pluck('driver_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $pool = Driver::query()
            ->where('drivers.is_active', true)
            ->where('drivers.is_online', true)
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->tap(fn ($q) => ReviewAccess::exceptFromDrivers($q))
            // An offer (`auto_offer`) only needs the switch on and a recent fix: the ring
            // wakes a phone in a pocket. An assignment needs the app heard from just now.
            ->when($connectedOnly, fn ($q) => DriverPresence::whereConnected($q))
            ->whereNotNull('drivers.location_updated_at')
            ->where('drivers.location_updated_at', '>=', now()->subSeconds(self::fixMaxAgeSeconds()))
            ->whereNotIn('drivers.id', $excluded)
            ->when(DispatchService::zoneRestricts(), fn ($q) => $q->where(fn ($inner) => $inner
                ->whereDoesntHave('assignedZones')
                ->orWhereHas('assignedZones', fn ($zq) => $zq->where('service_zones.id', $order->service_zone_id))))
            ->with(['assignedZones:id', 'user'])
            ->get()
            ->filter(fn (Driver $driver) => $driver->hasFix())
            ->values();

        $refusals = DriverTrip::refusals($pool, $order);

        return $pool
            ->filter(fn (Driver $driver) => ($refusals[(int) $driver->id] ?? null) === null)
            ->map(fn (Driver $driver) => [
                'driver' => $driver,
                'distance_km' => Geo::haversineKm((float) $driver->current_lat, (float) $driver->current_lng, (float) $place->latitude, (float) $place->longitude),
                'in_zone' => DispatchService::coversZone($driver, $order),
            ])
            ->sortBy([
                fn ($a, $b) => ($b['in_zone'] ? 1 : 0) <=> ($a['in_zone'] ? 1 : 0),
                fn ($a, $b) => $a['distance_km'] <=> $b['distance_km'],
                fn ($a, $b) => (float) $b['driver']->rating_avg <=> (float) $a['driver']->rating_avg,
                fn ($a, $b) => (int) $a['driver']->id <=> (int) $b['driver']->id,
            ])
            ->values();
    }

    /**
     * Assign this order to this captain if — under the lock, on fresh rows — it is still
     * due, still nobody's, and he still fits. False when anything moved.
     */
    public function attempt(Order $order, Driver $driver): bool
    {
        $lock = Cache::store('database')->lock(
            $order->order_group_id !== null ? "order_group:{$order->order_group_id}:assign" : "order:{$order->id}:assign",
            5,
        );

        if (! $lock->get()) {
            return false;
        }

        try {
            return DB::transaction(function () use ($order, $driver): bool {
                $captain = Driver::query()->lockForUpdate()->find($driver->id);
                $fresh = Order::query()->whereKey($order->id)->lockForUpdate()->first();

                if ($captain === null || $fresh === null || ! self::isDue($fresh) || ! $this->eligible($captain, $fresh)) {
                    return false;
                }

                TripAssignment::assign(
                    $fresh,
                    $captain,
                    OrderStatusActorType::System,
                    null,
                    TripAssignment::VIA_AUTO,
                    history: ['reason_code' => 'auto'],
                    columns: ['dispatch_started_at' => $fresh->dispatch_started_at ?? now()],
                );

                return true;
            });
        } finally {
            $lock->release();
        }
    }

    /** Every gate again, on the locked rows. */
    private function eligible(Driver $captain, Order $order): bool
    {
        if (! $captain->is_active || ! $captain->is_online) {
            return false;
        }

        $captain->loadMissing('user');

        if ($captain->user === null || ! $captain->user->is_active || ReviewAccess::isReviewPhone((string) $captain->user->phone)) {
            return false;
        }

        if (! $captain->hasFix()
            || $captain->location_updated_at === null
            || $captain->location_updated_at->lt(now()->subSeconds(self::fixMaxAgeSeconds()))) {
            return false;
        }

        if (! DriverPresence::isConnected($captain)) {
            return false;
        }

        $refusedHere = $order->driverAttempts()
            ->where('driver_id', $captain->id)
            ->whereIn('result', DriverTrip::EXCLUDING_RESULTS)
            ->exists();

        if ($refusedHere) {
            return false;
        }

        return DriverTrip::refusal($captain, $order) === null;
    }

    /**
     * The captain who just took a sub-order is the basket's holder, so the siblings whose
     * food is already waiting go to him now rather than on the next pass (D-115).
     */
    private function bringTheReadySiblings(Order $order): void
    {
        if ($order->order_group_id === null || ! DriverTrip::oneCaptainPerBasket()) {
            return;
        }

        $siblings = Order::query()
            ->where('order_group_id', $order->order_group_id)
            ->whereKeyNot($order->id)
            ->whereNull('driver_id')
            ->whereIn('status', self::READY_STATES)
            ->get();

        foreach ($siblings as $sibling) {
            app(DispatchService::class)->assignToHolder($sibling);
        }
    }

    /**
     * Nobody fits. The order stays where it is and the next pass tries again; the first
     * failure starts the clock the existing escalation reads. Food already on the counter
     * reads «بانتظار كابتن» (`waiting_for_driver`), the status the no-driver alert knows.
     */
    private function noCandidate(Order $order): void
    {
        $order = $order->fresh();

        if ($order === null || $order->driver_id !== null) {
            return;
        }

        $columns = [];

        if ($order->dispatch_started_at === null) {
            $columns['dispatch_started_at'] = now();
        }

        $status = $order->status->value;

        if (in_array($status, [OrderStatus::Ready->value, OrderStatus::SearchingDriver->value], true)
            && ! $order->driverAttempts()->where('result', 'sent')->exists()) {
            $columns['status'] = OrderStatus::WaitingForDriver;
            $columns['dispatch_last_offer_at'] = now();
        }

        if ($columns === []) {
            return;
        }

        $order->update($columns);

        if (isset($columns['status']) && $order->orderGroup !== null) {
            $group = $order->orderGroup;
            $group->update(['rollup_status' => RollupStatus::compute($group->orders()->get()->pluck('status'))]);
        }
    }
}
