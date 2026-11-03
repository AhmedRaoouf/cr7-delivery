<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Force update — 426 for a phone app below its platform's minimum, and nobody else. */
class ForceUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const PLAY = 'https://play.google.com/store/apps/details?id=com.cr7delivery.cr7_delivery';

    private const APPLE = 'https://apps.apple.com/app/id6815852436';

    private function min(string $android, string $ios = '1.0.0'): void
    {
        Settings::set('app.force_update.android_min_version', $android, 'app', true);
        Settings::set('app.force_update.ios_min_version', $ios, 'app', true);
    }

    private function app(string $version, string $platform = 'android'): array
    {
        return ['X-App' => 'app', 'X-App-Version' => $version, 'X-Platform' => $platform];
    }

    public function test_below_the_minimum_answers_426_in_the_shape_the_app_reads(): void
    {
        $this->min('1.0.24');

        $this->getJson('/api/v1/areas', $this->app('1.0.23+29'))
            ->assertStatus(426)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'force_update')
            ->assertJsonPath('meta.store_url', self::PLAY)
            ->assertJsonPath('meta.min_version', '1.0.24')
            ->assertJsonStructure(['message']);
    }

    public function test_at_or_above_the_minimum_passes(): void
    {
        $this->min('1.0.24');

        $this->getJson('/api/v1/areas', $this->app('1.0.24'))->assertOk();
        $this->getJson('/api/v1/areas', $this->app('1.0.24+30'))->assertOk();
        $this->getJson('/api/v1/areas', $this->app('1.1.0'))->assertOk();
    }

    public function test_versions_compare_numerically_not_as_text(): void
    {
        $this->min('1.0.10');

        $this->getJson('/api/v1/areas', $this->app('1.0.9'))->assertStatus(426);
        $this->getJson('/api/v1/areas', $this->app('1.0.10'))->assertOk();
        $this->getJson('/api/v1/areas', $this->app('1.0.100'))->assertOk();
    }

    public function test_each_platform_uses_its_own_minimum_and_store_link(): void
    {
        $this->min('1.0.5', '1.0.20');

        $this->getJson('/api/v1/areas', $this->app('1.0.10', 'android'))->assertOk();
        $this->getJson('/api/v1/areas', $this->app('1.0.10', 'ios'))
            ->assertStatus(426)
            ->assertJsonPath('meta.store_url', self::APPLE);
    }

    public function test_unknown_or_missing_headers_are_never_blocked(): void
    {
        $this->min('9.0.0', '9.0.0');

        $this->getJson('/api/v1/areas')->assertOk();
        $this->getJson('/api/v1/areas', ['X-App' => 'app'])->assertOk();
        $this->getJson('/api/v1/areas', ['X-App' => 'app', 'X-App-Version' => '1.0.0'])->assertOk(); // no platform
        $this->getJson('/api/v1/areas', ['X-App' => 'app', 'X-App-Version' => 'garbage', 'X-Platform' => 'ios'])->assertOk();
        $this->getJson('/api/v1/areas', ['X-App-Version' => '1.0.0', 'X-Platform' => 'ios'])->assertOk(); // not the phone app
        $this->getJson('/api/v1/areas', ['X-App' => 'restaurant', 'X-App-Version' => '1.0.0', 'X-Platform' => 'ios'])->assertOk();
        $this->getJson('/api/v1/areas', $this->app('1.0.0', 'windows'))->assertOk();
    }

    public function test_config_and_session_end_points_are_exempt(): void
    {
        $this->min('9.0.0', '9.0.0');

        $this->getJson('/api/v1/config', $this->app('1.0.0'))
            ->assertOk()
            ->assertJsonPath('data.force_update.android_min', '9.0.0')
            ->assertJsonPath('data.force_update.store_url_android', self::PLAY)
            ->assertJsonPath('data.force_update.store_url_ios', self::APPLE);

        $this->assertNotSame(426, $this->postJson('/api/v1/auth/refresh', [], $this->app('1.0.0'))->status());
        $this->assertNotSame(426, $this->postJson('/api/v1/auth/logout', [], $this->app('1.0.0'))->status());
        $this->assertSame(426, $this->postJson('/api/v1/auth/otp/send', ['phone' => '01000000555'], $this->app('1.0.0'))->status());
    }

    public function test_the_default_minimum_blocks_nobody(): void
    {
        $this->getJson('/api/v1/areas', $this->app('1.0.0'))->assertOk();
    }

    public function test_a_review_account_is_never_blocked(): void
    {
        $this->min('9.0.0', '9.0.0');
        config(['services.review.phones' => '01000000201', 'services.review.code' => '424242']);

        // Before sign-in: the phone in the body.
        $this->assertNotSame(426, $this->postJson('/api/v1/auth/otp/send', ['phone' => '01000000201'], $this->app('1.0.0'))->status());

        // After sign-in: the token's own account.
        $user = User::query()->create(['phone' => '01000000201', 'name' => 'مراجع', 'type' => UserType::Customer, 'password' => Hash::make('x'), 'is_active' => true]);
        $token = $user->createToken('t')->plainTextToken;
        $this->getJson('/api/v1/areas', $this->app('1.0.0') + ['Authorization' => "Bearer {$token}"])->assertOk();

        // A different account with the same old app is blocked.
        $other = User::query()->create(['phone' => '01000000777', 'name' => 'عميل', 'type' => UserType::Customer, 'password' => Hash::make('x'), 'is_active' => true]);
        $otherToken = $other->createToken('t')->plainTextToken;
        $this->app['auth']->forgetGuards(); // one process serves both requests; drop the cached user
        $this->getJson('/api/v1/areas', $this->app('1.0.0') + ['Authorization' => "Bearer {$otherToken}"])->assertStatus(426);
    }

    public function test_an_edited_store_link_is_published_and_used(): void
    {
        $this->min('2.0.0');
        Settings::set('app.force_update.store_url_android', 'https://example.test/play', 'app', true);

        $this->getJson('/api/v1/areas', $this->app('1.0.0'))->assertStatus(426)->assertJsonPath('meta.store_url', 'https://example.test/play');
        $this->getJson('/api/v1/config')->assertJsonPath('data.force_update.store_url_android', 'https://example.test/play');
    }
}
