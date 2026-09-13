<?php

namespace Tests\Feature;

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesOtpChannel;
use Tests\Concerns\RecordingOtpChannel;
use Tests\TestCase;

/**
 * D-107 — «هو المفروض الادمن اصلا بيبقا بايميل وباسوورد لو عايز يغير كلمه السر بقا تبقا
 * ب sms تمام ويبقا دا الوحيد اللي مفيش فيه غير sms في اي حاله حتي لو هو ثابت للكل».
 *
 * An email opens the panel and nothing else; a password on the panel changes only with
 * an SMS to the admin's own phone — in test mode too.
 */
class AdminEmailSignInTest extends TestCase
{
    use FakesOtpChannel;
    use RefreshDatabase;

    private const PASSWORD = 'Cr7Delivery#2026';

    private RecordingOtpChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->channel = $this->fakeOtpChannel();
        // Test mode is on throughout: every assertion below that an SMS *was* sent is an
        // assertion that test mode did not reach an admin.
        $this->otpTestMode('123456');
        $this->withHeaders(['Accept-Language' => 'ar']);
    }

    private function admin(array $overrides = []): User
    {
        return User::factory()->create($overrides + [
            'type' => 'admin',
            'phone' => '01000000099',
            'email' => 'ops@cr7-delivery.com',
            'password' => Hash::make(self::PASSWORD),
        ]);
    }

    // --------------------------------------------------------------- sign-in

    public function test_an_admin_signs_in_with_an_email_however_it_is_typed(): void
    {
        $this->admin();

        $this->withHeader('X-App', 'admin')
            ->postJson('/api/v1/auth/login', ['login' => '  Ops@CR7-Delivery.com ', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.user.type', 'admin');

        // The phone keeps working — an admin row with no email must not be locked out.
        $this->withHeader('X-App', 'admin')
            ->postJson('/api/v1/auth/login', ['login' => '+20 100 000 0099', 'password' => self::PASSWORD])
            ->assertOk();

        // And the one app, which opens every shell, opens the admin's by email too.
        $this->withHeader('X-App', 'app')
            ->postJson('/api/v1/auth/login', ['login' => 'ops@cr7-delivery.com', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.user.type', 'admin');
    }

    public function test_an_email_opens_no_other_account(): void
    {
        $this->admin();

        User::factory()->create([
            'type' => 'restaurant', 'email' => 'owner@cr7-delivery.com',
            'password' => Hash::make(self::PASSWORD),
        ]);
        User::factory()->create([
            'type' => 'customer', 'email' => 'customer@cr7-delivery.com',
            'password' => Hash::make(self::PASSWORD),
        ]);

        // A place's own email on its own dashboard — the same refusal as a wrong password.
        $this->withHeader('X-App', 'restaurant')
            ->postJson('/api/v1/auth/login', ['login' => 'owner@cr7-delivery.com', 'password' => self::PASSWORD])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_credentials');

        $this->withHeader('X-App', 'app')
            ->postJson('/api/v1/auth/login', ['login' => 'customer@cr7-delivery.com', 'password' => self::PASSWORD])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_credentials');

        // The admin's email on the place's dashboard is the wrong shell, and says nothing more.
        $this->withHeader('X-App', 'restaurant')
            ->postJson('/api/v1/auth/login', ['login' => 'ops@cr7-delivery.com', 'password' => self::PASSWORD])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_credentials')
            ->assertJsonMissingPath('meta.account_type');
    }

    public function test_something_that_is_neither_is_refused_by_the_field(): void
    {
        $this->withHeader('X-App', 'admin')
            ->postJson('/api/v1/auth/login', ['login' => 'ops@', 'password' => self::PASSWORD])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonPath('errors.login.0', 'أدخل بريدًا إلكترونيًا صحيحًا');

        $this->withHeader('X-App', 'admin')
            ->postJson('/api/v1/auth/login', ['login' => '', 'password' => self::PASSWORD])
            ->assertStatus(422)
            ->assertJsonPath('errors.login.0', 'أدخل البريد الإلكتروني أو رقم الهاتف');
    }

    // --------------------------------------------------------- forgot / reset

    public function test_forgot_by_email_sends_a_real_sms_to_the_admins_phone_and_says_which(): void
    {
        $admin = $this->admin();

        $this->withHeader('X-App', 'admin')
            ->postJson('/api/v1/auth/password/forgot', ['login' => 'ops@cr7-delivery.com'])
            ->assertOk()
            ->assertJsonPath('data.masked_phone', '010•••••99');

        $this->assertCount(1, $this->channel->sent);
        $code = $this->channel->lastCodeFor($admin->phone);
        $this->assertNotSame('123456', $code);

        $this->withHeader('X-App', 'admin')
            ->postJson('/api/v1/auth/password/reset', [
                'login' => 'ops@cr7-delivery.com',
                'code' => $code,
                'password' => 'Brand#New2026',
                'password_confirmation' => 'Brand#New2026',
            ])->assertOk();

        $this->withHeader('X-App', 'admin')
            ->postJson('/api/v1/auth/login', ['login' => 'ops@cr7-delivery.com', 'password' => 'Brand#New2026'])
            ->assertOk();
    }

    public function test_forgot_for_an_unknown_email_answers_the_same_and_sends_nothing(): void
    {
        $this->admin();
        User::factory()->create(['type' => 'restaurant', 'email' => 'owner@cr7-delivery.com']);

        foreach (['nobody@cr7-delivery.com', 'owner@cr7-delivery.com'] as $login) {
            $this->withHeader('X-App', 'admin')
                ->postJson('/api/v1/auth/password/forgot', ['login' => $login])
                ->assertOk()
                ->assertJsonMissingPath('data.masked_phone');
        }

        $this->assertSame([], $this->channel->sent);
        $this->assertSame(0, OtpCode::query()->count());
    }

    // -------------------------------------------------------- password change

    public function test_an_admin_changes_a_password_only_with_the_sms_code(): void
    {
        $admin = $this->admin(['must_change_password' => true]);
        Sanctum::actingAs($admin);
        $this->withHeader('X-App', 'admin');

        $change = fn (?string $code) => $this->postJson('/api/v1/auth/password/change', array_filter([
            'current_password' => self::PASSWORD,
            'password' => 'Brand#New2026',
            'password_confirmation' => 'Brand#New2026',
            'code' => $code,
        ]));

        // The forced first change is a change like any other.
        $change(null)
            ->assertStatus(422)
            ->assertJsonPath('error', 'otp_required')
            ->assertJsonPath('message', 'أرسل رمز التحقق إلى هاتفك أولًا');

        // A code nobody sent is the same sentence, not «wrong code».
        $change('111111')->assertStatus(422)->assertJsonPath('error', 'otp_required');

        $this->postJson('/api/v1/auth/password/change/send-code')
            ->assertOk()
            ->assertJsonPath('data.masked_phone', '010•••••99')
            ->assertJsonPath('data.resend_in', 60)
            ->assertJsonMissingPath('data.static_otp');

        // A real SMS with a random code, test mode or not.
        $this->assertCount(1, $this->channel->sent);
        $code = $this->channel->lastCodeFor($admin->phone);
        $this->assertNotSame('123456', $code);

        $change('123456')
            ->assertStatus(422)
            ->assertJsonPath('error', 'otp_invalid');
        $this->assertTrue($admin->fresh()->must_change_password);

        $change($code)->assertOk();

        $admin->refresh();
        $this->assertTrue(Hash::check('Brand#New2026', $admin->password));
        $this->assertFalse($admin->must_change_password);
    }

    public function test_everyone_else_changes_a_password_without_a_code(): void
    {
        $captain = User::factory()->create(['type' => 'driver', 'password' => Hash::make(self::PASSWORD)]);
        Sanctum::actingAs($captain);

        $this->withHeader('X-App', 'app')
            ->postJson('/api/v1/auth/password/change', [
                'current_password' => self::PASSWORD,
                'password' => 'Brand#New2026',
                'password_confirmation' => 'Brand#New2026',
                'code' => '999999',
            ])->assertOk();

        $this->assertSame([], $this->channel->sent);
    }

    /** The door when the gateway is down: a reset that does not strand the admin on a change screen. */
    public function test_the_console_reset_reaches_an_admin_by_email_and_can_skip_the_forced_change(): void
    {
        $admin = $this->admin();

        $this->artisan('cr7:reset-password', [
            'login' => 'OPS@cr7-delivery.com',
            '--password' => 'Console#Door2026',
            '--no-change-required' => true,
        ])->assertSuccessful();

        $admin->refresh();
        $this->assertTrue(Hash::check('Console#Door2026', $admin->password));
        $this->assertFalse($admin->must_change_password);
    }
}
