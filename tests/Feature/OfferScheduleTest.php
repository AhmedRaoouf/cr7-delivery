<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\HomePopup;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\BusinessTime;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «عروض للمطاعم زى العروض المنبثقه» — the two things an offer has that a card did not.
 *
 * Operator, 2026-09-10: «يكون فيها عداد تنازلى لو العرض مثلا 3 ايام… مثال 2 بيتزا لارج
 * والثلاثه مجانا. العرض مثلا خميس وجمعه وسبت».
 *
 * A weekly pattern is not a window: `starts_at`/`ends_at` can say «this Thursday to this
 * Saturday» once and then the offer is over. `days_of_week` says it every week until the
 * window closes, and the two compose — an offer that runs Thursday to Saturday for three
 * weeks is a three-week window with three days named inside it.
 */
class OfferScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($admin);
        $this->withHeader('X-App', 'admin');

        return $admin;
    }

    /** @return array<int, array<string, mixed>> */
    private function appSees(): array
    {
        return $this->withHeader('X-App', 'app')
            ->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/home-popups')
            ->assertOk()
            ->json('data');
    }

    /** A Cairo Thursday, well away from either midnight so no clause is on a boundary. */
    private function freezeOnThursday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-17 18:00:00', BusinessTime::timezone())->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_offer_named_for_three_days_is_shown_on_one_of_them(): void
    {
        $this->freezeOnThursday();

        HomePopup::query()->create([
            'title_ar' => '2 بيتزا لارج والثالثة مجانًا',
            'link_type' => 'none',
            // Thursday, Friday, Saturday — `0 = Sunday`, so 4, 5, 6.
            'days_of_week' => [4, 5, 6],
        ]);

        $this->assertSame(
            ['2 بيتزا لارج والثالثة مجانًا'],
            array_column($this->appSees(), 'title'),
        );
    }

    public function test_the_same_offer_is_not_shown_on_a_monday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-14 18:00:00', BusinessTime::timezone())->utc());

        HomePopup::query()->create([
            'title_ar' => '2 بيتزا لارج والثالثة مجانًا',
            'link_type' => 'none',
            'days_of_week' => [4, 5, 6],
        ]);

        $this->assertSame([], $this->appSees());
    }

    /**
     * The regression the column exists to prevent.
     *
     * Cairo is two hours ahead of UTC, so 00:30 on a Cairo Thursday is still Wednesday
     * on the server. A weekday read off `now()->dayOfWeek` would hide a Thursday offer
     * for the first two hours of its Thursday and show it for the first two hours of
     * Sunday — and the operator would see it working perfectly at noon.
     */
    public function test_the_weekday_is_the_customers_weekday_not_the_servers(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-17 00:30:00', BusinessTime::timezone())->utc());

        HomePopup::query()->create([
            'title_ar' => 'عرض الخميس',
            'link_type' => 'none',
            'days_of_week' => [4],
        ]);

        $this->assertSame(['عرض الخميس'], array_column($this->appSees(), 'title'));
    }

    public function test_a_card_with_no_days_named_runs_every_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-14 18:00:00', BusinessTime::timezone())->utc());

        HomePopup::query()->create([
            'title_ar' => 'بلا أيام',
            'link_type' => 'none',
            'days_of_week' => null,
        ]);

        $this->assertSame(['بلا أيام'], array_column($this->appSees(), 'title'));
    }

    public function test_the_countdown_is_counted_by_the_server_not_the_phone(): void
    {
        $this->freezeOnThursday();

        HomePopup::query()->create([
            'title_ar' => 'ثلاثة أيام',
            'link_type' => 'none',
            'show_countdown' => true,
            'ends_at' => now()->addDays(3),
        ]);

        $card = $this->appSees()[0];

        $this->assertTrue($card['show_countdown']);
        $this->assertEqualsWithDelta(3 * 24 * 60 * 60, $card['countdown_seconds'], 5);
    }

    /** A card that runs all season has an `ends_at` too, and no clock should tick on it. */
    public function test_an_end_date_alone_does_not_start_a_countdown(): void
    {
        $this->freezeOnThursday();

        HomePopup::query()->create([
            'title_ar' => 'حتى نهاية الموسم',
            'link_type' => 'none',
            'ends_at' => now()->addDays(90),
        ]);

        $card = $this->appSees()[0];

        $this->assertFalse($card['show_countdown']);
        $this->assertNull($card['countdown_seconds']);
    }

    public function test_an_operator_writes_a_restaurant_offer_with_its_days_and_its_clock(): void
    {
        $this->actingAsAdmin();
        $place = $this->makeRestaurant('بوكس ع السريع');

        $id = $this->postJson('/api/v1/admin/home-popups', [
            'title_ar' => '2 بيتزا لارج والثالثة مجانًا',
            'body_ar' => 'العرض ساري الخميس والجمعة والسبت.',
            'link_type' => 'restaurant',
            'restaurant_id' => $place->id,
            'days_of_week' => [4, 5, 6],
            'show_countdown' => true,
            'ends_at' => now()->addDays(3)->toIso8601String(),
        ])->assertCreated()->json('data.id');

        $row = HomePopup::query()->findOrFail($id);

        $this->assertSame([4, 5, 6], $row->days_of_week);
        $this->assertTrue($row->show_countdown);
        // The destination is the place, in the shape a banner speaks — so the app's
        // existing resolver takes it without learning anything.
        $this->assertSame((string) $place->id, $this->getJson("/api/v1/admin/home-popups/{$id}")->json('data.link_value'));
    }

    /**
     * An empty array is «no day at all» — a card that would never appear and whose
     * operator would spend an afternoon working out why.
     */
    public function test_an_offer_cannot_be_saved_with_no_days_at_all(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/home-popups', [
            'title_ar' => 'عرض بلا أيام',
            'days_of_week' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('days_of_week');
    }

    /**
     * Taking the days back off — the edit that a multipart body makes hard.
     *
     * An absent field means «leave it alone» on a PATCH, so the dashboard cannot clear a
     * column by omitting it, and `[]` is refused above. What it sends is an empty string,
     * which Laravel's own `ConvertEmptyStringsToNull` turns into the null the column
     * wants. This test exists because that conversion is a framework default, and a
     * framework default that this feature quietly depends on is one upgrade away from
     * being a Thursday offer nobody can un-Thursday.
     */
    public function test_an_offer_can_be_put_back_on_every_day(): void
    {
        $this->actingAsAdmin();

        $popup = HomePopup::query()->create([
            'title_ar' => 'كان للخميس',
            'link_type' => 'none',
            'days_of_week' => [4],
        ]);

        $this->patchJson("/api/v1/admin/home-popups/{$popup->id}", [
            'days_of_week' => '',
        ])->assertOk();

        $this->assertNull($popup->fresh()->days_of_week);
    }

    public function test_a_day_outside_the_week_is_refused(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/home-popups', [
            'title_ar' => 'اليوم الثامن',
            'days_of_week' => [7],
        ])->assertStatus(422)->assertJsonValidationErrors('days_of_week.0');
    }

    public function test_a_restaurant_deleted_under_an_offer_leaves_a_card_with_no_destination(): void
    {
        $this->actingAsAdmin();
        $place = $this->makeRestaurant('ناصر القياس');

        $popup = HomePopup::query()->create([
            'title_ar' => 'عرض المكان',
            'link_type' => 'restaurant',
            'restaurant_id' => $place->id,
        ]);

        Restaurant::query()->whereKey($place->id)->forceDelete();

        $this->assertNull($popup->fresh()->restaurant_id);
    }
}
