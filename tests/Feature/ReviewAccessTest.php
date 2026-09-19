<?php

namespace Tests\Feature;

use App\Enums\OtpPurpose;
use App\Enums\UserType;
use App\Models\Driver;
use App\Models\OtpCode;
use App\Models\Restaurant;
use App\Models\SmsSend;
use App\Models\User;
use App\Support\OtpBudget;
use App\Support\ReviewAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\FakesOtpChannel;
use Tests\TestCase;

/**
 * Store review access — a closed list of phones answered one fixed code, and nobody else.
 *
 * The half a response cannot show is whether the **gateway was called** and whether the
 * platform's own sender budget was charged: ZADX bills per message, and a reviewer's number
 * must cost nothing. Everything is asserted against the recording channel and `sms_sends`.
 */
class ReviewAccessTest extends TestCase
{
    use FakesOtpChannel;
    use RefreshDatabase;

    private const CUSTOMER = '01000000201';

    private const CAPTAIN = '01000000202';

    private const PLACE = '01000000203';

    private const CODE = '424242';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-App' => 'app', 'Accept-Language' => 'ar']);
        $this->reviewOn();
    }

    private function reviewOn(): void
    {
        config([
            'services.review.phones' => self::CUSTOMER.', '.self::CAPTAIN.' ; +20 100 000 0203',
            'services.review.code' => self::CODE,
        ]);
    }

    public function test_a_review_number_gets_the_fixed_code_and_no_sms(): void
    {
        $channel = $this->fakeOtpChannel();
        $this->otpSmsMode();

        $this->postJson('/api/v1/auth/otp/send', ['phone' => self::CUSTOMER])
            ->assertOk()
            // Never echoed — unlike the operator's test code.
            ->assertJsonPath('data.static_otp', null);

        $this->assertSame([], $channel->sent, 'the gateway must not be called for a review number');
        $this->assertSame(SmsSend::KIND_REVIEW, SmsSend::query()->where('phone', self::CUSTOMER)->value('kind'));
        $this->assertSame(0, OtpBudget::sentToday(), 'a review code is not on the paid-send budget');
    }

    public function test_the_fixed_code_verifies_and_signs_a_review_customer_in(): void
    {
        $this->fakeOtpChannel();
        User::query()->create(['phone' => self::CUSTOMER, 'name' => 'مراجع', 'type' => UserType::Customer, 'password' => Hash::make('x'), 'is_active' => true]);

        $this->postJson('/api/v1/auth/otp/send', ['phone' => self::CUSTOMER])->assertOk();

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::CUSTOMER, 'code' => '000000'])->assertStatus(422);
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::CUSTOMER, 'code' => self::CODE])
            ->assertOk()
            ->assertJsonStructure(['data' => ['user', 'token']]);
    }

    public function test_a_review_number_can_finish_registration_with_the_fixed_code(): void
    {
        $this->fakeOtpChannel();

        $this->postJson('/api/v1/auth/otp/send', ['phone' => self::PLACE, 'purpose' => 'register'])->assertOk();

        $this->assertSame(SmsSend::KIND_REVIEW, SmsSend::query()->where('phone', self::PLACE)->value('kind'));
        $this->assertSame(1, OtpCode::query()->where('phone', self::PLACE)->count());
    }

    public function test_a_number_that_is_not_on_the_list_is_sent_a_real_random_code(): void
    {
        $channel = $this->fakeOtpChannel();
        $this->otpSmsMode();

        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01011122233'])->assertOk();

        $this->assertCount(1, $channel->sent);
        $this->assertNotSame(self::CODE, $channel->lastCodeFor('01011122233'));
        $this->assertSame(SmsSend::KIND_REAL, SmsSend::query()->where('phone', '01011122233')->value('kind'));
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '01011122233', 'code' => self::CODE])->assertStatus(422);
    }

    public function test_it_is_off_unless_both_the_list_and_a_six_digit_code_are_set(): void
    {
        $channel = $this->fakeOtpChannel();
        $this->otpSmsMode();

        foreach (['', '12345', '1234567', 'abcdef'] as $bad) {
            config(['services.review.code' => $bad]);
            $this->assertNull(ReviewAccess::codeFor(self::CUSTOMER, OtpPurpose::Login));
        }

        config(['services.review.code' => self::CODE, 'services.review.phones' => null]);
        $this->assertNull(ReviewAccess::codeFor(self::CUSTOMER, OtpPurpose::Login));

        $this->postJson('/api/v1/auth/otp/send', ['phone' => self::CUSTOMER])->assertOk();
        $this->assertCount(1, $channel->sent, 'with the feature off, the number is an ordinary one');
    }

    public function test_an_admin_is_never_given_the_fixed_code_even_on_the_list(): void
    {
        $channel = $this->fakeOtpChannel();
        $admin = User::query()->create(['phone' => self::CUSTOMER, 'name' => 'مدير', 'type' => UserType::Admin, 'password' => Hash::make('x'), 'is_active' => true]);

        $this->assertNull(ReviewAccess::codeFor(self::CUSTOMER, OtpPurpose::Reset, $admin));

        $this->postJson('/api/v1/auth/password/forgot', ['login' => self::CUSTOMER])->assertOk();

        $this->assertCount(1, $channel->sent, 'an admin\'s code is a real SMS');
        $this->assertNotSame(self::CODE, $channel->lastCodeFor(self::CUSTOMER));
    }

    public function test_a_password_change_and_an_address_number_are_never_covered(): void
    {
        $this->assertNull(ReviewAccess::codeFor(self::CUSTOMER, OtpPurpose::PasswordChange));
        $this->assertNull(ReviewAccess::codeFor(self::CUSTOMER, OtpPurpose::AddressPhone));
        $this->assertSame(self::CODE, ReviewAccess::codeFor(self::CUSTOMER, OtpPurpose::Reset));
    }

    public function test_the_list_is_normalised_and_forgiving_of_typing(): void
    {
        $this->assertSame([self::CUSTOMER, self::CAPTAIN, self::PLACE], ReviewAccess::phones());
        $this->assertTrue(ReviewAccess::isReviewPhone('+20 100 000 0201'));
        $this->assertFalse(ReviewAccess::isReviewPhone('01011122233'));

        config(['services.review.phones' => 'nonsense, 123, '.self::CUSTOMER.','.self::CUSTOMER]);
        $this->assertSame([self::CUSTOMER], ReviewAccess::phones());
    }

    public function test_the_log_says_review_and_never_carries_the_code_or_the_number(): void
    {
        $this->fakeOtpChannel();
        Log::spy();

        $this->postJson('/api/v1/auth/otp/send', ['phone' => self::CUSTOMER])->assertOk();

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []): bool {
            return str_contains($message, '[OTP][review]')
                && ! str_contains($message.json_encode($context), self::CODE)
                && ! str_contains($message.json_encode($context), self::CUSTOMER);
        })->once();
    }

    public function test_the_command_makes_three_accounts_that_no_customer_can_see_and_dispatch_never_uses(): void
    {
        $this->seedLocation();

        $this->artisan('cr7:review-accounts', ['--password' => 'short'])->assertFailed();
        $this->artisan('cr7:review-accounts', ['--password' => 'Review#Pass2026'])->assertSuccessful();
        $this->artisan('cr7:review-accounts', ['--password' => 'Review#Pass2026'])->assertSuccessful(); // idempotent

        $this->assertSame(1, User::query()->where('phone', self::CUSTOMER)->where('type', UserType::Customer)->count());
        $this->assertSame(1, User::query()->where('phone', self::CAPTAIN)->where('type', UserType::Driver)->count());
        $this->assertSame(1, User::query()->where('phone', self::PLACE)->where('type', UserType::Restaurant)->count());
        $this->assertSame(1, Driver::query()->count());
        $this->assertFalse(Driver::query()->first()->is_online);

        $place = Restaurant::query()->where('slug', 'store-review-demo-place')->firstOrFail();
        $this->assertFalse($place->is_active, 'the demo place must be invisible to customers');

        $this->getJson('/api/v1/restaurants')->assertOk()->assertJsonMissing(['slug' => 'store-review-demo-place']);
        $this->getJson('/api/v1/restaurants/'.$place->id)->assertNotFound();

        foreach ([self::CUSTOMER, self::CAPTAIN, self::PLACE] as $phone) {
            $this->postJson('/api/v1/auth/login', ['login' => $phone, 'password' => 'Review#Pass2026'])
                ->assertOk()
                ->assertJsonMissingPath('data.must_change_password');
        }

        // The captain is left out of every dispatch pool.
        $driver = Driver::query()->first();
        $driver->forceFill(['is_online' => true, 'is_available' => true])->save();
        $pool = Driver::query()->where('is_active', true)->where('is_online', true);
        ReviewAccess::exceptFromDrivers($pool);
        $this->assertSame(0, $pool->count());
    }

    public function test_the_command_refuses_to_touch_a_number_that_is_a_different_kind_of_account(): void
    {
        $this->seedLocation();
        User::query()->create(['phone' => self::CAPTAIN, 'name' => 'عميل حقيقي', 'type' => UserType::Customer, 'password' => Hash::make('x'), 'is_active' => true]);

        $this->expectException(\RuntimeException::class);
        Artisan::call('cr7:review-accounts', ['--password' => 'Review#Pass2026']);
    }

    public function test_the_command_never_resets_a_real_customers_password_on_a_listed_number(): void
    {
        $this->seedLocation();
        $real = User::query()->create(['phone' => self::CUSTOMER, 'name' => 'أحمد', 'type' => UserType::Customer, 'password' => Hash::make('theirs-1234'), 'is_active' => true]);

        try {
            Artisan::call('cr7:review-accounts', ['--password' => 'Review#Pass2026']);
            $this->fail('expected the command to refuse');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertTrue(Hash::check('theirs-1234', $real->fresh()->password));
    }

    public function test_the_command_refuses_when_the_allowlist_is_not_configured(): void
    {
        config(['services.review.phones' => null]);

        $this->artisan('cr7:review-accounts', ['--password' => 'Review#Pass2026'])->assertFailed();
    }
}
