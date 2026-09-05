<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Events\DriverOfferSent;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Setting;
use App\Support\Notifications\NotificationDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * doc 05 §1-3 — dispatch starts the instant an order becomes `ready`, offering to the
 * nearest 3 online drivers. This is the synchronous, single-request-cycle version:
 * a real deployment schedules ExpireRound/ExpirePrepTimer as queued jobs (doc 04 §4.4,
 * doc 05 §3) — not wired up in this pass, see the round-advance note below.
 */
class DispatchService
{
    public function dispatchRound(Order $order, int $round): void
    {
        $settings = Setting::query()->whereIn('key', [
            'dispatch.batch_size', 'dispatch.radius_km', 'dispatch.radius_expand_km',
            'dispatch.offer_ttl_seconds', 'dispatch.max_rounds',
            'dispatch.location_stale_seconds', 'driver.location_stale_seconds',
        ])->pluck('value', 'key');

        $batchSize = (int) ($settings['dispatch.batch_size'] ?? 3);
        $baseRadius = (float) ($settings['dispatch.radius_km'] ?? 5);
        $radiusExpand = (float) ($settings['dispatch.radius_expand_km'] ?? 2);
        $ttlSeconds = (int) ($settings['dispatch.offer_ttl_seconds'] ?? 30);
        $maxRounds = (int) ($settings['dispatch.max_rounds'] ?? 3);
        $staleSeconds = self::staleSeconds($settings);

        $radius = $baseRadius + ($round - 1) * $radiusExpand;

        $excludedDriverIds = $order->driverAttempts()->pluck('driver_id');

        $candidates = Driver::query()
            ->where('is_active', true)
            ->where('is_online', true)
            ->where('is_available', true)
            ->whereNull('active_order_id')
            ->whereNotNull('location_updated_at')
            ->where('location_updated_at', '>=', now()->subSeconds($staleSeconds))
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
                'distance_km' => Geo::haversineKm((float) $driver->current_lat, (float) $driver->current_lng, (float) $order->restaurant->latitude, (float) $order->restaurant->longitude),
            ])
            ->filter(fn ($c) => $c['distance_km'] <= $radius)
            ->sortBy([
                fn ($a, $b) => $a['distance_km'] <=> $b['distance_km'],
                fn ($a, $b) => $b['driver']->rating_avg <=> $a['driver']->rating_avg,
                fn ($a, $b) => $b['driver']->completed_orders <=> $a['driver']->completed_orders,
            ])
            ->take($batchSize);

        if ($candidates->isEmpty()) {
            if ($round < $maxRounds) {
                $this->dispatchRound($order, $round + 1);

                return;
            }

            $order->update(['status' => OrderStatus::WaitingForDriver, 'dispatch_round' => $round]);
            $this->syncGroupRollup($order);

            return;
        }

        $expiresAt = Carbon::now()->addSeconds($ttlSeconds);

        foreach ($candidates as $candidate) {
            $order->driverAttempts()->create([
                'driver_id' => $candidate['driver']->id,
                'round' => $round,
                'distance_km' => round($candidate['distance_km'], 3),
                'result' => 'sent',
                'sent_at' => now(),
                'expires_at' => $expiresAt,
            ]);

            // Wakes the captain's app instead of it asking every three seconds. A
            // broadcast failure is not a dispatch failure: the row above is the offer,
            // this is only the notification, and the app's own poll still finds it.
            try {
                DriverOfferSent::dispatch(
                    (int) $candidate['driver']->id,
                    (int) $order->id,
                    $round,
                    $expiresAt->toIso8601String(),
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

        $order->update(['status' => OrderStatus::SearchingDriver, 'dispatch_round' => $round, 'dispatch_started_at' => $order->dispatch_started_at ?? now()]);
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
     * @param  array{driver: Driver, distance_km: float}  $candidate
     */
    private function ring(Order $order, array $candidate): void
    {
        try {
            app(NotificationDispatcher::class)->send(
                $candidate['driver']->user,
                'offer.new',
                [
                    'restaurant' => $order->restaurant?->name_ar,
                    'distance' => number_format($candidate['distance_km'], 1),
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

        $openAttempts = $order->driverAttempts()->where('round', $order->dispatch_round)->where('result', 'sent')->count();

        if ($openAttempts > 0) {
            return;
        }

        $order->driverAttempts()
            ->where('round', $order->dispatch_round)
            ->where('result', 'sent')
            ->where('expires_at', '<=', now())
            ->update(['result' => 'expired']);

        $this->dispatchRound($order, $order->dispatch_round + 1);
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
