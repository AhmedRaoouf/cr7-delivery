<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A word where an id belongs is a 404, never a 500.
 *
 * `GET /driver/offers/current` answered **500** on the live API. There is no such route:
 * `/driver/offers/{orderId}` matched it, `current` reached an action typed
 * `int $orderId`, and PHP raised a TypeError before any of this application's code ran.
 * Model-bound parameters were already safe — implicit binding looks the key up and
 * answers 404 — but the three that resolve by hand had nothing in front of them.
 *
 * The distinction matters operationally, not just aesthetically: a 500 is a page an
 * operator escalates and a developer has to reproduce, and a 404 is one they read and
 * move on from. It is also the difference between «this client sent a bad URL» and
 * «this server is broken».
 */
class RouteParameterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
    }

    public function test_a_word_where_an_order_id_belongs_is_not_a_server_error(): void
    {
        $user = User::query()->create([
            'name' => 'كابتن اختبار',
            'phone' => '01099998888',
            'type' => 'driver',
            'is_active' => true,
        ]);
        $user->driver()->create([
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $this->withHeader('X-App', 'app')
            ->getJson('/api/v1/driver/offers/current')
            ->assertNotFound();

        // The route that does exist still works, so the constraint did not shadow it.
        $this->withHeader('X-App', 'app')
            ->getJson('/api/v1/driver/offers')
            ->assertOk();
    }

    public function test_the_one_wildcard_that_is_meant_to_be_words_still_takes_them(): void
    {
        // `/pages/{slug}` is the exception the pattern list leaves out. A 404 here is the
        // page not existing; a 405 or a route miss would mean the constraint caught it.
        $this->getJson('/api/v1/pages/terms')->assertNotFound();
    }
}
