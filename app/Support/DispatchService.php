<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Events\DriverOfferSent;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Setting;
use App\Support\Notifications\NotificationDispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * doc 05 §1-3 — dispatch starts the instant an order becomes `ready`, offering to the
 * nearest 3 online drivers.
 *
 * **Time now passes between rounds.** This class used to say that ExpireRound and
 * ExpirePrepTimer were «not wired up in this pass», and they were not: `dispatchRound`
 * recursed straight into round 2 and 3 when a round came up empty, so all three rounds
 * ran inside one millisecond against the same set of captains. The radius grew and
 * nothing else did. A captain who came online eleven seconds later was never asked,
 * and the order was already in `waiting_for_driver` with nothing left to advance it.
 *
 * `App\Console\Commands\TickCommand` is that missing clock. `dispatchRound` no longer
 * recurses; it parks the order and the sweeper comes back to it.
 */
class DispatchService
{
    /**
     * @param  bool  $retry  A retry of a stranded order rather than the next round of a
     *                       live search. A captain who let an offer expire — phone in a
     *                       pocket, ten minutes ago — is a candidate again; one who
     *                       pressed «رفض» is not, and stays excluded either way.
     */
    public function dispatchRound(Order $order, int $round, bool $retry = false): void
    {
        $settings = Setting::query()->whereIn('key', [
            'dispatch.batch_size', 'dispatch.radius_km', 'dispatch.radius_expand_km',
            'dispatch.max_rounds',
            'dispatch.location_stale_seconds', 'driver.location_stale_seconds',
            'dispatch.last_call_all_drivers', 'dispatch.offer_to_all_online',
        ])->pluck('value', 'key');

        $batchSize = (int) ($settings['dispatch.batch_size'] ?? 3);
        $baseRadius = (float) ($settings['dispatch.radius_km'] ?? 5);
        $radiusExpand = (float) ($settings['dispatch.radius_expand_km'] ?? 2);
        $maxRounds = (int) ($settings['dispatch.max_rounds'] ?? 3);
        $staleSeconds = self::staleSeconds($settings);

        $radius = $baseRadius + ($round - 1) * $radiusExpand;

        // The last round is a last call: no circle and no batch cap, so an order
        // cannot end up unreachable merely because every free captain is further
        // out than the ladder happened to climb. Read from the device that found
        // this: the only captain on shift stood 7.42 km from the restaurant, the
        // ladder reached 5 then 7, and nobody was ever asked. Ranking is
        // untouched — nearest first, then rating, then completed orders — so the
        // captain who would have won a normal round still hears first.
        $lastCall = $round >= $maxRounds
            && filter_var($settings['dispatch.last_call_all_drivers'] ?? true, FILTER_VALIDATE_BOOL);

        // `dispatch.offer_to_all_online` makes **round 1** that last call, and it is
        // on by default.
        //
        // The last-call rule above only helps an order that reaches the last round,
        // and reaching it costs two TTLs of `cr7:tick`. Measured on the deployment,
        // 2026-09-05: order 39 sat in `waiting_for_driver` at `dispatch_round = 1`
        // with **zero** `order_driver_attempts` rows seven minutes after it was
        // marked ready, while the one live captain stood 7.53 km out — outside the
        // 5 km first circle and inside every later one. Nobody was offered anything,
        // no push was sent, and the board read «بانتظار الكابتن» the whole time. A
        // ladder that needs a clock to climb strands every order on a host where the
        // clock is not wired, and this platform runs one town.
        //
        // Distance still ranks and never hides: nearest first, then rating, then
        // completed orders. A captain who has never sent a fix has no distance and
        // sorts last, but is still asked — «متصل وفاضي» is the whole condition.
        $broadcast = $lastCall || filter_var(
            $settings['dispatch.offer_to_all_online'] ?? true,
            FILTER_VALIDATE_BOOL,
        );

        /*
         * Who is already out of this round.
         *
         * 🔴 The `orWhere` was not grouped, so it escaped the relation's own
         * `order_id = ?` and the subquery read «…this order's attempts… OR anybody
         * holding an open offer **anywhere**». One captain sitting on an unanswered
         * offer for order A was therefore excluded from every other order in the town
         * for as long as it stayed open — and offers stopped expiring on 2026-09-08, so
         * «as long as it stays open» became «forever».
         */
        $excludedDriverIds = $order->driverAttempts()
            ->when($retry, fn ($q) => $q->where(fn ($inner) => $inner
                ->whereIn('result', ['rejected', 'cancelled_after_accept'])
                ->orWhere(fn ($open) => $open->where('result', 'sent')->whereNull('expires_at'))))
            ->pluck('driver_id');

        $candidates = Driver::query()
            ->where('is_active', true)
            ->where('is_online', true)
            ->where('is_available', true)
            ->whereNull('active_order_id')
            // A stale fix is a reason to rank a captain last, not a reason to keep an
            // order in the kitchen. Four of the seven captains on the deployment had
            // never pinged from the current build, so a freshness gate ahead of the
            // ranking silently emptied the batch.
            ->when(! $broadcast, fn ($q) => $q
                ->whereNotNull('location_updated_at')
                ->where('location_updated_at', '>=', now()->subSeconds($staleSeconds)))
            ->whereNotIn('id', $excludedDriverIds)
            // A captain with no zone rows is **unrestricted**, not «matches no zone».
            // `ADM-16` never required a zone and `CreateDriverAction` syncs `zone_ids`
            // only when they are sent, so every captain created from the dashboard had
            // an empty `service_zone_driver` — and a bare `whereHas` then matched nobody.
            // Every round found an empty batch, every order fell through to
            // `waiting_for_driver`, and no captain was ever offered anything.
            ->where(fn ($q) => $q
                ->whereDoesntHave('assignedZones')
                ->orWhereHas('assignedZones', fn ($zq) => $zq->where('service_zones.id', $order->service_zone_id)))
            ->get()
            ->map(fn (Driver $driver) => [
                'driver' => $driver,
                // Casting a null coordinate to float gives 0.0, and 0.0 is a real place
                // 3 000 km west of Africa — it made a captain who had never reported a
                // position the *nearest* one to every restaurant in the country.
                'distance_km' => $driver->hasFix()
                    ? Geo::haversineKm((float) $driver->current_lat, (float) $driver->current_lng, (float) $order->restaurant->latitude, (float) $order->restaurant->longitude)
                    : null,
            ])
            ->filter(fn ($c) => $broadcast || ($c['distance_km'] !== null && $c['distance_km'] <= $radius))
            ->sortBy([
                fn ($a, $b) => ($a['distance_km'] ?? INF) <=> ($b['distance_km'] ?? INF),
                fn ($a, $b) => $b['driver']->rating_avg <=> $a['driver']->rating_avg,
                fn ($a, $b) => $b['driver']->completed_orders <=> $a['driver']->completed_orders,
            ]);

        $candidates = $broadcast ? $candidates : $candidates->take($batchSize);

        if ($candidates->isEmpty()) {
            // Nobody *new* — which is not the same as nobody. An offer with no
            // deadline is still in somebody's hand, and flipping the order to
            // `waiting_for_driver` here would put «لم يقبل الطلب أي كابتن» on the
            // board and in the operator's alert dock while three captains were
            // still looking at a live «قبول» button. The widening sweep runs every
            // minute, so without this guard it would do exactly that, every minute.
            $stillOpen = $order->driverAttempts()->where('result', 'sent')->exists();

            if ($stillOpen) {
                $order->update([
                    'dispatch_round' => $round,
                    'dispatch_last_offer_at' => now(),
                ]);

                return;
            }

            // Recursing here is what made rounds free. The next round is a wider circle
            // *and* a later moment; collapsing the two into one request meant the widest
            // circle was searched at the same instant as the narrowest, and an order
            // whose captain logged in half a minute later never heard from anyone.
            // `cr7:tick` calls back in with `$round + 1` once the TTL has actually run.
            $order->update([
                'status' => OrderStatus::WaitingForDriver,
                'dispatch_round' => $round,
                'dispatch_started_at' => $order->dispatch_started_at ?? now(),
                'dispatch_last_offer_at' => now(),
            ]);
            $this->syncGroupRollup($order);

            return;
        }

        /*
         * **No `expires_at`.** Operator ruling, 2026-09-08: «ما يكونش 30 ثانية، هو
         * يكون موجود لحد ما يرفض أو يقبل».
         *
         * The thirty-second TTL was doing two jobs and the second one was the reason
         * it existed: it ended a round so the *next*, wider one could start. Nothing
         * about that needed the offer to disappear from the captain's screen. So the
         * clock moves off the offer and onto the order — `dispatch_last_offer_at`
         * below — and `cr7:tick` widens the search a minute later while every offer
         * already sent stays answerable. A captain who picks the phone up ten minutes
         * on still has a live «قبول» button, and the order was never idle meanwhile.
         */
        foreach ($candidates as $candidate) {
            $order->driverAttempts()->create([
                'driver_id' => $candidate['driver']->id,
                'round' => $round,
                'distance_km' => $candidate['distance_km'] === null ? null : round($candidate['distance_km'], 3),
                'result' => 'sent',
                'sent_at' => now(),
                'expires_at' => null,
            ]);

            // Wakes the captain's app instead of it asking every three seconds. A
            // broadcast failure is not a dispatch failure: the row above is the offer,
            // this is only the notification, and the app's own poll still finds it.
            try {
                DriverOfferSent::dispatch(
                    (int) $candidate['driver']->id,
                    (int) $order->id,
                    $round,
                    null,
                );
            } catch (\Throwable $e) {
                Log::warning('offer broadcast failed', [
                    'order_id' => $order->id,
                    'driver_id' => $candidate['driver']->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->ring($order, $candidate);
        }

        $order->update([
            'status' => OrderStatus::SearchingDriver,
            'dispatch_round' => $round,
            'dispatch_started_at' => $order->dispatch_started_at ?? now(),
            'dispatch_last_offer_at' => now(),
        ]);
        $this->syncGroupRollup($order);
    }

    /**
     * doc 11 §2 `offer.new` — the one push in this application that has to break
     * through a silent phone.
     *
     * The broadcast above wakes a captain whose app is open and in front of them.
     * This is for the far more common case: the phone is in a pocket, the screen
     * is off, and the offer expires in `$ttlSeconds` whether or not anybody
     * looked. Sent on the `offers` channel, which is what makes it loud —
     * a high-priority Android channel with its own sound, and an APNs
     * time-sensitive interruption level that plays through a Focus mode.
     *
     * Wrapped and swallowed for the same reason the broadcast is: the
     * `driver_attempts` row written above **is** the offer. If FCM is down the
     * captain still finds it on their next poll, and the dispatch round still
     * advances on time.
     *
     * @param  array{driver: Driver, distance_km: float|null}  $candidate
     */
    private function ring(Order $order, array $candidate): void
    {
        try {
            app(NotificationDispatcher::class)->send(
                $candidate['driver']->user,
                'offer.new',
                [
                    'restaurant' => $order->restaurant?->name_ar,
                    'distance' => $candidate['distance_km'] === null
                        ? '—'
                        : number_format($candidate['distance_km'], 1),
                    'earning' => number_format(
                        DriverEarningCalculator::calculate($order, $candidate['driver']),
                        2,
                    ).' ج.م',
                ],
                'cr7://offer/'.$order->id,
                $order->id,
                ['order_id' => (string) $order->id, 'kind' => 'offer'],
                channel: 'offers',
            );
        } catch (\Throwable $e) {
            Log::warning('offer push failed', [
                'order_id' => $order->id,
                'driver_id' => $candidate['driver']->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncGroupRollup(Order $order): void
    {
        $group = $order->orderGroup;
        $group->update(['rollup_status' => RollupStatus::compute($group->orders()->get()->pluck('status'))]);
    }

    /** doc 08 §7 — GET /admin/orders/{id}/eligible-drivers: same ranking, read-only, no round/TTL side effects. */
    /**
     * @param  bool  $includeOffline  Admin-only escape hatch for `ADM-07`.
     *
     * Automatic dispatch only ever wants live captains, so `false` is the default and
     * nothing about the nearest-3 algorithm changes. But an operator looking at sixteen
     * unclaimed orders at a moment when every captain is offline was shown an empty list
     * and had no way to hand the work to anyone — while `ReassignDriverAction` would
     * have accepted the assignment perfectly well, since it only requires the captain to
     * be active and free. The filter was in the suggestion, not in the rule.
     *
     * Offline captains also have no distance to sort by if they never reported a
     * position, so they come last, and the caller shows each one's state on the row.
     */
    public function eligibleDrivers(Order $order, float $radiusKm, bool $includeOffline = false): Collection
    {
        $staleSeconds = self::staleSeconds(
            Setting::query()
                ->whereIn('key', ['dispatch.location_stale_seconds', 'driver.location_stale_seconds'])
                ->pluck('value', 'key')
        );

        return Driver::query()
            ->where('is_active', true)
            ->when(! $includeOffline, fn ($q) => $q->whereNotNull('location_updated_at'))
            ->with('user:id,name')
            ->get()
            ->map(function (Driver $driver) use ($order, $staleSeconds) {
                // `location_updated_at` is null for a captain who has never sent a ping,
                // and `include_offline` exists precisely to list those rows — so calling
                // `diffInSeconds()` on the null answered the whole request with a 500.
                // `ADM-07` renders a failed query and an empty result the same way, so
                // turning the switch on read as «لا يوجد كباتن نشطون متاحون الآن».
                $isLive = $driver->is_online
                    && $driver->location_updated_at !== null
                    && $driver->location_updated_at->diffInSeconds(now()) < $staleSeconds;

                return [
                    'driver' => $driver,
                    'distance_km' => $driver->current_lat === null || $driver->current_lng === null
                        ? null
                        : Geo::haversineKm((float) $driver->current_lat, (float) $driver->current_lng, (float) $order->restaurant->latitude, (float) $order->restaurant->longitude),
                    'state' => $isLive ? 'live' : ($driver->is_online ? 'stale' : 'offline'),
                ];
            })
            // `active_order_id` and nothing else, because that is the only rule
            // `ReassignDriverAction` itself enforces. `is_available` is not checked
            // there, and a list stricter than the action hides captains the operator
            // could have picked — which is the defect this whole method just had.
            ->filter(fn ($c) => $includeOffline
                ? $c['driver']->active_order_id === null
                // The radius ranks; it must not hide. A **live** captain is assignable at
                // any distance — `ReassignDriverAction` accepts them — so filtering on it
                // removed the one captain actually on the road, twenty kilometres out,
                // and kept four who had not pinged in a week but sat inside the circle.
                // The operator was told nobody was online while `ADM-19` showed one.
                : $c['state'] === 'live'
                    || ($c['state'] === 'stale' && ($c['distance_km'] === null || $c['distance_km'] <= $radiusKm)))
            // Live first, then stale, then offline; nearest first inside each group. An
            // unknown position sorts last rather than as "zero km away".
            ->sortBy(fn ($c) => [
                match ($c['state']) {
                    'live' => 0, 'stale' => 1, default => 2
                },
                $c['distance_km'] ?? PHP_INT_MAX,
            ])
            ->values();
    }

    /** doc 05 §3 round-outcome table — call when a round's offers are all answered or expired. */
    public function advanceIfRoundExhausted(Order $order): void
    {
        if ($order->status !== OrderStatus::SearchingDriver) {
            return;
        }

        // The expiry sweep used to run *after* the early return, so a round whose three
        // offers had all timed out counted three open attempts and advanced to nothing.
        // Only an explicit «رفض» from every captain could move a round on. Marking the
        // lapsed rows first is the whole fix.
        // Only rows written before the 2026-09-08 ruling carry a deadline. A null
        // `expires_at` is an offer that is still in a captain's hand, and it must not
        // be swept away by a clock that no longer applies to it.
        $order->driverAttempts()
            ->where('round', $order->dispatch_round)
            ->where('result', 'sent')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['result' => 'expired', 'responded_at' => now()]);

        $openAttempts = $order->driverAttempts()
            ->where('round', $order->dispatch_round)
            ->where('result', 'sent')
            ->count();

        if ($openAttempts > 0) {
            return;
        }

        $this->dispatchRound($order, $order->dispatch_round + 1, retry: true);
    }

    /**
     * Every two minutes, ask **everybody** again.
     *
     * Operator, 2026-09-11: «وخلال دقيقتين لو محدش قبل يبعت للناس كلها تاني، المتصلة
     * والمتفرغة، لحد ما حد يقبل». That is a different rule from {@see self::widenSearch()},
     * which only ever asked captains who had *not* been asked — so a town where every
     * captain had seen the offer once and put the phone down had nothing left to do, and
     * the order sat in `searching_driver` with three silent offers until an operator
     * noticed.
     *
     * Every online, free captain is a candidate on every pass:
     *
     * - one already **holding** an open offer is rung again, not given a second row —
     *   two live offers for one order is two «قبول» buttons and a race the database
     *   would refuse anyway;
     * - one who **declined** earlier is asked again. That is the ruling: «الناس كلها».
     *   A refusal at 19:03 was about 19:03 — they were eating, or two streets from a
     *   drop-off — and the alternative is an order nobody is allowed to take.
     *
     * Distance still ranks the ring order (nearest hears first) and never hides anyone.
     *
     * @return int how many captains were rung — new offers and re-rings together
     */
    public function rebroadcast(Order $order): int
    {
        if (! in_array($order->status, [OrderStatus::SearchingDriver, OrderStatus::WaitingForDriver], true)) {
            return 0;
        }

        if ($order->driver_id !== null) {
            return 0;
        }

        $candidates = $this->availableCandidates($order);

        if ($candidates->isEmpty()) {
            // Nobody is on shift. Keep the clock honest so the next pass tries again,
            // and let the order say so on the board and in the alert dock.
            $stillOpen = $order->driverAttempts()->where('result', 'sent')->exists();

            $order->update([
                'status' => $stillOpen ? OrderStatus::SearchingDriver : OrderStatus::WaitingForDriver,
                'dispatch_started_at' => $order->dispatch_started_at ?? now(),
                'dispatch_last_offer_at' => now(),
            ]);
            $this->syncGroupRollup($order);

            return 0;
        }

        $openHolders = $order->driverAttempts()
            ->where('result', 'sent')
            ->pluck('driver_id')
            ->all();

        $round = (int) $order->dispatch_round + 1;
        $rung = 0;

        foreach ($candidates as $candidate) {
            $driverId = (int) $candidate['driver']->id;

            if (! in_array($driverId, $openHolders, true)) {
                $order->driverAttempts()->create([
                    'driver_id' => $driverId,
                    'round' => $round,
                    'distance_km' => $candidate['distance_km'] === null ? null : round($candidate['distance_km'], 3),
                    'result' => 'sent',
                    'sent_at' => now(),
                    'expires_at' => null,
                ]);
            }

            $this->wake($order, $candidate, $round);
            $rung++;
        }

        $order->update([
            'status' => OrderStatus::SearchingDriver,
            'dispatch_round' => $round,
            'dispatch_started_at' => $order->dispatch_started_at ?? now(),
            'dispatch_last_offer_at' => now(),
        ]);
        $this->syncGroupRollup($order);

        return $rung;
    }

    /**
     * «متصل وفاضي» — the whole condition, ranked by distance and never filtered by it.
     *
     * @return Collection<int, array{driver: Driver, distance_km: float|null}>
     */
    private function availableCandidates(Order $order): Collection
    {
        return Driver::query()
            ->where('is_active', true)
            ->where('is_online', true)
            ->where('is_available', true)
            ->whereNull('active_order_id')
            // A captain with no zone rows is unrestricted, not «matches no zone».
            ->where(fn ($q) => $q
                ->whereDoesntHave('assignedZones')
                ->orWhereHas('assignedZones', fn ($zq) => $zq->where('service_zones.id', $order->service_zone_id)))
            ->get()
            ->map(fn (Driver $driver) => [
                'driver' => $driver,
                'distance_km' => $driver->hasFix()
                    ? Geo::haversineKm((float) $driver->current_lat, (float) $driver->current_lng, (float) $order->restaurant->latitude, (float) $order->restaurant->longitude)
                    : null,
            ])
            ->sortBy([
                fn ($a, $b) => ($a['distance_km'] ?? INF) <=> ($b['distance_km'] ?? INF),
                fn ($a, $b) => $b['driver']->rating_avg <=> $a['driver']->rating_avg,
                fn ($a, $b) => $b['driver']->completed_orders <=> $a['driver']->completed_orders,
            ])
            ->values();
    }

    /**
     * Wake one captain: the socket for a phone that is awake, the push for one that is not.
     *
     * @param  array{driver: Driver, distance_km: float|null}  $candidate
     */
    private function wake(Order $order, array $candidate, int $round): void
    {
        try {
            DriverOfferSent::dispatch((int) $candidate['driver']->id, (int) $order->id, $round, null);
        } catch (\Throwable $e) {
            Log::warning('offer broadcast failed', [
                'order_id' => $order->id,
                'driver_id' => $candidate['driver']->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->ring($order, $candidate);
    }

    /**
     * The minute hand. «كل دقيقة تتشيك الأوردر، لحد قبل ولا لأ… تقوم بعت تاني، وهكذا.»
     *
     * Called by `cr7:tick` for every order still looking for a captain — one whose
     * offers are all still open included. That last part is the whole point: before
     * today an order in `searching_driver` with three unanswered offers was *waiting*,
     * and nothing widened it until those three offers died. Now nothing dies, so the
     * widening has to be the thing on the clock.
     *
     * It is **additive**. Every offer already sent stays exactly as it was; this only
     * asks captains who have not been asked yet. A round that finds nobody new is not
     * a failure and costs one query — the fourth captain may come on shift at minute
     * nine, and this is what reaches them.
     *
     * @return bool whether anyone new was offered the order
     */
    public function widenSearch(Order $order): bool
    {
        if (! in_array($order->status, [OrderStatus::SearchingDriver, OrderStatus::WaitingForDriver], true)) {
            return false;
        }

        $before = $order->driverAttempts()->count();

        $this->dispatchRound($order, $order->dispatch_round + 1, retry: true);

        return $order->driverAttempts()->count() > $before;
    }

    /**
     * D-56 — a captain counts as live while the last ping is younger than this.
     *
     * `SettingsSeeder` writes the number twice: `dispatch.location_stale_seconds` (90,
     * the decision, and what both dashboards and `Driver::isLive()` use) and
     * `driver.location_stale_seconds` (60). Dispatch read the 60 and nothing read the
     * 90, so a captain quiet for 70 seconds was «متصل ومتفرغ» on `ADM-19`, «متصل» on the
     * fleet map, and invisible to dispatch — one captain, three answers. The 90 wins;
     * the older key stays readable so an operator who edited it is not overruled.
     *
     * @param  Collection<string, mixed>  $settings
     */
    private static function staleSeconds(Collection $settings): int
    {
        return (int) ($settings['dispatch.location_stale_seconds']
            ?? $settings['driver.location_stale_seconds']
            ?? 90);
    }
}
