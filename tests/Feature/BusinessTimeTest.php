<?php

namespace Tests\Feature;

use App\Support\BusinessTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * «ظبط الوقت في كل حاجة — خلي UTC ده مضبوط على أساس القاهرة».
 *
 * Rows stay UTC: Egypt observes DST again since 2023, so a local-time column has
 * a duplicated hour every October and nothing on the row to tell the two apart.
 * What moves to Cairo is every boundary a person means when they say «اليوم» —
 * and `Carbon::today()` in a UTC process answers 22:00 the previous evening
 * there, so `ADM-04` counted from two hours after Cairo midnight and every order
 * taken between 00:00 and 02:00 was filed under yesterday.
 */
class BusinessTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_starts_at_midnight_in_cairo_not_in_greenwich(): void
    {
        // 00:30 in Cairo on 5 September — 21:30 UTC on the 4th.
        Carbon::setTestNow(Carbon::parse('2026-09-04 21:30:00', 'UTC'));

        $start = BusinessTime::startOfToday();

        $this->assertSame('2026-09-04 21:00:00', $start->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(
            '2026-09-05 00:00:00',
            $start->copy()->setTimezone(BusinessTime::timezone())->format('Y-m-d H:i:s'),
        );

        Carbon::setTestNow();
    }

    public function test_an_order_half_an_hour_after_cairo_midnight_counts_as_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-04 22:00:00', 'UTC'));

        // The order was placed at 00:30 Cairo, which is 21:30 UTC.
        $placedAt = Carbon::parse('2026-09-04 21:30:00', 'UTC');

        $this->assertTrue(
            $placedAt->greaterThanOrEqualTo(BusinessTime::startOfToday()),
            'an order taken half an hour into the day was filed under yesterday',
        );

        // And the old rule really did get it wrong, which is why this matters:
        // at 00:30 in Gamasa the UTC clock still reads yesterday, so «اليوم» on
        // `ADM-04` opened a window covering the whole of the previous day.
        $this->assertSame('2026-09-05', BusinessTime::now()->format('Y-m-d'));
        $this->assertSame('2026-09-04', Carbon::today()->format('Y-m-d'));
        $this->assertTrue(
            Carbon::today()->lessThan(BusinessTime::startOfToday()),
            'the UTC «today» opened earlier than the day the operator meant',
        );

        Carbon::setTestNow();
    }

    public function test_a_typed_date_is_read_as_a_cairo_date(): void
    {
        $from = BusinessTime::fromInput(Carbon::parse('2026-09-05', 'UTC'));
        $to = BusinessTime::fromInput(Carbon::parse('2026-09-05', 'UTC'), endOfDay: true);

        $this->assertSame('2026-09-04 21:00:00', $from->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-05 20:59:59', $to->utc()->format('Y-m-d H:i:s'));
    }

    public function test_a_null_range_end_stays_null(): void
    {
        $this->assertNull(BusinessTime::fromInput(null));
    }

    public function test_the_chart_expression_shifts_by_the_live_offset(): void
    {
        // Not a hard-coded «+2»: Egypt's offset is 3 in summer and 2 in winter,
        // and a literal would be wrong for half the year.
        $expected = (int) BusinessTime::now()->utcOffset();

        $this->assertSame(
            "DATE_ADD(created_at, INTERVAL {$expected} MINUTE)",
            BusinessTime::sqlLocal('created_at'),
        );
        $this->assertContains($expected, [120, 180]);
    }
}
