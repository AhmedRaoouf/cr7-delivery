<?php

namespace App\Support;

use App\Models\Restaurant;
use Illuminate\Support\Carbon;

/**
 * doc 104 — one place's statement cycle: periods `every_days` long, back to back from
 * `starts_on` (Cairo days).
 *
 * Only a place on a commission has one. A place at 0 % owes the platform nothing, and what
 * the platform owes it for online orders is still settled by hand on `ADM-33`, as before
 * doc 103. `settlements.cycle_days` is the length a place takes when the office names none,
 * and zero there stops the automatic statement for every place.
 */
final class SettlementCycle
{
    private function __construct(
        public readonly Carbon $startsOn,
        public readonly int $everyDays,
    ) {}

    public static function of(Restaurant $restaurant): ?self
    {
        if ((float) $restaurant->commission_rate <= 0 || $restaurant->settlement_starts_on === null) {
            return null;
        }

        $default = SettingsCatalog::int('settlements.cycle_days');
        if ($default <= 0) {
            return null;
        }

        $days = (int) ($restaurant->settlement_every_days ?: $default);

        return new self(
            Carbon::parse($restaurant->settlement_starts_on->toDateString(), BusinessTime::timezone())->startOfDay(),
            max(1, $days),
        );
    }

    public static function make(string $startsOn, int $everyDays): self
    {
        return new self(Carbon::createFromFormat('Y-m-d', $startsOn, BusinessTime::timezone())->startOfDay(), max(1, $everyDays));
    }

    /**
     * The period that holds today, as two `Y-m-d` strings — the first one while the
     * contract has not started yet.
     *
     * @return array{0: string, 1: string}
     */
    public function currentPeriod(?Carbon $today = null): array
    {
        $index = max(0, intdiv(max(0, $this->elapsed($today)), $this->everyDays));

        return $this->period($index);
    }

    /**
     * The last few periods that are over, oldest first — three, so a cron that was down
     * for a whole period still writes it, without the hourly run growing with the years.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function completedPeriods(?Carbon $today = null, int $lookBack = 3): array
    {
        $completed = intdiv(max(0, $this->elapsed($today)), $this->everyDays);
        $periods = [];

        for ($index = max(0, $completed - $lookBack); $index < $completed; $index++) {
            $periods[] = $this->period($index);
        }

        return $periods;
    }

    /** @return array{0: string, 1: string}|null */
    public function lastCompletedPeriod(?Carbon $today = null): ?array
    {
        $periods = $this->completedPeriods($today, 1);

        return $periods[0] ?? null;
    }

    /** @return array{starts_on: string, every_days: int, period_start: string, period_end: string, next_statement_on: string} */
    public function toArray(?Carbon $today = null): array
    {
        [$start, $end] = $this->currentPeriod($today);

        return [
            'starts_on' => $this->startsOn->toDateString(),
            'every_days' => $this->everyDays,
            'period_start' => $start,
            'period_end' => $end,
            // The statement is written the morning after the period's last day.
            'next_statement_on' => Carbon::createFromFormat('Y-m-d', $end, BusinessTime::timezone())->addDay()->toDateString(),
        ];
    }

    private function elapsed(?Carbon $today): int
    {
        $today = ($today ?? BusinessTime::now())->copy()->setTimezone(BusinessTime::timezone())->startOfDay();

        return (int) $this->startsOn->diffInDays($today, false);
    }

    /** @return array{0: string, 1: string} */
    private function period(int $index): array
    {
        $start = $this->startsOn->copy()->addDays($index * $this->everyDays);

        return [$start->toDateString(), $start->copy()->addDays($this->everyDays - 1)->toDateString()];
    }
}
