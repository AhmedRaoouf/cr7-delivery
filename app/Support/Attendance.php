<?php

namespace App\Support;

use App\Models\Driver;
use App\Models\DriverPresenceSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * doc 99 §2 — D-170. A captain's day, read from the presence sessions.
 *
 * The day is a Cairo calendar day ({@see BusinessTime}); a session that crosses midnight
 * is clipped at read time, so each day counts only its own seconds and a session is never
 * counted twice. Check-in is the first session of the day. **Check-out is never guessed
 * mid-day**: `checked_out_at` exists only when the day's last session ended by the
 * captain's own decision (`went_offline` / `logged_out`); otherwise the row shows the
 * last time the phone was heard from, and once the day is over `checkout_kind` says
 * that time is an estimate. A gap's reason is the previous session's end: «أوقف الكابتن
 * استقبال الطلبات» or «انقطع الاتصال — السبب غير معروف». No gap is a violation.
 */
final class Attendance
{
    /** @return array{0: Carbon, 1: Carbon} the day's bounds in UTC, `[start, end)` */
    public static function bounds(string $date): array
    {
        $tz = BusinessTime::timezone();
        $start = Carbon::createFromFormat('Y-m-d', $date, $tz)->startOfDay();
        // The next local midnight, not «+24 h»: a day with a clock change is 23 or 25 hours.
        $end = $start->copy()->addDay()->startOfDay();

        return [$start->utc(), $end->utc()];
    }

    /**
     * Every session that touches the day, for these captains, grouped by captain.
     *
     * @param  list<int>  $driverIds
     * @return Collection<int, Collection<int, DriverPresenceSession>>
     */
    public static function sessionsFor(array $driverIds, string $date): Collection
    {
        [$start, $end] = self::bounds($date);

        if ($driverIds === []) {
            return collect();
        }

        return DriverPresenceSession::query()
            ->whereIn('driver_id', $driverIds)
            ->where('started_at', '<', $end)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $start))
            ->orderBy('started_at')
            ->orderBy('id')
            ->get()
            ->groupBy('driver_id');
    }

    /**
     * One captain's day.
     *
     * @param  Collection<int, DriverPresenceSession>|null  $sessions  already loaded, if the caller has them
     * @return array<string, mixed>
     */
    public static function day(Driver $driver, string $date, ?Collection $sessions = null): array
    {
        [$start, $end] = self::bounds($date);
        $sessions ??= self::sessionsFor([(int) $driver->id], $date)->get((int) $driver->id, collect());
        $now = now();
        $gap = DriverPresence::gapSeconds();

        $segments = $sessions
            ->sortBy([['started_at', 'asc'], ['id', 'asc']])
            ->map(function (DriverPresenceSession $s) use ($start, $end, $now, $gap) {
                $open = $s->ended_at === null;
                $effectiveEnd = $open
                    ? ($s->last_seen_at->gte($now->copy()->subSeconds($gap)) ? $now->copy() : $s->last_seen_at->copy())
                    : $s->ended_at->copy();

                $from = $s->started_at->copy()->max($start);
                $to = $effectiveEnd->min($end)->min($now);

                return [
                    'session' => $s,
                    'from' => $from,
                    'to' => $to->lt($from) ? $from->copy() : $to,
                    // A zero-length row written at a check-out after a silence carries
                    // the check-out, not time on shift.
                    'real' => $open || $s->ended_at->gt($s->started_at)
                        || ! in_array($s->end_reason, DriverPresence::CHECKOUT_REASONS, true),
                    'last_seen' => $s->last_seen_at->copy()->min($end),
                    // An open session whose phone went quiet before this day began (the
                    // sweep has not closed it yet) has nothing in this day.
                    'touches_day' => $s->started_at->lt($end) && $effectiveEnd->gte($start),
                ];
            })
            ->filter(fn (array $seg) => $seg['touches_day'])
            ->values();

        $real = $segments->filter(fn (array $seg) => $seg['real'])->values();
        $isToday = $start->lte($now) && $now->lt($end);

        if ($real->isEmpty()) {
            return self::row($driver, $date, [
                'state' => 'absent',
                'checked_in_at' => null,
                'checked_in_in_coverage' => null,
                'last_seen_at' => null,
                'checked_out_at' => null,
                'checkout_kind' => null,
                'online_seconds' => 0,
                'gap_seconds' => 0,
                'gaps_count' => 0,
                'sessions' => [],
                'timeline' => [],
            ]);
        }

        $first = $real->first();
        $last = $segments->last();

        $checkedOut = null;
        if ($last['session']->ended_at !== null
            && in_array($last['session']->end_reason, DriverPresence::CHECKOUT_REASONS, true)
            && $last['session']->ended_at->gte($start)
            && $last['session']->ended_at->lt($end)) {
            $checkedOut = $last['session']->ended_at->copy();
        }

        $timeline = [];
        $gaps = [];
        $previous = null;

        foreach ($segments as $seg) {
            if ($previous !== null && $seg['from']->gt($previous['to'])) {
                $reason = $previous['session']->end_reason ?? DriverPresenceSession::SIGNAL_LOST;
                $gapRow = [
                    'kind' => 'gap',
                    'from' => $previous['to'],
                    'to' => $seg['from'],
                    'seconds' => (int) $previous['to']->diffInSeconds($seg['from']),
                    'reason' => $reason,
                    ...self::reasonLabels($reason),
                ];
                $gaps[] = $gapRow;
                $timeline[] = $gapRow;
            }

            if ($seg['real']) {
                $timeline[] = [
                    'kind' => 'online',
                    'from' => $seg['from'],
                    'to' => $seg['to'],
                    'seconds' => (int) $seg['from']->diffInSeconds($seg['to']),
                    'reason' => null,
                    'in_coverage' => $seg['session']->start_in_coverage,
                ];
            }

            $previous = $seg;
        }

        $openNow = $sessions->first(fn (DriverPresenceSession $s) => $s->ended_at === null
            && $s->last_seen_at->gte($now->copy()->subSeconds($gap)));

        return self::row($driver, $date, [
            'state' => $isToday && $openNow !== null ? 'online' : 'offline',
            'checked_in_at' => $first['from'],
            'checked_in_in_coverage' => $first['session']->start_in_coverage,
            'last_seen_at' => $segments->max(fn (array $seg) => $seg['last_seen']),
            'checked_out_at' => $checkedOut,
            'checkout_kind' => $checkedOut !== null ? 'actual' : ($end->lte($now) ? 'estimated' : null),
            'online_seconds' => (int) $real->sum(fn (array $seg) => $seg['from']->diffInSeconds($seg['to'])),
            'gap_seconds' => (int) array_sum(array_column($gaps, 'seconds')),
            'gaps_count' => count($gaps),
            'sessions' => $real->map(fn (array $seg) => [
                'id' => $seg['session']->id,
                'started_at' => $seg['session']->started_at,
                'ended_at' => $seg['session']->ended_at,
                'last_seen_at' => $seg['session']->last_seen_at,
                'end_reason' => $seg['session']->end_reason,
                'in_coverage' => $seg['session']->start_in_coverage,
                'zone_id' => $seg['session']->start_zone_id,
                'from' => $seg['from'],
                'to' => $seg['to'],
                'seconds' => (int) $seg['from']->diffInSeconds($seg['to']),
            ])->values()->all(),
            'timeline' => $timeline,
        ]);
    }

    /** @return array{reason_ar: string, reason_en: string} */
    public static function reasonLabels(string $reason): array
    {
        return match ($reason) {
            DriverPresenceSession::WENT_OFFLINE => ['reason_ar' => 'أوقف الكابتن استقبال الطلبات', 'reason_en' => 'The captain stopped taking orders'],
            DriverPresenceSession::LOGGED_OUT => ['reason_ar' => 'سجّل الكابتن الخروج', 'reason_en' => 'The captain signed out'],
            DriverPresenceSession::ADMIN => ['reason_ar' => 'أوقفته الإدارة', 'reason_en' => 'Stopped by the office'],
            DriverPresenceSession::BLOCKED => ['reason_ar' => 'حُظر الحساب', 'reason_en' => 'The account was blocked'],
            default => ['reason_ar' => 'انقطع الاتصال — السبب غير معروف', 'reason_en' => 'Connection lost — reason unknown'],
        };
    }

    /**
     * @param  array<string, mixed>  $day
     * @return array<string, mixed>
     */
    private static function row(Driver $driver, string $date, array $day): array
    {
        return [
            'date' => $date,
            'driver' => [
                'id' => $driver->id,
                'name' => $driver->user?->name,
                'phone' => $driver->user?->phone,
                'avatar' => $driver->user?->avatar,
            ],
            'is_online' => (bool) $driver->is_online,
            'active_order_id' => $driver->active_order_id,
            ...$day,
        ];
    }
}
