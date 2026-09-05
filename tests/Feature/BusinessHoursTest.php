<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Support\BusinessTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `RES-19`'s switch reported open and the header kept saying «المطعم مغلق».
 *
 * `is_open_manual` was `true` in the database the whole time — the write always worked.
 * `is_open_now` is that flag **and** today's window, and the window was compared against
 * `now()`: the production server runs UTC, Egypt is three hours ahead, and a kitchen
 * open «‪10:00‬ – ‪02:00‬» therefore read as closed from 04:00 to 12:00 local, every day,
 * for every restaurant on the platform. The customer app's list and the checkout gate
 * read the same field.
 */
class BusinessHoursTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        // What production runs. The bug is invisible on a machine set to Cairo.
        config(['app.timezone' => 'UTC', 'app.business_timezone' => 'Africa/Cairo']);
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function openAllWeek(string $opens = '10:00:00', string $closes = '02:00:00'): Restaurant
    {
        $restaurant = $this->makeRestaurant('ابن البلد');

        foreach (range(0, 6) as $weekday) {
            $restaurant->openingHours()->create([
                'weekday' => $weekday,
                'opens_at' => $opens,
                'closes_at' => $closes,
                'is_closed' => false,
            ]);
        }

        return $restaurant->fresh();
    }

    public function test_a_kitchen_open_at_ten_local_is_open_at_ten_local(): void
    {
        $restaurant = $this->openAllWeek();

        // The exact moment from the report: 10:48 in Gamasa, 07:48 on the server.
        Carbon::setTestNow(Carbon::parse('2026-09-05 07:48:00', 'UTC'));

        $this->assertSame('07:48', now()->format('H:i'), 'the server clock is UTC');
        $this->assertSame('10:48', BusinessTime::now()->format('H:i'));
        $this->assertTrue($restaurant->isOpenNow());
    }

    public function test_it_is_still_closed_before_the_window_opens(): void
    {
        $restaurant = $this->openAllWeek();

        // 08:00 in Gamasa — genuinely before opening, and it must stay closed.
        Carbon::setTestNow(Carbon::parse('2026-09-05 05:00:00', 'UTC'));

        $this->assertFalse($restaurant->isOpenNow());
    }

    public function test_the_window_still_runs_past_midnight(): void
    {
        $restaurant = $this->openAllWeek();

        // 01:30 in Gamasa, inside «‪10:00‬ – ‪02:00‬», and 22:30 the previous day in UTC.
        Carbon::setTestNow(Carbon::parse('2026-09-05 22:30:00', 'UTC'));

        $this->assertTrue($restaurant->isOpenNow());
    }

    public function test_the_weekday_row_is_chosen_on_the_local_day(): void
    {
        $restaurant = $this->openAllWeek('09:00:00', '23:00:00');

        // 01:00 Saturday in Gamasa is 22:00 Friday in UTC. Reading the UTC weekday
        // picks a different row, which for a kitchen with different Friday hours is a
        // wrong answer rather than an off-by-one nobody notices.
        Carbon::setTestNow(Carbon::parse('2026-09-04 22:00:00', 'UTC'));

        $this->assertSame(5, now()->dayOfWeek, 'Friday on the server');
        $this->assertSame(6, BusinessTime::now()->dayOfWeek, 'Saturday in Gamasa');

        $restaurant->openingHours()->where('weekday', 6)->update(['is_closed' => true]);

        $this->assertFalse($restaurant->fresh()->isOpenNow());
    }

    public function test_a_kitchen_with_no_hours_at_all_follows_the_manual_switch(): void
    {
        $restaurant = $this->makeRestaurant('سندوتش عمر');

        Carbon::setTestNow(Carbon::parse('2026-09-05 07:48:00', 'UTC'));

        $this->assertTrue($restaurant->isOpenNow());

        $restaurant->update(['is_open_manual' => false]);

        $this->assertFalse($restaurant->fresh()->isOpenNow());
    }

    public function test_the_business_date_is_the_local_calendar_day(): void
    {
        // 01:00 Saturday in Gamasa belongs to Saturday's shift, not Friday's.
        Carbon::setTestNow(Carbon::parse('2026-09-04 22:00:00', 'UTC'));

        $this->assertSame('2026-09-04', now()->toDateString());
        $this->assertSame('2026-09-05', BusinessTime::date());
    }
}
