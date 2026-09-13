<?php

namespace Tests\Feature;

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * «بيانات الدخول غير صحيحة» for the right number and the right password.
 *
 * Normalisation had been added on the way *in* — an admin creating a captain, an
 * applicant filing an application — but every lookup still compared the raw string.
 * `+20 109 000 9599` is the number that is stored as `01090009599`, and none of
 * sign-in, forgot-password or OTP could see that.
 *
 * The OTP case is worse than a refusal: the number missed the existing row and
 * `VerifyOtpAction` created a *second* customer account for a phone that already
 * had one — the duplicate the uniqueness work was supposed to have ended.
 */
class LoginPhoneNormalisationTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{string}> */
    public static function spellings(): array
    {
        return [['01090009599'], ['+20 109 000 9599'], ['0020109 000 9599'], ['1090009599'], ['٠١٠٩٠٠٠٩٥٩٩'], [' 010-9000-9599 ']];
    }

    #[DataProvider('spellings')]
    public function test_a_captain_signs_in_with_their_number_however_they_write_it(string $typed): void
    {
        User::factory()->create([
            'phone' => '01090009599',
            'type' => 'driver',
            'password' => Hash::make('123456789'),
            'is_active' => true,
        ]);

        $this->withHeaders(['X-App' => 'app'])
            ->postJson('/api/v1/auth/login', ['login' => $typed, 'password' => '123456789'])
            ->assertOk()
            ->assertJsonPath('data.user.type', 'driver');
    }

    public function test_a_wrong_password_is_still_a_wrong_password(): void
    {
        User::factory()->create([
            'phone' => '01090009599',
            'type' => 'driver',
            'password' => Hash::make('123456789'),
            'is_active' => true,
        ]);

        // Normalising must widen how a number may be *written*, never what may open it.
        $this->withHeaders(['X-App' => 'app'])
            ->postJson('/api/v1/auth/login', ['login' => '+20 109 000 9599', 'password' => 'not-it'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_credentials');
    }

    /**
     * D-78, operator 2026-09-11: «شيل تسجيل الدخول بالايميل» — and D-107 brought the email
     * back for admins only. A place's email is still no way into the place's dashboard:
     * it is refused like a wrong password, because the lookup reaches admins and nobody
     * else (`AdminEmailSignInTest` has the admin's half).
     */
    public function test_an_email_is_no_login_for_a_place(): void
    {
        User::factory()->create([
            'email' => 'owner@cr7-delivery.com',
            'phone' => '01020508483',
            'type' => 'restaurant',
            'password' => Hash::make('Cr7Delivery#2026'),
            'is_active' => true,
        ]);

        $this->withHeaders(['X-App' => 'restaurant'])
            ->postJson('/api/v1/auth/login', [
                'login' => ' owner@cr7-delivery.com ',
                'password' => 'Cr7Delivery#2026',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_credentials');

        // Neither a phone nor an email is still refused by the field, with the field named.
        $this->withHeaders(['X-App' => 'restaurant', 'Accept-Language' => 'ar'])
            ->postJson('/api/v1/auth/login', [
                'login' => '12-ab',
                'password' => 'Cr7Delivery#2026',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonPath('errors.login.0', 'أدخل رقم هاتف صحيح');

        $this->withHeaders(['X-App' => 'restaurant'])
            ->postJson('/api/v1/auth/login', [
                'login' => '01020508483',
                'password' => 'Cr7Delivery#2026',
            ])
            ->assertOk();
    }

    public function test_one_number_written_two_ways_is_one_customer(): void
    {
        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01090009599', 'purpose' => 'login'])
            ->assertOk();

        // The 60s resend cooldown is keyed on the phone, so hitting it from a differently
        // written spelling of the same number *is* the proof both landed on one row.
        // Unnormalised, this second call was a clean 200 and a second code — and the
        // verification behind it mints a duplicate account for a number that has one.
        $this->postJson('/api/v1/auth/otp/send', ['phone' => '+20 109 000 9599', 'purpose' => 'login'])
            ->assertStatus(429)
            ->assertJsonPath('error', 'otp_resend_cooldown');

        $this->assertSame(['01090009599'], OtpCode::query()->pluck('phone')->unique()->values()->all());
    }

    public function test_forgot_password_finds_the_account_behind_a_formatted_number(): void
    {
        User::factory()->create([
            'phone' => '01090009599',
            'type' => 'driver',
            'password' => Hash::make('123456789'),
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/auth/password/forgot', ['login' => '+20 109 000 9599'])
            ->assertOk();

        // Existence is never leaked, so the response is 200 either way (doc 08 §1) — the
        // only way to tell a found account from a missed one is that a code was issued.
        $this->assertDatabaseHas('otp_codes', ['phone' => '01090009599', 'purpose' => 'reset']);
    }
}
