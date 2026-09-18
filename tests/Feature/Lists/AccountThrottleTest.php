<?php

namespace Tests\Feature\Lists;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsListFixtures;
use Tests\TestCase;

/**
 * Batch 68 — ADMIN-LISTS.md §6: one bucket per signed-in account, sized by account type,
 * refused in the house envelope with `meta.retry_after`.
 */
class AccountThrottleTest extends TestCase
{
    use BuildsListFixtures;
    use RefreshDatabase;

    private Restaurant $place;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpListWorld();
        $this->place = $this->makeRestaurant('سندوتش عمر');
    }

    public function test_an_account_past_its_minute_gets_the_house_429(): void
    {
        config(['lists.throttle.customer' => 2]);
        $this->actingAsCustomer($this->customerNamed('منى', '01022220002'));

        $this->getJson('/api/v1/orders')->assertOk()
            ->assertHeader('X-RateLimit-Limit', '2')
            ->assertHeader('X-RateLimit-Remaining', '1');
        $this->getJson('/api/v1/notifications')->assertOk()->assertHeader('X-RateLimit-Remaining', '0');

        $refused = $this->getJson('/api/v1/orders')->assertStatus(429);

        $refused->assertJsonPath('success', false)
            ->assertJsonPath('error', 'rate_limited')
            ->assertHeader('Retry-After');
        $this->assertGreaterThan(0, $refused->json('meta.retry_after'));
        $this->assertLessThanOrEqual(60, $refused->json('meta.retry_after'));
    }

    public function test_the_bucket_is_the_account_and_its_size_is_the_accounts_type(): void
    {
        config(['lists.throttle.customer' => 1, 'lists.throttle.restaurant' => 3]);

        $this->actingAsCustomer($this->customerNamed('منى', '01022220002'));
        $this->getJson('/api/v1/orders')->assertOk();
        $this->getJson('/api/v1/orders')->assertStatus(429);

        // Another customer on the same address has a bucket of their own.
        $this->actingAsCustomer($this->customerNamed('أحمد', '01022220001'));
        $this->getJson('/api/v1/orders')->assertOk();

        // A place is sized as a place.
        $this->actingAsPlace($this->place);
        foreach (range(1, 3) as $i) {
            $this->getJson('/api/v1/restaurant/orders')->assertOk();
        }
        $this->getJson('/api/v1/restaurant/orders')->assertStatus(429);
    }

    public function test_every_signed_in_group_is_throttled(): void
    {
        config(['lists.throttle.admin' => 1, 'lists.throttle.driver' => 1]);

        $this->actingAsAdmin($this->globalAdmin());
        $this->getJson('/api/v1/admin/orders')->assertOk();
        $this->getJson('/api/v1/admin/orders')->assertStatus(429);

        $this->actingAsCaptainUser($this->captainNamed('كابتن رامي', '01011110001'));
        $this->getJson('/api/v1/driver/orders')->assertOk();
        $this->getJson('/api/v1/driver/orders')->assertStatus(429);
    }

    public function test_the_public_search_is_throttled_by_address(): void
    {
        config(['lists.throttle.search_per_ip' => 2]);

        $this->getJson('/api/v1/search?search=عمر')->assertOk();
        $this->getJson('/api/v1/search?search=عمر')->assertOk();
        $this->getJson('/api/v1/search?search=عمر')->assertStatus(429)->assertJsonPath('error', 'rate_limited');

        // The catalogue beside it is not.
        $this->getJson('/api/v1/restaurants')->assertOk();
    }
}
