<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A live captain on 2026-09-05: pinging every 13 seconds, «أنت متصل الآن» on the
 * screen, and `drivers.is_online = false` in the database. Invisible to every
 * dispatch round, and told nothing.
 *
 * Four things write `is_online` — the captain's own toggle, sign-out, an admin,
 * an accepted order — and exactly one thing reads it: dispatch. The app writes it
 * once, at the toggle, and believes itself for the rest of the shift. The ping is
 * the only conversation frequent enough to settle the disagreement, so it carries
 * the answer back.
 */
class DriverLocationDutyEchoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
    }

    private function captain(bool $isOnline): Driver
    {
        $user = User::factory()->create(['type' => 'driver']);
        $driver = Driver::query()->create([
            'user_id' => $user->id,
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'earning_type' => 'fixed',
            'earning_value' => 15,
            'is_active' => true,
            'is_online' => $isOnline,
            'is_available' => true,
        ]);

        Sanctum::actingAs($user);
        $this->withHeader('X-App', 'app');

        return $driver;
    }

    public function test_a_ping_reports_back_what_the_server_thinks_of_the_shift(): void
    {
        $this->captain(isOnline: false);

        $this->postJson('/api/v1/driver/location', [
            'latitude' => 31.4021,
            'longitude' => 31.4761,
        ])
            ->assertOk()
            // The whole point: the ping succeeded and the shift is still over.
            ->assertJsonPath('data.is_online', false)
            ->assertJsonPath('data.is_available', true);
    }

    public function test_an_online_captain_is_told_so(): void
    {
        $this->captain(isOnline: true);

        $this->postJson('/api/v1/driver/location', [
            'latitude' => 31.4021,
            'longitude' => 31.4761,
        ])
            ->assertOk()
            ->assertJsonPath('data.is_online', true);
    }

    public function test_the_ping_still_moves_the_liveness_stamp_dispatch_reads(): void
    {
        $driver = $this->captain(isOnline: true);

        $this->assertNull($driver->location_updated_at);

        $this->postJson('/api/v1/driver/location', [
            'latitude' => 31.4021,
            'longitude' => 31.4761,
        ])->assertOk();

        $this->assertNotNull($driver->fresh()->location_updated_at);
    }
}
