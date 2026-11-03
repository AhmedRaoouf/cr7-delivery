<?php

namespace Tests\Feature;

use App\Actions\Customer\CreateOrderAction;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Catalog\FirstPublished;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsListFixtures;
use Tests\TestCase;

/**
 * doc 99 §5 — D-173: a new place shows a welcome 5.0 for 14 days or 20 ratings,
 * whichever comes first. No rating is ever written; `rating_avg` stays the truth, except
 * to a 1.0.23 app, whose only field it is.
 */
class WelcomeRatingTest extends TestCase
{
    use BuildsListFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-03 10:00:00');
        $this->setUpListWorld();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function badge(Restaurant $place, array $headers = []): array
    {
        return $this->withHeaders($headers)->getJson('/api/v1/restaurants/'.$place->id)->assertOk()->json('data');
    }

    /** An established place: published long ago, with real ratings. */
    private function established(string $name, float $avg, int $count): Restaurant
    {
        $place = $this->makeRestaurant($name);
        $place->forceFill([
            'first_published_at' => now()->subYear(),
            'rating_avg' => $avg,
            'rating_count' => $count,
        ])->save();

        return $place;
    }

    public function test_a_new_place_is_welcomed_until_fourteen_days_pass(): void
    {
        $place = $this->makeRestaurant('مكان جديد');
        $this->assertTrue($place->fresh()->first_published_at->equalTo(now()));

        $data = $this->badge($place);
        $this->assertSame(['value' => 5, 'count' => 0, 'source' => 'welcome', 'is_new' => true], array_intersect_key($data['rating_badge'], array_flip(['value', 'count', 'source', 'is_new'])));
        $this->assertSame('2026-11-17T10:00:00.000000Z', $data['rating_badge']['welcome_until']);
        // The true average, for a current app.
        $this->assertEquals(0, $data['rating_avg']);

        // Three real ratings during the window: still 5.0, with the real count.
        $place->forceFill(['rating_avg' => 3.0, 'rating_count' => 3])->save();
        Carbon::setTestNow('2026-11-17 09:59:00');
        $data = $this->badge($place);
        $this->assertSame('welcome', $data['rating_badge']['source']);
        $this->assertEquals(5.0, $data['rating_badge']['value']);
        $this->assertSame(3, $data['rating_badge']['count']);
        $this->assertEquals(3.0, $data['rating_avg']);

        // Day fourteen is over: the real average, and no longer «جديد».
        Carbon::setTestNow('2026-11-17 10:01:00');
        $data = $this->badge($place);
        $this->assertSame(['value' => 3, 'count' => 3, 'source' => 'real', 'is_new' => false, 'welcome_until' => null], $data['rating_badge']);

        $this->assertSame(0, DB::table('ratings')->count(), 'a welcome must never write a rating');
    }

    public function test_twenty_ratings_end_the_welcome_before_fourteen_days(): void
    {
        $place = $this->makeRestaurant('مكان مزدحم');
        Carbon::setTestNow('2026-11-06 10:00:00');

        $place->forceFill(['rating_avg' => 4.2, 'rating_count' => 19])->save();
        $this->assertSame('welcome', $this->badge($place)['rating_badge']['source']);

        $place->forceFill(['rating_count' => 20])->save();
        $badge = $this->badge($place)['rating_badge'];
        $this->assertSame('real', $badge['source']);
        $this->assertEquals(4.2, $badge['value']);
        // Still new by the calendar.
        $this->assertTrue($badge['is_new']);
    }

    public function test_no_ratings_after_the_window_reads_none_never_zero(): void
    {
        $place = $this->makeRestaurant('مكان هادئ');
        Carbon::setTestNow('2026-12-01 10:00:00');

        $badge = $this->badge($place)['rating_badge'];
        $this->assertSame('none', $badge['source']);
        $this->assertNull($badge['value']);
        $this->assertSame(0, $badge['count']);
    }

    public function test_switching_a_place_off_and_on_does_not_restart_the_welcome(): void
    {
        $place = $this->makeRestaurant('مكان قديم');
        $published = $place->fresh()->first_published_at;
        $admin = $this->globalAdmin();

        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->actingAsAdmin($admin)->postJson("/api/v1/admin/restaurants/{$place->id}/status", ['is_active' => false])->assertOk();
        $this->actingAsAdmin($admin)->postJson("/api/v1/admin/restaurants/{$place->id}/status", ['is_active' => true])->assertOk();
        $this->actingAsAdmin($admin)->patchJson("/api/v1/admin/restaurants/{$place->id}", ['name_ar' => 'اسم جديد'])->assertOk();

        $this->assertTrue($place->fresh()->first_published_at->equalTo($published));
        $this->assertSame('none', $this->badge($place)['rating_badge']['source']);
    }

    public function test_a_place_created_hidden_is_published_the_day_it_is_switched_on(): void
    {
        $place = $this->makeRestaurant('مكان مخفي');
        $place->forceFill(['is_active' => false, 'first_published_at' => null])->save();
        $this->assertNull($place->fresh()->first_published_at);

        Carbon::setTestNow('2026-11-20 08:00:00');
        $this->actingAsAdmin($this->globalAdmin())
            ->postJson("/api/v1/admin/restaurants/{$place->id}/status", ['is_active' => true])
            ->assertOk();

        $this->assertSame('2026-11-20 08:00:00', $place->fresh()->first_published_at->toDateTimeString());
        $this->assertSame('welcome', $this->badge($place)['rating_badge']['source']);
    }

    public function test_an_app_before_1_0_24_reads_the_welcome_in_rating_avg_and_is_cached_apart(): void
    {
        $place = $this->makeRestaurant('مكان جديد');
        $place->forceFill(['rating_avg' => 3.5, 'rating_count' => 2])->save();

        $legacy = ['X-App' => 'app', 'X-App-Version' => '1.0.23'];
        $current = ['X-App' => 'app', 'X-App-Version' => '1.0.24'];

        $old = $this->withHeaders($legacy)->getJson('/api/v1/restaurants')->assertOk();
        $new = $this->withHeaders($current)->getJson('/api/v1/restaurants')->assertOk();
        $none = $this->withHeaders(['X-App' => 'app'])->getJson('/api/v1/restaurants')->assertOk();

        $this->assertEquals(5.0, $old->json('data.0.rating_avg'));
        $this->assertEquals(3.5, $new->json('data.0.rating_avg'));
        $this->assertEquals(3.5, $none->json('data.0.rating_avg'));
        $this->assertSame('welcome', $new->json('data.0.rating_badge.source'));
        $this->assertNotSame($old->headers->get('ETag'), $new->headers->get('ETag'));

        // Asked again in the other order, each is served its own body.
        $this->assertEquals(5.0, $this->withHeaders($legacy)->getJson('/api/v1/restaurants')->json('data.0.rating_avg'));
        $this->assertEquals(3.5, $this->withHeaders($current)->getJson('/api/v1/restaurants')->json('data.0.rating_avg'));

        // The detail screen too; after the welcome both read the real average.
        $this->assertEquals(5.0, $this->badge($place, $legacy)['rating_avg']);
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->assertEquals(3.5, $this->badge($place, $legacy)['rating_avg']);
    }

    public function test_best_rated_and_min_rating_read_the_shown_value(): void
    {
        $top = $this->established('الأفضل', 4.8, 40);
        $low = $this->established('الأضعف', 3.0, 25);
        $welcome = $this->makeRestaurant('الجديد');
        $welcome->forceFill(['rating_avg' => 2.0, 'rating_count' => 1])->save();

        $ids = fn ($response) => array_column($response->assertOk()->json('data'), 'id');

        $this->assertSame([$welcome->id, $top->id, $low->id], $ids($this->getJson('/api/v1/restaurants?sort=-rating_avg')));
        $this->assertSame([$low->id, $top->id, $welcome->id], $ids($this->getJson('/api/v1/restaurants?sort=rating_avg')));
        $this->assertSame([$welcome->id, $top->id], $ids($this->getJson('/api/v1/restaurants?sort=-rating_avg&filter[min_rating]=4')));

        // The operator's «الأعلى تقييمًا» preset: the welcome place at 5.0, the rest as before.
        Settings::set('catalog.places_order', 'top_rated', 'catalog');
        $this->assertSame([$welcome->id, $top->id, $low->id], $ids($this->getJson('/api/v1/restaurants')));

        // Welcome off: everybody by the truth.
        Settings::set('catalog.welcome_rating_enabled', false, 'catalog');
        $this->assertSame([$top->id, $low->id, $welcome->id], $ids($this->getJson('/api/v1/restaurants')));
        $this->assertSame('real', $this->badge($welcome)['rating_badge']['source']);
        $this->assertTrue($this->badge($welcome)['rating_badge']['is_new']);
    }

    public function test_the_badge_rides_beside_every_place_name(): void
    {
        $place = $this->makeRestaurant('سندوتش عمر');
        $dish = $this->makeProduct($place, 'سندوتش فول', 15);

        $search = $this->getJson('/api/v1/search?'.http_build_query(['search' => 'سندوتش']))->assertOk();
        $this->assertSame('welcome', $search->json('data.restaurants.0.rating_badge.source'));
        $this->assertSame('welcome', $search->json('data.products.0.restaurant_rating_badge.source'));

        $this->getJson('/api/v1/products?ids='.$dish->id)->assertOk()
            ->assertJsonPath('data.0.restaurant_rating_badge.source', 'welcome');

        $customer = $this->makeCustomer();
        $this->makeCart($customer, [[$place, $dish, 1]]);
        $this->actingAsCustomer($customer)->getJson('/api/v1/cart')->assertOk()
            ->assertJsonPath('data.groups.0.restaurant.rating_badge.source', 'welcome');
    }

    public function test_the_backfill_uses_the_first_activation_then_the_first_order(): void
    {
        $admin = User::query()->create(['name' => 'مدير', 'phone' => '01000000055', 'type' => 'admin', 'is_active' => true]);

        $audited = $this->makeRestaurant('مفعّل من الإدارة');
        $ordered = $this->makeRestaurant('له طلبات قديمة');
        $plain = $this->makeRestaurant('بلا سجل');
        $hidden = $this->makeRestaurant('مخفي');

        DB::table('restaurants')->update(['first_published_at' => null, 'created_at' => '2026-01-01 00:00:00']);
        DB::table('restaurants')->where('id', $hidden->id)->update(['is_active' => false]);

        foreach (['2026-10-30 12:00:00' => true, '2026-10-01 12:00:00' => false, '2026-10-20 12:00:00' => true] as $at => $active) {
            DB::table('audit_logs')->insert([
                'user_id' => $admin->id, 'action' => 'restaurants.updated',
                'auditable_type' => Restaurant::class, 'auditable_id' => $audited->id,
                'new_values' => json_encode(['is_active' => $active]), 'created_at' => $at, 'updated_at' => $at,
            ]);
        }

        // Switched on by an admin last month, but it took an order in March.
        DB::table('audit_logs')->insert([
            'user_id' => $admin->id, 'action' => 'restaurants.updated',
            'auditable_type' => Restaurant::class, 'auditable_id' => $ordered->id,
            'new_values' => json_encode(['is_active' => true]), 'created_at' => '2026-10-25 09:00:00', 'updated_at' => '2026-10-25 09:00:00',
        ]);
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $this->makeCart($customer, [[$ordered, $this->makeProduct($ordered, 'طبق', 50), 1]]);
        $group = app(CreateOrderAction::class)->execute($customer, ['address_id' => $address->id, 'payment_method' => 'cash'], 'idem-'.uniqid());
        DB::table('orders')->where('order_group_id', $group->id)->update(['created_at' => '2026-03-01 08:00:00']);
        DB::table('restaurants')->where('id', $ordered->id)->update(['first_published_at' => null]);

        $this->assertSame(3, FirstPublished::backfill());

        $at = fn (Restaurant $r) => DB::table('restaurants')->where('id', $r->id)->value('first_published_at');
        $this->assertSame('2026-10-20 12:00:00', $at($audited));
        $this->assertSame('2026-03-01 08:00:00', $at($ordered));
        $this->assertSame('2026-01-01 00:00:00', $at($plain));
        $this->assertNull($at($hidden));

        // Run twice, nothing moves.
        $this->assertSame(0, FirstPublished::backfill());
    }
}
