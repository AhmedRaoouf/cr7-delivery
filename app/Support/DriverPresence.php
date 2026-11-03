<?php

namespace App\Support;

use App\Models\Driver;
use App\Models\DriverPresenceSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * doc 99 §2 — D-170. Who is on shift and **heard from**, recorded as sessions.
 *
 * Owners: «أول تسجيل أون لاين للطيار يحسب كتسجيل حضور مع ربطه بلوكيشن التغطية … آخر مرة
 * يكون أون لاين كانصراف مع بيان حالات عدم الاتصال على مدار اليوم».
 *
 * `drivers.is_online` is the captain's own switch and nothing here ever writes it. A
 * session is a separate fact: «online, and the server is hearing from the phone». It
 * opens when the captain goes online — or on any captain request while `is_online` with
 * no session open, which covers the captains already online the day this shipped — and
 * every request after that is a **touch** that moves `last_seen_at`. The 1.0.23 app
 * polls `GET /driver/offers` every few seconds while online, so the poll itself is the
 * heartbeat and no app update is needed.
 *
 * A touch after more than `attendance.gap_seconds` of silence closes the old session at
 * its `last_seen_at` (`signal_lost`) and opens a new one: the silence is the gap the
 * owners asked to see. `cr7:tick` closes silent sessions the same way, and never sets
 * `is_online` — a phone that went quiet is not a captain who chose to stop.
 */
final class DriverPresence
{
    /** «متصل» — an open session heard from within this many seconds. */
    public const CONNECTED_SECONDS = 120;

    /** The middleware writes at most once per captain per this many seconds. */
    public const TOUCH_THROTTLE_SECONDS = 30;

    /** The end reasons that are a captain's own decision to stop — a real check-out. */
    public const CHECKOUT_REASONS = [DriverPresenceSession::WENT_OFFLINE, DriverPresenceSession::LOGGED_OUT];

    public static function gapSeconds(): int
    {
        return max(30, SettingsCatalog::int('attendance.gap_seconds'));
    }

    /**
     * The heartbeat every authenticated captain request makes (middleware): throttled to
     * one write per captain per {@see TOUCH_THROTTLE_SECONDS}, and never an error.
     */
    public static function heartbeat(int $userId, callable $driver): void
    {
        if (! Cache::add('presence-touch:'.$userId, true, self::TOUCH_THROTTLE_SECONDS)) {
            return;
        }

        try {
            $captain = $driver();

            if ($captain instanceof Driver) {
                self::touch($captain);
            }
        } catch (\Throwable $e) {
            Log::warning('presence heartbeat failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Heard from this captain now. Opens a session when none is open, splits one that has
     * been silent past the gap, and records the session's first fix when it has none yet.
     * Does nothing for a captain who is not online — a ping from a phone whose switch is
     * off is not a shift.
     */
    public static function touch(Driver $driver, ?float $lat = null, ?float $lng = null): ?DriverPresenceSession
    {
        if (! $driver->is_online || ! $driver->is_active) {
            return null;
        }

        $now = now();
        $open = self::openSession($driver->id);

        if ($open !== null && self::isSilent($open, $now)) {
            self::closeAt($open, $open->last_seen_at, DriverPresenceSession::SIGNAL_LOST);
            $open = null;
        }

        if ($open === null) {
            return self::start($driver, $now, $lat, $lng);
        }

        $updates = ['last_seen_at' => $now];

        if ($open->start_lat === null && $lat !== null && $lng !== null) {
            $updates += self::fixColumns($driver, $lat, $lng);
        }

        DriverPresenceSession::query()->whereKey($open->id)->whereNull('ended_at')->update($updates + ['updated_at' => $now]);

        return $open->forceFill($updates);
    }

    /**
     * The shift ends by somebody's decision: the captain's switch (`went_offline`), signing
     * out (`logged_out`), the office (`admin`) or a block (`blocked`).
     *
     * A session already silent past the gap ended when the phone went quiet, not now — it
     * is closed at its `last_seen_at` as `signal_lost`. When the decision is the captain's
     * own, a zero-length marker is written at this moment so the day still shows a real
     * check-out.
     *
     * @param  bool  $wasOnline  whether the captain's switch was on before this decision
     */
    public static function end(Driver|int $driver, string $reason, bool $wasOnline = true): void
    {
        $driverId = $driver instanceof Driver ? (int) $driver->id : $driver;
        $now = now();
        $open = self::openSession($driverId);
        $marker = $wasOnline && in_array($reason, self::CHECKOUT_REASONS, true);

        if ($open !== null && ! self::isSilent($open, $now)) {
            self::closeAt($open, $now, $reason, lastSeen: $now);

            return;
        }

        if ($open !== null) {
            self::closeAt($open, $open->last_seen_at, DriverPresenceSession::SIGNAL_LOST);
        }

        if ($marker) {
            DriverPresenceSession::query()->create([
                'driver_id' => $driverId,
                'started_at' => $now,
                'last_seen_at' => $now,
                'ended_at' => $now,
                'end_reason' => $reason,
            ]);
        }
    }

    /**
     * `cr7:tick` — every open session silent past the gap ends at its `last_seen_at`.
     * `is_online` is left exactly as it is.
     */
    public static function closeStale(): int
    {
        return DriverPresenceSession::query()
            ->whereNull('ended_at')
            ->where('last_seen_at', '<', now()->subSeconds(self::gapSeconds()))
            ->update([
                'ended_at' => DB::raw('last_seen_at'),
                'end_reason' => DriverPresenceSession::SIGNAL_LOST,
                'updated_at' => now(),
            ]);
    }

    /**
     * Narrow a driver query to captains whose phone the server heard from within
     * {@see CONNECTED_SECONDS}.
     *
     * @template T of Builder
     *
     * @param  T  $drivers
     * @return T
     */
    public static function whereConnected(Builder $drivers): Builder
    {
        return $drivers->whereHas('presenceSessions', fn (Builder $q) => $q
            ->whereNull('ended_at')
            ->where('last_seen_at', '>=', now()->subSeconds(self::CONNECTED_SECONDS)));
    }

    public static function isConnected(Driver|int $driver): bool
    {
        $id = $driver instanceof Driver ? (int) $driver->id : $driver;

        return DriverPresenceSession::query()
            ->where('driver_id', $id)
            ->whereNull('ended_at')
            ->where('last_seen_at', '>=', now()->subSeconds(self::CONNECTED_SECONDS))
            ->exists();
    }

    public static function openSession(int $driverId): ?DriverPresenceSession
    {
        return DriverPresenceSession::query()->where('driver_id', $driverId)->whereNull('ended_at')->latest('id')->first();
    }

    /**
     * «ربطه بلوكيشن التغطية» — is this fix inside one of the captain's own areas, or, for
     * a captain with no area (or none with a shape to test against), inside any area or
     * any served city's circle.
     *
     * @return array{start_in_coverage: bool, start_zone_id: int|null}
     */
    public static function coverage(Driver $driver, float $lat, float $lng): array
    {
        $own = $driver->assignedZones()->where('service_zones.is_active', true)->get()
            ->filter(fn ($zone) => $zone->polygon || $zone->radius_km);

        if ($own->isNotEmpty()) {
            $hit = $own->first(fn ($zone) => ZoneLocator::contains($zone, $lat, $lng));

            return ['start_in_coverage' => $hit !== null, 'start_zone_id' => $hit?->id];
        }

        $zone = ZoneLocator::forPoint($lat, $lng);

        if ($zone !== null) {
            return ['start_in_coverage' => true, 'start_zone_id' => (int) $zone->id];
        }

        return ['start_in_coverage' => ZoneLocator::cityForPoint($lat, $lng) !== null, 'start_zone_id' => null];
    }

    // ------------------------------------------------------------------ internals

    private static function isSilent(DriverPresenceSession $session, Carbon $now): bool
    {
        return $session->last_seen_at->lt($now->copy()->subSeconds(self::gapSeconds()));
    }

    private static function start(Driver $driver, Carbon $now, ?float $lat, ?float $lng): ?DriverPresenceSession
    {
        // The session's first fix: this request's, or the one the captain sent moments ago.
        // A fix from yesterday is not where this shift started.
        if (($lat === null || $lng === null)
            && $driver->hasFix()
            && $driver->location_updated_at !== null
            && $driver->location_updated_at->gte($now->copy()->subSeconds(self::gapSeconds()))) {
            $lat = (float) $driver->current_lat;
            $lng = (float) $driver->current_lng;
        }

        try {
            return DriverPresenceSession::query()->create([
                'driver_id' => $driver->id,
                'started_at' => $now,
                'last_seen_at' => $now,
                ...($lat !== null && $lng !== null ? self::fixColumns($driver, $lat, $lng) : []),
            ]);
        } catch (QueryException $e) {
            // Two requests of the same captain raced to open it; the unique index let one
            // win. Theirs is the session.
            if (! str_contains($e->getMessage(), 'driver_presence_one_open_per_captain') && (int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }

            return self::openSession((int) $driver->id);
        }
    }

    /** @return array<string, mixed> */
    private static function fixColumns(Driver $driver, float $lat, float $lng): array
    {
        return ['start_lat' => $lat, 'start_lng' => $lng, ...self::coverage($driver, $lat, $lng)];
    }

    private static function closeAt(DriverPresenceSession $session, Carbon $at, string $reason, ?Carbon $lastSeen = null): void
    {
        DriverPresenceSession::query()
            ->whereKey($session->id)
            ->whereNull('ended_at')
            ->update([
                'ended_at' => $at,
                'end_reason' => $reason,
                ...($lastSeen !== null ? ['last_seen_at' => $lastSeen] : []),
                'updated_at' => now(),
            ]);
    }
}
