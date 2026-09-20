<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\Driver;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesOtpChannel;
use Tests\TestCase;

/**
 * «إنشاء حساب» — and the two things it is not allowed to do.
 *
 * Operator, 2026-09-07: «مش عايزه يختار هو customer ولا driver… هو يخش يكتب الرقم
 * والباسورد، فهيتعرف من الباك إن ده driver، فيدخله على وجهة الـdriver على طول». One login
 * screen, one phone, one role — «مفيش نفس الرقم يبقى driver وcustomer».
 */
class CustomerRegistrationTest extends TestCase
{
    use FakesOtpChannel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The operator's test code (D-106), so the test types what a tester types.
        $this->otpTestMode('123456');
        // Symfony's test request says `Accept-Language: en-us` unless told otherwise,
        // and the sentences asserted below have English entries in `lang/en.json`.
        $this->withHeaders(['X-App' => 'app', 'Accept-Language' => 'ar']);
    }

    private function verifyForRegistration(string $phone = '01011122233'): string
    {
        $this->postJson('/api/v1/auth/otp/send', ['phone' => $phone, 'purpose' => 'register'])->assertOk();

        $verified = $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => $phone,
            'code' => '123456',
            'purpose' => 'register',
        ]);

        $verified->assertOk();

        // No account, no session — just proof the phone answered.
        $this->assertNull($verified->json('data.token'));
        $this->assertNull(User::query()->where('phone', $phone)->first());

        return $verified->json('data.registration_token');
    }

    public function test_a_new_customer_verifies_a_code_and_then_chooses_a_password(): void
    {
        $token = $this->verifyForRegistration();

        $created = $this->postJson('/api/v1/auth/register', [
            'registration_token' => $token,
            'name' => 'أحمد الشناوي',
            'password' => 'Ahmed@123',
        ]);

        $created->assertCreated();
        $this->assertNotEmpty($created->json('data.token'));
        $this->assertSame('customer', $created->json('data.user.type'));

        $user = User::query()->where('phone', '01011122233')->firstOrFail();
        $this->assertSame(UserType::Customer, $user->type);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertFalse($user->must_change_password);

        // …and that password is the one that signs them in from now on.
        $this->postJson('/api/v1/auth/login', [
            'login' => '01011122233',
            'password' => 'Ahmed@123',
        ])->assertOk()->assertJsonPath('data.user.type', 'customer');
    }

    public function test_a_registration_token_is_spent_once(): void
    {
        $token = $this->verifyForRegistration();

        $this->postJson('/api/v1/auth/register', [
            'registration_token' => $token,
            'name' => 'أحمد',
            'password' => 'Ahmed@123',
        ])->assertCreated();

        // The double tap on a slow button. Without the token being destroyed inside the
        // same transaction that creates the user, this is a 500 on `users.phone`.
        $this->postJson('/api/v1/auth/register', [
            'registration_token' => $token,
            'name' => 'أحمد',
            'password' => 'Ahmed@123',
        ])->assertStatus(422)->assertJsonPath('error', 'registration_expired');

        $this->assertSame(1, User::query()->where('phone', '01011122233')->count());
    }

    public function test_a_phone_that_is_already_a_captain_is_never_sent_a_sign_up_code(): void
    {
        $captain = User::factory()->create(['phone' => '01011122233', 'type' => 'driver']);

        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01011122233', 'purpose' => 'register'])
            ->assertStatus(409)
            ->assertJsonPath('error', 'phone_taken')
            ->assertJsonPath('message', 'هذا الرقم مسجل بالفعل — سجّل الدخول بكلمة المرور')
            // It used to name the account's type — «مسجل كحساب كابتن» and
            // `meta.account_type: driver` — which told anyone holding a number whether
            // it was a captain's, a place's or an admin's.
            ->assertJsonMissingPath('meta.account_type');

        $this->assertSame(1, User::query()->where('phone', $captain->phone)->count());
    }

    public function test_a_registered_number_costs_no_code_no_row_and_no_budget_in_any_shape(): void
    {
        $channel = $this->fakeOtpChannel();
        User::factory()->create(['phone' => '01011122233', 'type' => 'customer']);

        foreach (['01011122233', '+201011122233', '201011122233', '+20 101 112 2233'] as $typed) {
            $this->postJson('/api/v1/auth/otp/send', ['phone' => $typed, 'purpose' => 'register'])
                ->assertStatus(409)
                ->assertJsonPath('error', 'phone_taken');
        }

        $this->assertSame([], $channel->sent);
        $this->assertSame(0, \App\Models\OtpCode::query()->where('phone', '01011122233')->count());
        $this->assertSame(0, \App\Models\SmsSend::query()->where('phone', '01011122233')->count());
    }

    public function test_a_registered_number_still_signs_in_and_resets_by_code(): void
    {
        User::factory()->create(['phone' => '01011122233', 'type' => 'customer']);

        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01011122233', 'purpose' => 'login'])->assertOk();
        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01011122233', 'purpose' => 'reset'])->assertOk();
    }

    /**
     * D-106 — `auth.register_requires_otp` off: phone, name and password in one request,
     * for the weeks the Sender ID is not approved and no code can be delivered.
     */
    public function test_with_the_code_switched_off_a_customer_signs_up_in_one_request(): void
    {
        $channel = $this->fakeOtpChannel();
        Settings::set('auth.register_requires_otp', false, 'otp');

        $created = $this->postJson('/api/v1/auth/register', [
            'phone' => '+20 101 112 2233',
            'name' => 'أحمد الشناوي',
            'password' => 'Ahmed@123',
        ]);

        $created->assertCreated()->assertJsonPath('data.user.type', 'customer');
        $this->assertNotEmpty($created->json('data.token'));

        $user = User::query()->where('phone', '01011122233')->firstOrFail();
        // Nothing proved the number, and the row says so.
        $this->assertNull($user->phone_verified_at);
        $this->assertSame([], $channel->sent);

        // A number that already has an account is refused the same way as on the code path.
        $this->postJson('/api/v1/auth/register', [
            'phone' => '01011122233',
            'name' => 'أحمد',
            'password' => 'Ahmed@123',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error', 'phone_taken')
            ->assertJsonMissingPath('meta.account_type');

        $this->assertSame(1, User::query()->where('phone', '01011122233')->count());
    }

    public function test_with_the_code_switched_on_a_sign_up_without_a_token_is_refused(): void
    {
        Settings::set('auth.register_requires_otp', true, 'otp');

        $this->postJson('/api/v1/auth/register', [
            'phone' => '01011122233',
            'name' => 'أحمد',
            'password' => 'Ahmed@123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'otp_required')
            ->assertJsonPath('message', 'يلزم تأكيد رقم الهاتف أولًا');

        $this->assertNull(User::query()->where('phone', '01011122233')->first());
    }

    public function test_the_code_less_sign_up_still_needs_a_phone(): void
    {
        Settings::set('auth.register_requires_otp', false, 'otp');

        $this->postJson('/api/v1/auth/register', ['name' => 'أحمد', 'password' => 'Ahmed@123'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonPath('errors.phone.0', 'أدخل رقم الهاتف');
    }

    /** A token is honoured whatever the switch says — the app verified before it moved. */
    public function test_a_registration_token_still_works_with_the_code_switched_off(): void
    {
        $token = $this->verifyForRegistration();
        Settings::set('auth.register_requires_otp', false, 'otp');

        $this->postJson('/api/v1/auth/register', [
            'registration_token' => $token,
            'name' => 'أحمد',
            'password' => 'Ahmed@123',
        ])->assertCreated();

        $this->assertNotNull(User::query()->where('phone', '01011122233')->value('phone_verified_at'));
    }

    public function test_the_otp_login_path_still_names_the_account_type_it_refuses(): void
    {
        User::factory()->create(['phone' => '01011122233', 'type' => 'driver']);

        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01011122233', 'purpose' => 'login'])->assertOk();

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '01011122233', 'code' => '123456', 'purpose' => 'login'])
            ->assertStatus(403)
            ->assertJsonPath('error', 'wrong_client')
            ->assertJsonPath('meta.account_type', 'driver');
    }

    /**
     * One login screen, two shells.
     *
     * `X-App: app` used to mean «driver», so a customer's own password was answered
     * `wrong_client` by the only login form the app has.
     */
    public function test_one_login_endpoint_answers_for_both_shells(): void
    {
        $this->seedLocation();

        $customer = User::factory()->create([
            'phone' => '01011122233', 'type' => 'customer',
            'password' => bcrypt('Ahmed@123'),
        ]);
        $captainUser = User::factory()->create([
            'phone' => '01011122244', 'type' => 'driver',
            'password' => bcrypt('Ahmed@123'),
        ]);
        Driver::query()->create([
            'user_id' => $captainUser->id,
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'earning_type' => 'fixed',
            'earning_value' => 15,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/auth/login', ['login' => $customer->phone, 'password' => 'Ahmed@123'])
            ->assertOk()->assertJsonPath('data.user.type', 'customer');

        $this->postJson('/api/v1/auth/login', ['login' => $captainUser->phone, 'password' => 'Ahmed@123'])
            ->assertOk()->assertJsonPath('data.user.type', 'driver');

        // The dashboards are unchanged in what they refuse, and changed in what they
        // say: D-78 makes the wrong shell answer exactly like a wrong password, so an
        // anonymous caller cannot learn from the dashboard that this number is a real
        // account somewhere else.
        $this->withHeader('X-App', 'admin')
            ->postJson('/api/v1/auth/login', ['login' => $captainUser->phone, 'password' => 'Ahmed@123'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_credentials')
            ->assertJsonMissingPath('meta.account_type');
    }

    /**
     * `must_change_password` was a formality.
     *
     * An admin-created captain could satisfy the forced change by retyping the password
     * the admin had just read to them over WhatsApp, and the credential stayed live.
     */
    public function test_the_new_password_may_not_be_the_old_one(): void
    {
        $user = User::factory()->create([
            'type' => 'driver',
            'password' => bcrypt('Ahmed@123'),
            'must_change_password' => true,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/password/change', [
            'current_password' => 'Ahmed@123',
            'password' => 'Ahmed@123',
            'password_confirmation' => 'Ahmed@123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'password_unchanged');

        $this->assertTrue($user->fresh()->must_change_password);

        $this->postJson('/api/v1/auth/password/change', [
            'current_password' => 'Ahmed@123',
            'password' => 'Ahmed@1234',
            'password_confirmation' => 'Ahmed@1234',
        ])->assertOk();

        $this->assertFalse($user->fresh()->must_change_password);
    }

    /**
     * The accounts that existed before passwords did.
     *
     * `ResetPasswordAction` refused any account with no password — which was every
     * customer on the platform, i.e. exactly the people who needed to set one.
     */
    public function test_a_legacy_otp_only_customer_can_set_a_first_password(): void
    {
        $user = User::factory()->create(['phone' => '01011122233', 'type' => 'customer', 'password' => null]);

        $this->postJson('/api/v1/auth/password/forgot', ['login' => $user->phone])->assertOk();

        $this->postJson('/api/v1/auth/password/reset', [
            'login' => $user->phone,
            'code' => '123456',
            'password' => 'Ahmed@123',
            'password_confirmation' => 'Ahmed@123',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', ['login' => $user->phone, 'password' => 'Ahmed@123'])
            ->assertOk()->assertJsonPath('data.user.type', 'customer');
    }
}
