<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\Notification;
use App\Models\OtpBlock;
use App\Models\OtpCode;
use App\Models\SmsSend;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\OtpGuard;
use App\Support\OtpRequester;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesOtpChannel;
use Tests\Concerns\RecordingOtpChannel;
use Tests\TestCase;

/**
 * Batch 68 — «واعمل جد لل sms وكمان وقت لل resend وعدد محاولات معينه … وانك تعمل حظر
 * للموبايل لمده معينه … المهم تحافظ علي الفلوس».
 *
 * Every rule `OtpGuard` and `OtpBudget` enforce, each driven through the HTTP routes the
 * clients call, on a frozen clock so every `retry_after` is an exact number. Every refusal
 * is checked for both `retry_after` and `available_at` — see {@see self::assertRefused()}.
 */
class OtpAbuseTest extends TestCase
{
    use FakesOtpChannel;
    use RefreshDatabase;

    private RecordingOtpChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->channel = $this->fakeOtpChannel();
        $this->travelTo(Carbon::parse('2026-09-18 06:00:00'));
        $this->withHeaders(['X-App' => 'app', 'Accept-Language' => 'ar']);
    }

    private function send(string $phone, string $purpose = 'login'): TestResponse
    {
        return $this->postJson('/api/v1/auth/otp/send', ['phone' => $phone, 'purpose' => $purpose]);
    }

    private function verify(string $phone, string $code, string $purpose = 'login'): TestResponse
    {
        return $this->postJson('/api/v1/auth/otp/verify', ['phone' => $phone, 'code' => $code, 'purpose' => $purpose]);
    }

    private function forgot(string $phone): TestResponse
    {
        return $this->postJson('/api/v1/auth/password/forgot', ['login' => $phone]);
    }

    private function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    /** A refusal says when to come back twice — in seconds, and as a UTC moment. */
    private function assertRefused(TestResponse $response, int $status, string $error, int $retryAfter): TestResponse
    {
        $response
            ->assertStatus($status)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', $error)
            ->assertJsonPath('meta.retry_after', $retryAfter)
            ->assertJsonPath('meta.available_at', now()->addSeconds($retryAfter)->utc()->toIso8601String());

        return $response;
    }

    private function globalAdmin(string $phone = '01000000099'): User
    {
        $admin = User::factory()->create(['type' => 'admin', 'phone' => $phone, 'password' => Hash::make('Admin#2026pass')]);
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        return $admin;
    }

    // ----------------------------------------------------------- the escalating wait

    public function test_the_resend_wait_doubles_up_to_the_ceiling(): void
    {
        $this->otpTestMode();
        Settings::set('otp.max_per_hour', 20, 'otp');

        $this->send('01011122233')->assertOk()->assertJsonPath('data.resend_in', 60);

        $this->travel(59)->seconds();
        $this->assertRefused($this->send('01011122233'), 429, 'otp_resend_cooldown', 1);

        $this->travel(1)->seconds();
        $this->send('01011122233')->assertOk()->assertJsonPath('data.resend_in', 120);

        foreach ([[120, 240], [240, 480], [480, 900], [900, 900]] as [$wait, $next]) {
            $this->travel($wait - 1)->seconds();
            $this->assertRefused($this->send('01011122233'), 429, 'otp_resend_cooldown', 1);
            $this->travel(1)->seconds();
            $this->send('01011122233')->assertOk()->assertJsonPath('data.resend_in', $next);
        }

        $this->assertSame(6, SmsSend::query()->where('phone', '01011122233')->where('kind', 'test')->count());

        // Each purpose has its own wait: a sign-up code is not held up by the sign-in one.
        $this->send('01011122233', 'register')->assertOk()->assertJsonPath('data.resend_in', 60);
    }

    public function test_every_send_answers_the_same_shape(): void
    {
        $this->otpTestMode('654321');

        $this->send('01011122233')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'تم إرسال رمز التحقق',
                'data' => [
                    'resend_in' => 60,
                    'expires_in' => 300,
                    'code_length' => 6,
                    'max_attempts' => 5,
                    'static_otp' => '654321',
                ],
            ]);
    }

    public function test_a_correct_code_starts_the_wait_over(): void
    {
        $this->otpTestMode();

        $this->send('01011122233')->assertOk()->assertJsonPath('data.resend_in', 60);
        $this->travel(60)->seconds();
        $this->send('01011122233')->assertOk()->assertJsonPath('data.resend_in', 120);

        $this->verify('01011122233', '123456')->assertOk();

        // No wait at all, and the next wait is the first one again.
        $this->send('01011122233')->assertOk()->assertJsonPath('data.resend_in', 60);
    }

    public function test_only_the_latest_code_is_checked(): void
    {
        $this->otpSmsMode();

        $this->send('01011122233')->assertOk();
        $first = $this->channel->lastCodeFor('01011122233');
        $this->travel(60)->seconds();
        $this->send('01011122233')->assertOk();
        $second = $this->channel->lastCodeFor('01011122233');

        if ($first === $second) {
            $this->markTestSkipped('the two random codes collided');
        }

        // The first message still sits in the inbox, and it opens nothing.
        $this->verify('01011122233', $first)
            ->assertStatus(422)
            ->assertJsonPath('error', 'otp_invalid')
            ->assertJsonPath('meta.attempts_left', 4);

        $this->verify('01011122233', $second)->assertOk();

        // Once the latest is spent, an older unspent one does not come back to life.
        $this->verify('01011122233', $first)->assertStatus(422)->assertJsonPath('error', 'otp_invalid');
    }

    // ------------------------------------------------------------ per number: blocks

    public function test_the_request_past_the_daily_count_blocks_the_number(): void
    {
        $this->otpTestMode();
        Settings::set('otp.max_per_day', 3, 'otp');
        Settings::set('otp.resend_seconds', 30, 'otp');
        Settings::set('otp.resend_max_seconds', 60, 'otp');

        $this->send('01011122233')->assertOk();
        $this->travel(30)->seconds();
        $this->send('01011122233', 'register')->assertOk();
        $this->travel(30)->seconds();
        $this->send('01011122233')->assertOk();
        $this->travel(60)->seconds();

        $blocked = $this->assertRefused($this->send('01011122233'), 423, 'otp_phone_blocked', 86400);
        $blocked->assertJsonPath('meta.blocked_until', now()->addHours(24)->utc()->toIso8601String());

        $this->assertSame('daily_cap', OtpBlock::query()->where('phone', '01011122233')->value('reason'));

        // A blocked number checks nothing — not even the code it holds, which is right.
        $this->assertRefused($this->verify('01011122233', '123456'), 423, 'otp_phone_blocked', 86400);
        // …and every purpose is refused with it.
        $this->assertRefused($this->send('01011122233', 'reset'), 423, 'otp_phone_blocked', 86400);

        // It lifts on its own clock.
        $this->travel(24)->hours();
        $this->send('01011122233')->assertOk();
    }

    public function test_codes_guessed_dead_block_the_number(): void
    {
        $this->otpTestMode();
        Settings::set('otp.max_attempts', 3, 'otp');
        Settings::set('otp.max_failed_codes', 2, 'otp');

        $this->send('01011122233')->assertOk();

        $this->verify('01011122233', '000000')->assertStatus(422)->assertJsonPath('meta.attempts_left', 2);
        $this->verify('01011122233', '000000')->assertStatus(422)->assertJsonPath('meta.attempts_left', 1);

        // The guess that uses the last attempt: the code is dead, and a new one may be
        // asked for once the resend wait (60 s from the send) is over.
        $this->assertRefused($this->verify('01011122233', '000000'), 423, 'otp_attempts_exceeded', 60)
            ->assertJsonPath('meta.attempts_left', 0);
        $this->assertRefused($this->verify('01011122233', '123456'), 423, 'otp_attempts_exceeded', 60);

        $this->travel(60)->seconds();
        $this->send('01011122233')->assertOk();
        $this->verify('01011122233', '000000')->assertStatus(422);
        $this->verify('01011122233', '000000')->assertStatus(422);

        // The second dead code in 24 hours blocks the number.
        $this->assertRefused($this->verify('01011122233', '000000'), 423, 'otp_phone_blocked', 86400);
        $this->assertSame('burned_codes', OtpBlock::query()->where('phone', '01011122233')->value('reason'));
        $this->assertRefused($this->send('01011122233'), 423, 'otp_phone_blocked', 86400);
    }

    // ------------------------------------------------------- per address, per install

    public function test_one_address_is_capped_per_hour_and_per_day_and_blocks_nobody(): void
    {
        $this->otpTestMode();
        Settings::set('otp.ip_max_per_hour', 3, 'otp');
        Settings::set('otp.ip_max_per_day', 4, 'otp');

        $this->fromIp('10.0.0.1');
        foreach (['01011100001', '01011100002', '01011100003'] as $phone) {
            $this->send($phone)->assertOk();
        }

        $this->assertRefused($this->send('01011100004'), 429, 'otp_send_rate_limited', 3600);

        // Another address is another allowance.
        $this->fromIp('10.0.0.2')->send('01011100004')->assertOk();

        $this->travel(3600)->seconds();
        $this->fromIp('10.0.0.1')->send('01011100005')->assertOk();

        // Four in 24 hours from 10.0.0.1: the day is full until the first of them is a day old.
        $this->assertRefused($this->send('01011100006'), 429, 'otp_send_rate_limited', 86400 - 3600);

        $this->assertSame(0, OtpBlock::query()->count(), 'an address limit never blocks a number the caller does not own');
    }

    public function test_one_install_is_capped_per_day(): void
    {
        $this->otpTestMode();
        Settings::set('otp.device_max_per_day', 2, 'otp');
        $device = ['X-Device-Id' => '6f1c2b0e-8d8a-4b8e-9f3a-2d4c5e6f7a8b'];

        $this->withHeaders($device)->send('01011100001')->assertOk();
        $this->withHeaders($device)->send('01011100002')->assertOk();
        $this->assertRefused($this->withHeaders($device)->send('01011100003'), 429, 'otp_send_rate_limited', 86400);

        // Another install, and a request with no id at all, are not this one.
        $this->withHeaders(['X-Device-Id' => '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d'])->send('01011100003')->assertOk();
        $this->withHeaders(['X-Device-Id' => '!!'])->send('01011100004')->assertOk();

        $this->assertSame(
            ['6f1c2b0e-8d8a-4b8e-9f3a-2d4c5e6f7a8b', '6f1c2b0e-8d8a-4b8e-9f3a-2d4c5e6f7a8b', '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d', null],
            SmsSend::query()->orderBy('id')->pluck('device_id')->all(),
        );
    }

    /**
     * The dashboards' BFF calls from one address for every place owner and operator. With
     * the shared key it names the browser's address, and each browser has its own count;
     * without the key the header is ignored, so nobody can walk out of a bucket with it.
     */
    public function test_only_the_bff_can_name_the_browsers_address(): void
    {
        $this->otpTestMode();
        Settings::set('otp.ip_max_per_hour', 2, 'otp');
        $key = str_repeat('k', 40);
        config(['services.bff.key' => $key]);

        $viaBff = fn (string $browser) => $this->fromIp('10.9.9.9')->withHeaders([
            OtpRequester::BFF_KEY_HEADER => $key,
            OtpRequester::CLIENT_IP_HEADER => $browser,
        ]);

        $viaBff('41.1.1.1')->forgot('01011100001')->assertOk();
        $viaBff('41.1.1.1')->forgot('01011100002')->assertOk();
        $this->assertRefused($viaBff('41.1.1.1')->forgot('01011100003'), 429, 'otp_send_rate_limited', 3600);

        // The next operator on the same dashboard is not held by the stranger above.
        $viaBff('41.2.2.2')->forgot('01011100003')->assertOk();

        // Anybody else naming an address is counted by their own.
        $spoof = fn (string $claimed, string $presented) => $this->fromIp('5.5.5.5')->withHeaders([
            OtpRequester::BFF_KEY_HEADER => $presented,
            OtpRequester::CLIENT_IP_HEADER => $claimed,
        ]);
        $spoof('77.0.0.1', 'wrong')->forgot('01011100004')->assertOk();
        $spoof('77.0.0.2', '')->forgot('01011100005')->assertOk();
        $this->assertRefused($spoof('77.0.0.3', substr($key, 0, 39))->forgot('01011100006'), 429, 'otp_send_rate_limited', 3600);

        $this->assertSame(
            ['41.1.1.1', '41.1.1.1', '41.2.2.2', '5.5.5.5', '5.5.5.5'],
            SmsSend::query()->orderBy('id')->pluck('ip')->all(),
        );

        // A key shorter than 32 characters turns the header off altogether.
        config(['services.bff.key' => 'short']);
        $this->withHeaders([OtpRequester::BFF_KEY_HEADER => 'short', OtpRequester::CLIENT_IP_HEADER => '41.3.3.3']);
        $this->fromIp('10.9.9.9')->forgot('01011100007')->assertOk();
        $this->assertSame('10.9.9.9', SmsSend::query()->latest('id')->value('ip'));
    }

    public function test_an_ipv6_caller_is_counted_by_its_64(): void
    {
        $this->assertSame('2001:db8:1:2::/64', OtpRequester::bucket('2001:db8:1:2:aaaa:bbbb:cccc:1'));
        $this->assertSame('41.1.1.1', OtpRequester::bucket('41.1.1.1'));

        $this->otpTestMode();
        Settings::set('otp.ip_max_per_hour', 1, 'otp');

        $this->fromIp('2001:db8:1:2::1')->send('01011100001')->assertOk();
        $this->assertRefused($this->fromIp('2001:db8:1:2:ffff::9')->send('01011100002'), 429, 'otp_send_rate_limited', 3600);
        $this->fromIp('2001:db8:1:3::1')->send('01011100002')->assertOk();
    }

    public function test_a_number_that_is_not_an_egyptian_mobile_is_refused_before_anything_is_counted(): void
    {
        $this->otpTestMode();
        Settings::set('otp.ip_max_per_hour', 1, 'otp');

        foreach (['12345', '+44 7700 900123', '0101112223'] as $phone) {
            $this->send($phone)->assertStatus(422)->assertJsonPath('error', 'validation_failed')->assertJsonStructure(['errors' => ['phone']]);
        }
        $this->forgot('ops@cr7-delivery.com')->assertStatus(422)->assertJsonStructure(['errors' => ['login']]);

        $this->assertSame(0, SmsSend::query()->count());

        // The one allowance this address has is still whole.
        $this->send('01011122233')->assertOk();
    }

    // -------------------------------------------------------------- the budget

    public function test_the_hourly_budget_trips_the_breaker_except_for_the_operator(): void
    {
        $this->otpSmsMode();
        Settings::set('otp.hourly_budget', 2, 'otp');
        $admin = $this->globalAdmin();
        User::factory()->create(['type' => 'customer', 'phone' => '01011100009']);

        $this->send('01011100001')->assertOk();
        $this->send('01011100002')->assertOk();

        $this->assertRefused($this->send('01011100003'), 503, 'otp_unavailable', 3600);
        $this->assertRefused($this->send('01011100004'), 503, 'otp_unavailable', 3600);

        // Once, however many requests it refuses.
        $this->assertSame(1, Notification::query()->where('user_id', $admin->id)->where('type', 'sms.hourly_budget_reached_admin')->count());

        // The operator's own reset is never refused by it…
        $this->forgot($admin->phone)->assertOk();
        $this->assertNotNull($this->channel->lastCodeFor($admin->phone));

        // …while a customer's and a stranger's read the same 503.
        $this->assertRefused($this->forgot('01011100009'), 503, 'otp_unavailable', 3600);
        $this->assertRefused($this->forgot('01011100008'), 503, 'otp_unavailable', 3600);

        // Test-mode codes cost nothing, so the budget does not hold them.
        $this->otpTestMode();
        $this->send('01011100005')->assertOk();

        $this->assertCount(3, $this->channel->sent);
        $this->assertSame(3, SmsSend::query()->where('kind', 'real')->count());

        // An hour after the first paid message there is room again.
        $this->otpSmsMode();
        $this->travel(3600)->seconds();
        $this->send('01011100006')->assertOk();
    }

    public function test_the_daily_budget_warns_at_eighty_percent_once_and_reopens_at_midnight(): void
    {
        $this->otpSmsMode();
        Settings::set('otp.daily_budget', 5, 'otp');
        $admin = $this->globalAdmin();
        $warnings = fn () => Notification::query()->where('user_id', $admin->id)->where('type', 'sms.budget_warning_admin')->count();

        foreach (['01011100001', '01011100002', '01011100003'] as $phone) {
            $this->send($phone)->assertOk();
        }
        $this->assertSame(0, $warnings());

        $this->send('01011100004')->assertOk();
        $this->assertSame(1, $warnings(), 'four of five is 80%');

        $this->send('01011100005')->assertOk();
        $this->assertSame(1, $warnings(), 'the warning goes out once a day');

        $midnight = BusinessTime::now()->addDay()->startOfDay()->utc();
        $refused = $this->send('01011100006')->assertStatus(503)->assertJsonPath('error', 'otp_unavailable');
        $refused->assertJsonPath('meta.available_at', $midnight->toIso8601String())
            ->assertJsonPath('meta.retry_after', (int) now()->diffInSeconds($midnight, true));
        $this->send('01011100007')->assertStatus(503);

        $this->assertSame(1, Notification::query()->where('user_id', $admin->id)->where('type', 'sms.budget_reached_admin')->count());

        // A budget of zero stops every paid message but the operator's own.
        Settings::set('otp.daily_budget', 0, 'otp');
        $this->travelTo($midnight->copy()->addMinute());
        $this->send('01011100008')->assertStatus(503);
        $this->forgot($admin->phone)->assertOk();

        Settings::set('otp.daily_budget', 5, 'otp');
        $this->send('01011100008')->assertOk();
    }

    public function test_a_customers_password_change_code_is_not_a_way_around_the_breaker(): void
    {
        $this->otpSmsMode();
        Settings::set('otp.daily_budget', 0, 'otp');
        $customer = User::factory()->create(['type' => 'customer', 'phone' => '01011100001']);
        Sanctum::actingAs($customer);

        $this->assertRefused($this->postJson('/api/v1/auth/password/change/send-code'), 503, 'otp_unavailable', (int) now()->diffInSeconds(BusinessTime::now()->addDay()->startOfDay(), true));
    }

    // ------------------------------------------------------ the owner's own change

    /**
     * A stranger who knows the operator's number can run up its anonymous counts until it
     * is blocked. The operator's own password change — a live session and the current
     * password — must still work, the breaker notwithstanding.
     */
    public function test_a_block_a_stranger_caused_does_not_lock_the_operator_out_of_a_password_change(): void
    {
        $this->otpSmsMode();
        Settings::set('otp.daily_budget', 0, 'otp');
        $admin = $this->globalAdmin();
        OtpGuard::block($admin->phone, OtpBlock::REASON_DAILY_CAP, now()->addDay());

        $this->assertRefused($this->forgot($admin->phone), 423, 'otp_phone_blocked', 86400);

        Sanctum::actingAs($admin);
        $this->withHeader('X-App', 'admin');

        $this->postJson('/api/v1/auth/password/change/send-code')->assertOk()->assertJsonPath('data.resend_in', 60);
        $code = $this->channel->lastCodeFor($admin->phone);

        $this->postJson('/api/v1/auth/password/change', [
            'current_password' => 'Admin#2026pass',
            'password' => 'Brand#New2026',
            'password_confirmation' => 'Brand#New2026',
            'code' => $code,
        ])->assertOk();

        // The owner's wait is still the owner's: the correct code started it over, and a
        // second code straight after the next one is not free.
        $this->postJson('/api/v1/auth/password/change/send-code')->assertOk()->assertJsonPath('data.resend_in', 60);
        $this->assertRefused($this->postJson('/api/v1/auth/password/change/send-code'), 429, 'otp_resend_cooldown', 60)
            ->assertJsonMissingPath('meta.blocked_until');
    }

    // ------------------------------------------------------------- anti-enumeration

    /**
     * Twelve «نسيت كلمة المرور» requests for a registered number and twelve for a stranger,
     * on the same clock from two addresses: every status, error code, `meta` and `data`
     * is the same, through the escalating wait, the hourly count and the daily block.
     */
    public function test_forgot_password_reads_the_same_for_a_registered_number_and_a_stranger_for_twelve_requests(): void
    {
        $this->otpSmsMode();
        Settings::set('otp.max_per_day', 9, 'otp');
        User::factory()->create(['type' => 'customer', 'phone' => '01011100001']);

        $offsets = [0, 10, 60, 180, 420, 900, 1000, 3600, 4500, 5400, 6300, 7200];
        $start = now()->copy();

        $run = function (string $phone, string $ip) use ($offsets, $start): array {
            $answers = [];

            foreach ($offsets as $offset) {
                $this->travelTo($start->copy()->addSeconds($offset));
                $response = $this->fromIp($ip)->forgot($phone);
                $answers[] = [$offset, $response->status(), $response->json()];
            }

            return $answers;
        };

        $registered = $run('01011100001', '10.0.0.1');
        $stranger = $run('01011100002', '10.0.0.2');

        $this->assertSame($registered, $stranger);

        $this->assertSame(
            [200, 429, 200, 200, 200, 200, 429, 200, 200, 200, 200, 423],
            array_column($registered, 1),
        );
        $this->assertSame(
            [null, 'otp_resend_cooldown', null, null, null, null, 'otp_send_rate_limited', null, null, null, null, 'otp_phone_blocked'],
            array_map(fn (array $a) => $a[2]['error'] ?? null, $registered),
        );
        $this->assertSame(
            [60, null, 120, 240, 480, 2700, null, 900, 900, 900, 900, null],
            array_map(fn (array $a) => $a[2]['data']['resend_in'] ?? null, $registered),
        );

        // One real SMS per 200 for the registered number; nothing at all for the stranger.
        $this->assertCount(9, $this->channel->sent);
        $this->assertNull($this->channel->lastCodeFor('01011100002'));
    }

    public function test_a_reset_counts_attempts_the_same_for_a_registered_number_and_a_stranger(): void
    {
        $this->otpSmsMode();
        User::factory()->create(['type' => 'customer', 'phone' => '01011100001']);
        $start = now()->copy();

        $run = function (string $phone, string $ip) use ($start): array {
            $this->travelTo($start);
            $answers = [$this->fromIp($ip)->forgot($phone)->json()];

            foreach (range(1, 6) as $i) {
                $answers[] = $this->fromIp($ip)->postJson('/api/v1/auth/password/reset', [
                    'login' => $phone,
                    'code' => '000000',
                    'password' => 'Brand#New2026',
                    'password_confirmation' => 'Brand#New2026',
                ])->json();
            }

            return $answers;
        };

        $registered = $run('01011100001', '10.0.0.1');
        $stranger = $run('01011100002', '10.0.0.2');

        $this->assertSame($registered, $stranger);
        $this->assertSame(
            [null, 4, 3, 2, 1, 0, 0],
            array_map(fn (array $a) => $a['meta']['attempts_left'] ?? null, $registered),
        );
        $this->assertSame('otp_attempts_exceeded', $registered[5]['error']);
        $this->assertSame(60, $registered[5]['meta']['retry_after']);
    }

    // ---------------------------------------------------------------- verify side

    public function test_every_verify_route_is_throttled_and_says_when_to_come_back(): void
    {
        $customer = User::factory()->create(['type' => 'customer']);

        $last = null;
        for ($i = 0; $i < 11; $i++) {
            $last = $this->verify('01011122233', '000000');
        }
        $this->assertRefused($last, 429, 'otp_verify_rate_limited', (int) $last->json('meta.retry_after'));
        $this->assertGreaterThan(0, $last->json('meta.retry_after'));

        // The address's second number is checked the same way — it had no throttle at all.
        Sanctum::actingAs($customer);
        for ($i = 0; $i < 11; $i++) {
            $last = $this->postJson('/api/v1/addresses/phone/verify', ['phone' => '01090009599', 'code' => '000000']);
        }
        $last->assertStatus(429)->assertJsonPath('error', 'otp_verify_rate_limited')->assertJsonStructure(['meta' => ['retry_after', 'available_at']]);
    }

    public function test_an_expired_code_says_when_a_new_one_may_be_sent(): void
    {
        $this->otpTestMode();
        $this->send('01011122233')->assertOk();
        $this->travel(301)->seconds();

        $this->verify('01011122233', '123456')
            ->assertStatus(422)
            ->assertJsonPath('error', 'otp_expired')
            ->assertJsonPath('meta.retry_after', 0);
    }

    // --------------------------------------------------------------------- /config

    public function test_config_publishes_what_a_code_screen_draws_before_its_first_send(): void
    {
        $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('data.otp', ['resend_seconds' => 60, 'code_length' => 6, 'max_attempts' => 5]);

        Settings::set('otp.resend_seconds', 90, 'otp');
        Settings::set('otp.max_attempts', 3, 'otp');

        $this->getJson('/api/v1/config')
            ->assertJsonPath('data.otp', ['resend_seconds' => 90, 'code_length' => 6, 'max_attempts' => 3]);
    }

    // ----------------------------------------------------------------------- prune

    public function test_the_prune_keeps_what_any_rule_still_counts(): void
    {
        $this->otpTestMode();
        $this->send('01011122233')->assertOk();

        $this->travel(31)->days();
        OtpGuard::block('01011100001', OtpBlock::REASON_ADMIN, now()->addHour());
        $this->send('01011122244')->assertOk();

        $this->artisan('cr7:prune-otp')->assertSuccessful();

        $this->assertSame(['01011122244'], SmsSend::query()->pluck('phone')->all());
        $this->assertSame(['01011122244'], OtpCode::query()->pluck('phone')->all());
        $this->assertSame(1, OtpBlock::query()->count());
    }
}
