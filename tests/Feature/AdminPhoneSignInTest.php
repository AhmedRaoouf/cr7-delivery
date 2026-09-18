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
 * An admin signs in by phone, like every other account — operator 2026-09-17: «مفيش تسجيل
 * بايميل خالص كله برقم الموبايل». D-107 had let an email open the panel; that half is gone,
 * and an email typed into `login` is refused by the field as a phone that is not one, on
 * every shell and on «نسيت كلمة المرور» and the reset that follows it.
 *
 * D-107's other half stands: «لو عايز يغير كلمه السر بقا تبقا ب sms … ويبقا دا الوحيد اللي
 * مفيش فيه غير sms في اي حاله حتي لو هو ثابت للكل» — a password on the panel changes only
 * with an SMS to the admin's own phone, in test mode too.
 */
class AdminPhoneSignInTest extends TestCase
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

    public function test_an_admin_signs_in_with_the_phone_however_it_is_typed(): void
    {
        $this->admin();

        foreach (['01000000099', '+20 100 000 0099', ' 0100-000-0099 '] as $typed) {
            $this->withHeader('X-App', 'admin')
                ->postJson('/api/v1/auth/login', ['login' => $typed, 'password' => self::PASSWORD])
                ->assertOk()
                ->assertJsonPath('data.user.type', 'admin');
        }

        // The one app, which opens every shell, opens the admin's by the same phone.
        $this->withHeader('X-App', 'app')
            ->postJson('/api/v1/auth/login', ['login' => '01000000099', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.user.type', 'admin');
    }

    public function test_an_admins_email_is_refused_as_a_phone_that_is_not_one_on_every_shell(): void
    {
        $this->admin();

        foreach (['admin', 'app', 'restaurant'] as $shell) {
            foreach (['ops@cr7-delivery.com', '  Ops@CR7-Delivery.com ', 'ops@', 'ops@example.com'] as $typed) {
                $this->withHeader('X-App', $shell)
                    ->postJson('/api/v1/auth/login', ['login' => $typed, 'password' => self::PASSWORD])
                    ->assertStatus(422)
                    ->assertJsonPath('error', 'validation_failed')
                    ->assertJsonPath('errors.login.0', 'أدخل رقم هاتف صحيح')
                    ->assertJsonMissingPath('data.user');
            }
        }
    }

    public function test_an_email_wrapped_around_the_admins_digits_is_not_the_admins_phone(): void
    {
        $this->admin();

        $this->withHeader('X-App', 'admin')
            ->postJson('/api/v1/auth/login', ['login' => '01000000099@cr7-delivery.com', 'password' => self::PASSWORD])
            ->assertStatus(422)
            ->assertJsonPath('errors.login.0', 'أدخل رقم هاتف صحيح');
    }

    public function test_the_empty_field_asks_for_the_phone_on_the_panel_too(): void
    {
        foreach (['admin', 'restaurant', 'app'] as $shell) {
            $this->withHeader('X-App', $shell)
                ->postJson('/api/v1/auth/login', ['login' => '', 'password' => self::PASSWORD])
                ->assertStatus(422)
                ->assertJsonPath('errors.login.0', 'أدخل رقم الهاتف');
        }
    }

    // --------------------------------------------------------- forgot / reset

    public function test_forgot_by_phone_sends_a_real_sms_to_the_admin_and_masks_nothing(): void
    {
        $admin = $this->admin();

        $this->withHeader('X-App', 'admin')
            ->postJson('/api/v1/auth/password/forgot', ['login' => '+20 100 000 0099'])
            ->assertOk()
            // Batch 68 — the send shape, the same for every number; never an echoed code.
            ->assertJsonPath('data.static_otp', null)
            ->assertJsonPath('data.code_length', 6)
            ->assertJsonMissingPath('data.masked_phone');

        $this->assertCount(1, $this->channel->sent);
        $code = $this->channel->lastCodeFor($admin->phone);
        $this->assertNotSame('123456', $code);

        $this->withHeader('X-App', 'admin')
            ->postJson('/api/v1/auth/password/reset', [
                'login' => '01000000099',
                'code' => $code,
                'password' => 'Brand#New2026',
                'password_confirmation' => 'Brand#New2026',
            ])->assertOk();

        $this->withHeader('X-App', 'admin')
            ->postJson('/api/v1/auth/login', ['login' => '01000000099', 'password' => 'Brand#New2026'])
            ->assertOk();
    }

    public function test_forgot_and_reset_refuse_an_email_and_send_nothing(): void
    {
        $admin = $this->admin();

        foreach (['ops@cr7-delivery.com', 'nobody@cr7-delivery.com'] as $login) {
            $this->withHeader('X-App', 'admin')
                ->postJson('/api/v1/auth/password/forgot', ['login' => $login])
                ->assertStatus(422)
                ->assertJsonPath('error', 'validation_failed')
                ->assertJsonPath('errors.login.0', 'أدخل رقم هاتف صحيح');
        }

        $this->assertSame([], $this->channel->sent);
        $this->assertSame(0, OtpCode::query()->count());

        // A code that did reach the phone does not reset the password by the email either.
        $this->withHeader('X-App', 'admin')
            ->postJson('/api/v1/auth/password/forgot', ['login' => '01000000099'])
            ->assertOk();
        $code = $this->channel->lastCodeFor($admin->phone);

        $this->withHeader('X-App', 'admin')
            ->postJson('/api/v1/auth/password/reset', [
                'login' => 'ops@cr7-delivery.com',
                'code' => $code,
                'password' => 'Brand#New2026',
                'password_confirmation' => 'Brand#New2026',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.login.0', 'أدخل رقم هاتف صحيح');

        $this->assertTrue(Hash::check(self::PASSWORD, $admin->fresh()->password));
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
            // Batch 68 — the send shape everywhere; a password-change code is never the
            // test code, so the echo is always null.
            ->assertJsonPath('data.static_otp', null)
            ->assertJsonPath('data.max_attempts', 5);

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
    public function test_the_console_reset_reaches_an_admin_by_phone_and_can_skip_the_forced_change(): void
    {
        $admin = $this->admin();

        $this->artisan('cr7:reset-password', [
            'login' => '+20 100 000 0099',
            '--password' => 'Console#Door2026',
            '--no-change-required' => true,
        ])->assertSuccessful();

        $admin->refresh();
        $this->assertTrue(Hash::check('Console#Door2026', $admin->password));
        $this->assertFalse($admin->must_change_password);
    }

    public function test_the_console_reset_finds_nobody_by_email(): void
    {
        $admin = $this->admin();

        $this->artisan('cr7:reset-password', [
            'login' => 'ops@cr7-delivery.com',
            '--password' => 'Console#Door2026',
        ])->assertFailed();

        $this->assertTrue(Hash::check(self::PASSWORD, $admin->fresh()->password));
    }
}
