<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
            'dispatch.offer_ttl_seconds', 'dispatch.max_rounds', 'driver.location_stale_seconds',
        ])->pluck('value', 'key');

        $batchSize = (int) ($settings['dispatch.batch_size'] ?? 3);
        $baseRadius = (float) ($settings['dispatch.radius_km'] ?? 5);
        $radiusExpand = (float) ($settings['dispatch.radius_expand_km'] ?? 2);
        $ttlSeconds = (int) ($settings['dispatch.offer_ttl_seconds'] ?? 30);
        $maxRounds = (int) ($settings['dispatch.max_rounds'] ?? 3);
        $staleSeconds = (int) ($settings['driver.location_stale_seconds'] ?? 60);

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
            ->whereHas('assignedZones', fn ($q) => $q->where('service_zones.id', $order->service_zone_id))
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
        }

        $order->update(['status' => OrderStatus::SearchingDriver, 'dispatch_round' => $round, 'dispatch_started_at' => $order->dispatch_started_at ?? now()]);
        $this->syncGroupRollup($order);
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
        $staleSeconds = (int) (Setting::query()->where('key', 'driver.location_stale_seconds')->value('value') ?? 60);

        return Driver::query()
            ->where('is_active', true)
            ->when(! $includeOffline, fn ($q) => $q->whereNotNull('location_updated_at'))
            ->get()
            ->map(function (Driver $driver) use ($order, $staleSeconds) {
                $isLive = $driver->is_online
                    && $driver->location_updated_at->diffInSeconds(now()) < $staleSeconds;

                return [
                    'driver' => $driver,
                    'distance_km' => $driver->current_lat === null || $driver->current_lng === null
                        ? null
                        : Geo::haversineKm((float) $driver->current_lat, (float) $driver->current_lng, (float) $order->restaurant->latitude, (float) $order->restaurant->longitude),
                    'state' => $isLive ? 'live' : ($driver->is_online ? 'stale' : 'offline'),
                ];
            })
            ->filter(fn ($c) => $includeOffline
                ? $c['driver']->active_order_id === null
                : $c['distance_km'] <= $radiusKm && $c['state'] !== 'offline')
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
}
