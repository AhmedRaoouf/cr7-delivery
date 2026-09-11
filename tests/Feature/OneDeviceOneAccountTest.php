<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * D-79 — a push token belongs to exactly one account.
 *
 * Operator, 2026-09-11: «الـ notifications المفروض لما أعمل تسجيل خروج وأدخل بـ role
 * تانية يجيلي بتاع الـ role الأخيرة — مش عايز حاجة تجيلي من الأولى ولا أي بيانات من
 * الأولى». The table's only unique index was `(user_id, token)` and nothing ever looked
 * a token up across accounts, so a phone that had been a captain and then became an
 * admin was **two** live rows, and `NotificationDispatcher` sends to every token a user
 * has. Offers for a captain who was no longer signed in kept ringing on it.
 */
class OneDeviceOneAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_a_token_takes_it_from_the_previous_account(): void
    {
        $captain = User::factory()->create(['type' => 'driver', 'is_active' => true]);
        $admin = User::factory()->create(['type' => 'admin', 'is_active' => true]);

        $device = ['token' => 'fcm-one', 'platform' => 'android', 'device_id' => 'phone-1'];

        Sanctum::actingAs($captain);
        $this->withHeaders(['X-App' => 'app'])
            ->postJson('/api/v1/devices', $device + ['app' => 'driver'])
            ->assertOk();

        Sanctum::actingAs($admin);
        $this->withHeaders(['X-App' => 'app'])
            ->postJson('/api/v1/devices', $device + ['app' => 'admin'])
            ->assertOk();

        $this->assertSame(0, DeviceToken::query()->where('user_id', $captain->id)->count(),
            'the previous account keeps nothing that could still ring this phone');
        $this->assertSame(1, DeviceToken::query()->where('token', 'fcm-one')->count());
        $this->assertSame($admin->id, DeviceToken::query()->where('token', 'fcm-one')->value('user_id'));
    }

    public function test_a_rotated_token_replaces_the_row_rather_than_joining_it(): void
    {
        $user = User::factory()->create(['type' => 'customer', 'is_active' => true]);
        Sanctum::actingAs($user);

        foreach (['fcm-old', 'fcm-new'] as $token) {
            $this->withHeaders(['X-App' => 'app'])
                ->postJson('/api/v1/devices', [
                    'token' => $token, 'platform' => 'android', 'app' => 'customer', 'device_id' => 'phone-1',
                ])
                ->assertOk();
        }

        $this->assertSame(1, DeviceToken::query()->where('user_id', $user->id)->count());
        $this->assertSame('fcm-new', DeviceToken::query()->where('user_id', $user->id)->value('token'));
    }

    public function test_signing_out_unregisters_the_phone(): void
    {
        $user = User::factory()->create(['type' => 'restaurant', 'is_active' => true]);
        Sanctum::actingAs($user);

        $this->withHeaders(['X-App' => 'app', 'X-Device-Id' => 'phone-1'])
            ->postJson('/api/v1/devices', [
                'token' => 'fcm-one', 'platform' => 'android', 'app' => 'restaurant', 'device_id' => 'phone-1',
            ])
            ->assertOk();

        // Four of the app's five sign-out buttons never called `DELETE /devices`, so the
        // server does it too, from the header the client already sends.
        $this->withHeaders(['X-App' => 'app', 'X-Device-Id' => 'phone-1'])
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertSame(0, DeviceToken::query()->where('user_id', $user->id)->count());
    }
}
