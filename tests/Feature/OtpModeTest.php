<?php

namespace Tests\Feature;

use App\Enums\OtpPurpose;
use App\Models\OtpCode;
use App\Models\User;
use App\Support\Otp;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesOtpChannel;
use Tests\TestCase;

/**
 * D-106 — «عايز كمان تفعل ال sms في كله مفيش كود ثابت وبتحكم في دا من الادمين».
 *
 * The code switch moved from `.env` to `ADM-46`, and each of its numbers with it. What
 * these pin down is the half a test of the response alone cannot see: whether the
 * gateway was called. Test mode must cost nothing; an admin's code must be sent whatever
 * the mode says (D-107).
 */
class OtpModeTest extends TestCase
{
    use FakesOtpChannel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Symfony's test request says `Accept-Language: en-us` unless told otherwise,
        // and a sentence `lang/en.json` knows would come back in English.
        $this->withHeaders(['X-App' => 'app', 'Accept-Language' => 'ar']);
    }

    public function test_test_mode_sends_no_sms_and_answers_the_operators_code(): void
    {
        $channel = $this->fakeOtpChannel();
        $this->otpTestMode('654321');

        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01011122233'])
            ->assertOk()
            ->assertJsonPath('data.static_otp', '654321')
            ->assertJsonPath('data.resend_in', 60)
            ->assertJsonPath('data.expires_in', 300);

        // No message left the server — no SMS, no cost.
        $this->assertSame([], $channel->sent);

        // …and the code it stored is the one it answered, hashed like any other.
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '01011122233', 'code' => '654321'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['user', 'token']]);
    }

    public function test_sms_mode_sends_a_random_code_and_echoes_nothing(): void
    {
        $channel = $this->fakeOtpChannel();
        $this->otpSmsMode();

        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01011122233'])
            ->assertOk()
            ->assertJsonPath('data.static_otp', null);

        $this->assertCount(1, $channel->sent);
        $code = $channel->lastCodeFor('01011122233');
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $code);

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '01011122233', 'code' => $code])
            ->assertOk();
    }

    public function test_sms_is_the_mode_when_nobody_has_chosen_one(): void
    {
        $channel = $this->fakeOtpChannel();

        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01011122233'])
            ->assertOk()
            ->assertJsonPath('data.static_otp', null);

        $this->assertCount(1, $channel->sent);
        $this->assertNotSame('123456', $channel->lastCodeFor('01011122233'));
    }

    /**
     * D-107 — «يبقا دا الوحيد اللي مفيش فيه غير sms في اي حاله حتي لو هو ثابت للكل».
     * A reset for an admin's number is a real SMS with a random code in test mode too.
     */
    public function test_an_admins_code_is_always_a_real_sms(): void
    {
        $channel = $this->fakeOtpChannel();
        $this->otpTestMode();

        $admin = User::factory()->create(['type' => 'admin', 'phone' => '01000000099']);

        $this->postJson('/api/v1/auth/password/forgot', ['login' => $admin->phone])->assertOk();

        $this->assertCount(1, $channel->sent);
        $this->assertNotSame('123456', $channel->lastCodeFor($admin->phone));

        // Even asked for down the customer's own route, the fixed code never reaches it.
        $this->travel(2)->minutes();
        $this->postJson('/api/v1/auth/otp/send', ['phone' => $admin->phone, 'purpose' => 'login'])
            ->assertOk()
            ->assertJsonPath('data.static_otp', null);

        $this->assertCount(2, $channel->sent);

        // …so the test code opens nothing.
        $this->postJson('/api/v1/auth/password/reset', [
            'login' => $admin->phone,
            'code' => '123456',
            'password' => 'NewPass#2026',
            'password_confirmation' => 'NewPass#2026',
        ])->assertStatus(422)->assertJsonPath('error', 'otp_invalid');
    }

    public function test_a_password_change_code_is_protected_whoever_asks(): void
    {
        $this->otpTestMode();
        $customer = User::factory()->create(['type' => 'customer']);

        $this->assertTrue(Otp::isAdminProtected(OtpPurpose::PasswordChange, $customer));
        $this->assertFalse(Otp::usesTestCode(OtpPurpose::PasswordChange, $customer));
        $this->assertTrue(Otp::usesTestCode(OtpPurpose::Reset, $customer));
    }

    public function test_the_three_clocks_are_the_operators(): void
    {
        $this->fakeOtpChannel();
        $this->otpTestMode();
        Settings::set('otp.resend_seconds', 30, 'otp');
        Settings::set('otp.ttl_minutes', 2, 'otp');

        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01011122233'])
            ->assertOk()
            ->assertJsonPath('data.resend_in', 30)
            ->assertJsonPath('data.expires_in', 120);

        $this->travel(10)->seconds();
        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01011122233'])
            ->assertStatus(429)
            ->assertJsonPath('error', 'otp_resend_cooldown');

        $this->travel(25)->seconds();
        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01011122233'])->assertOk();

        // Two minutes to live, not the five that used to be written into the action.
        $this->travel(3)->minutes();
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '01011122233', 'code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'otp_expired');
    }

    public function test_the_hourly_cap_per_number_is_the_operators(): void
    {
        $this->fakeOtpChannel();
        $this->otpTestMode();
        Settings::set('otp.max_per_hour', 2, 'otp');

        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01011122233', 'purpose' => 'login'])->assertOk();
        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01011122233', 'purpose' => 'register'])->assertOk();

        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01011122233', 'purpose' => 'reset'])
            ->assertStatus(429)
            ->assertJsonPath('error', 'otp_send_rate_limited');
    }

    /**
     * A wrong code costs an attempt, and the attempts add up.
     *
     * They did not: the check ran inside the transaction that then threw, so the rollback
     * undid `increment('attempts')` every time and a six-digit code could be guessed for
     * as long as it lived.
     */
    public function test_wrong_codes_add_up_to_the_operators_limit(): void
    {
        $this->fakeOtpChannel();
        $this->otpTestMode();
        Settings::set('otp.max_attempts', 3, 'otp');

        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01011122233'])->assertOk();

        foreach ([2, 1] as $left) {
            $this->postJson('/api/v1/auth/otp/verify', ['phone' => '01011122233', 'code' => '000000'])
                ->assertStatus(422)
                ->assertJsonPath('error', 'otp_invalid')
                ->assertJsonPath('meta.attempts_left', $left);
        }

        // Batch 68 — the guess that uses the last attempt says the code is dead at once,
        // and when a new one may be asked for, rather than `attempts_left: 0`.
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '01011122233', 'code' => '000000'])
            ->assertStatus(423)
            ->assertJsonPath('error', 'otp_attempts_exceeded')
            ->assertJsonPath('meta.attempts_left', 0)
            ->assertJsonStructure(['meta' => ['retry_after', 'available_at']]);

        $this->assertSame(3, OtpCode::query()->where('phone', '01011122233')->value('attempts'));

        // Locked — the right code no longer helps.
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '01011122233', 'code' => '123456'])
            ->assertStatus(423)
            ->assertJsonPath('error', 'otp_attempts_exceeded');
    }

    public function test_one_address_cannot_send_more_than_twenty_an_hour(): void
    {
        $this->fakeOtpChannel();
        $this->otpTestMode();

        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/v1/auth/otp/send', ['phone' => sprintf('010111%05d', $i)])->assertOk();
        }

        // A fresh number, so the per-phone cap is not what refuses it.
        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01099999999'])
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'otp_send_rate_limited')
            ->assertJsonPath('message', 'محاولات كثيرة جدًا، حاول لاحقًا');

        // The same bucket covers «نسيت كلمة المرور».
        $this->postJson('/api/v1/auth/password/forgot', ['login' => '01099999998'])
            ->assertStatus(429)
            ->assertJsonPath('error', 'otp_send_rate_limited');
    }

    /** D-108 — test mode is for account codes; an order code is random whatever it says. */
    public function test_order_codes_are_random_even_in_test_mode(): void
    {
        $this->otpTestMode();

        $codes = collect(range(1, 30))->map(fn () => Otp::orderCode());

        $codes->each(fn (string $code) => $this->assertMatchesRegularExpression('/^\d{4}$/', $code));
        $this->assertGreaterThan(1, $codes->unique()->count());
    }
}
