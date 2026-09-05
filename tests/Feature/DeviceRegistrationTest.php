<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `POST /devices` answered 422 to every registration the phone made.
 *
 * Found in a device log, not in a bug report, because the app swallows the failure by
 * design — a customer must not get an error toast about a feature they never asked for.
 * The rule read `in:app,restaurant,admin`, which is the `X-App` header's vocabulary; the
 * column is `enum('app', ['customer','driver','restaurant','admin'])` and the mobile
 * binary can only ever send `customer` or `driver` (D-45). So no customer and no captain
 * has ever had a device token stored — which is every push notification on both shells,
 * including the one that tells a captain an order is waiting.
 */
class DeviceRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function shells(): array
    {
        return [
            'the customer shell' => ['customer', 'customer'],
            'the captain shell' => ['driver', 'driver'],
        ];
    }

    #[DataProvider('shells')]
    public function test_the_mobile_binary_can_register_a_device(string $sent, string $stored): void
    {
        $user = User::factory()->create(['type' => $sent === 'driver' ? 'driver' : 'customer']);
        Sanctum::actingAs($user);

        $this->withHeader('X-App', 'app')
            ->postJson('/api/v1/devices', [
                'token' => 'fcm-'.str_repeat('a', 60),
                'platform' => 'android',
                'app' => $sent,
                'device_id' => 'device-1',
            ])
            ->assertOk();

        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $user->id,
            'device_id' => 'device-1',
            'app' => $stored,
        ]);
    }

    public function test_a_dashboard_can_still_register_its_own(): void
    {
        $user = User::factory()->create(['type' => 'restaurant']);
        Sanctum::actingAs($user);

        $this->withHeader('X-App', 'restaurant')
            ->postJson('/api/v1/devices', [
                'token' => 'fcm-web',
                'platform' => 'web',
                'app' => 'restaurant',
                'device_id' => 'browser-1',
            ])
            ->assertOk();
    }

    public function test_a_value_the_column_could_not_hold_is_still_refused(): void
    {
        $user = User::factory()->create(['type' => 'customer']);
        Sanctum::actingAs($user);

        // The old rule *accepted* this one, and the enum column would then have thrown a
        // 500 on the insert rather than a 422 on the input.
        $this->withHeader('X-App', 'app')
            ->postJson('/api/v1/devices', [
                'token' => 'fcm-token',
                'platform' => 'android',
                'app' => 'app',
                'device_id' => 'device-2',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('app');
    }

    public function test_registering_twice_from_one_phone_updates_the_row(): void
    {
        $user = User::factory()->create(['type' => 'driver']);
        Sanctum::actingAs($user);

        foreach (['first-token', 'second-token'] as $token) {
            $this->withHeader('X-App', 'app')
                ->postJson('/api/v1/devices', [
                    'token' => $token,
                    'platform' => 'android',
                    'app' => 'driver',
                    'device_id' => 'one-phone',
                ])
                ->assertOk();
        }

        $this->assertSame(1, $user->deviceTokens()->count());
        $this->assertSame('second-token', $user->deviceTokens()->first()->token);
    }
}
