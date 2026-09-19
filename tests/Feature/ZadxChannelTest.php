<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\OtpCode;
use App\Models\SmsSend;
use App\Providers\AppServiceProvider;
use App\Services\Otp\OtpChannel;
use App\Services\Otp\SmsGatewayStatus;
use App\Services\Otp\ZadxChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesOtpChannel;
use Tests\TestCase;

/**
 * ZADX as the OTP gateway.
 *
 * Per the standing rule: fake the response AND assert the request. A gateway that is
 * handed a body it refuses answers 4xx to a test double just as happily as it answers
 * 202, so every send below also pins what left — the two credential headers, the
 * idempotency key, the international number and the code — and the last group drives the
 * real endpoints with the real channel bound, to prove D-146/D-150's counters still see it.
 */
class ZadxChannelTest extends TestCase
{
    use FakesOtpChannel;
    use RefreshDatabase;

    private const SEND = 'https://smsapi.zadx.net/api/v1/otp/send';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.otp_channel' => 'zadx',
            'services.zadx.base_url' => 'https://smsapi.zadx.net/api/v1',
            'services.zadx.key' => 'pk_test_key',
            'services.zadx.secret' => 'sk_test_secret',
            'services.zadx.sender' => 'ZADX',
            'services.zadx.locale' => 'ar',
            'services.zadx.timeout' => 15,
        ]);
    }

    private function accepted(array $overrides = []): array
    {
        return $overrides + [
            'id' => 4127, 'status' => 'queued', 'to' => '+201012345678', 'sender_id' => 'ZADX',
            'encoding' => 'ucs2', 'segments' => 1, 'cost_credits' => 1, 'remaining_credits' => 49,
        ];
    }

    private function fault(string $code): array
    {
        return ['error' => ['code' => $code, 'message' => 'x']];
    }

    public function test_a_send_posts_the_code_a_sender_the_credentials_and_an_idempotency_key(): void
    {
        Http::fake([self::SEND => Http::response($this->accepted(), 202)]);

        (new ZadxChannel)->send('01012345678', '654321');

        Http::assertSent(function (Request $request) {
            $this->assertSame(self::SEND, $request->url());
            $this->assertSame('POST', $request->method());

            $this->assertSame(['pk_test_key'], $request->header('X-Api-Key'));
            $this->assertSame(['sk_test_secret'], $request->header('X-Api-Secret'));
            $this->assertMatchesRegularExpression('/^cr7-otp-[0-9a-f-]{36}$/', $request->header('Idempotency-Key')[0]);

            // The code, not a message: ZADX owns the OTP template.
            $this->assertSame('+201012345678', $request['to']);
            $this->assertSame('654321', $request['otp']);
            $this->assertSame('ZADX', $request['sender_id']);
            $this->assertSame('ar', $request['locale']);
            $this->assertArrayNotHasKey('message', $request->data());

            return true;
        });
    }

    /**
     * ZADX answers the same body under the same key with the original response, and a
     * different body with 409. Every code is a different body, so a key reused across two
     * sends would refuse the second — a customer asking for a new code would get nothing.
     */
    public function test_every_send_carries_its_own_idempotency_key(): void
    {
        Http::fake([self::SEND => Http::response($this->accepted(), 202)]);

        $channel = new ZadxChannel;
        $channel->send('01012345678', '111111');
        $channel->send('01012345678', '222222');

        $keys = Http::recorded()->map(fn (array $pair) => $pair[0]->header('Idempotency-Key')[0])->all();

        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1]);
    }

    /** @return array<string, array{string, int}> */
    public static function acceptedStatuses(): array
    {
        return [
            'queued, the 202 the docs describe' => ['queued', 202],
            'sent' => ['sent', 200],
            'delivered' => ['delivered', 200],
            'pending_verification, credits reserved and never resent' => ['pending_verification', 202],
        ];
    }

    #[DataProvider('acceptedStatuses')]
    public function test_a_message_zadx_took_is_a_success(string $status, int $http): void
    {
        Http::fake([self::SEND => Http::response($this->accepted(['status' => $status]), $http)]);

        (new ZadxChannel)->send('01012345678', '654321');

        Http::assertSentCount(1);
    }

    public function test_the_log_carries_the_message_id_and_never_the_code(): void
    {
        Log::spy();
        Http::fake([self::SEND => Http::response($this->accepted(), 202)]);

        (new ZadxChannel)->send('01012345678', '654321');

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) {
            return $message === 'zadx.response'
                && $context['message_id'] === 4127
                && $context['remaining_credits'] === 49
                && ! str_contains(json_encode($context), '654321');
        })->once();
    }

    public function test_a_nearly_empty_account_is_said_on_every_send(): void
    {
        Log::spy();
        Http::fake([self::SEND => Http::response($this->accepted(['remaining_credits' => 4]), 202)]);

        (new ZadxChannel)->send('01012345678', '654321');

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => $message === 'zadx.low_credits')->once();
    }

    /** @return array<string, array{int, string}> */
    public static function faults(): array
    {
        return [
            'wrong key' => [401, 'invalid_credentials'],
            'no plan' => [402, 'no_active_subscription'],
            'out of credits' => [402, 'quota_exhausted'],
            'suspended app' => [403, 'app_inactive'],
            'ip not allowed' => [403, 'ip_not_allowed'],
            'sender not assigned' => [403, 'sender_id_not_allowed'],
            'mode not allowed' => [403, 'mode_not_allowed'],
            'not a mobile number' => [422, 'invalid_phone'],
            'carrier refused' => [502, 'provider_failed'],
            'carrier down' => [502, 'provider_unavailable'],
        ];
    }

    #[DataProvider('faults')]
    public function test_a_documented_fault_is_a_503_and_is_named_in_the_log(int $http, string $code): void
    {
        Log::spy();
        Http::fake([self::SEND => Http::response($this->fault($code), $http)]);

        try {
            (new ZadxChannel)->send('01012345678', '654321');
            $this->fail('the fault was reported as a success');
        } catch (ApiException $e) {
            $this->assertSame('otp_send_failed', $e->errorCode);
            $this->assertSame(503, $e->status);
        }

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []) use ($code, $http) {
            return $message === 'zadx.send_failed'
                && $context['code'] === $code
                && $context['http'] === $http
                && $context['meaning'] !== 'undocumented code'
                && ! str_contains(json_encode($context), '654321');
        })->once();
    }

    public function test_a_200_whose_status_is_failed_is_not_a_success(): void
    {
        Http::fake([self::SEND => Http::response($this->accepted(['status' => 'failed']), 200)]);

        $this->expectException(ApiException::class);

        (new ZadxChannel)->send('01012345678', '654321');
    }

    public function test_a_body_that_is_not_json_is_a_failure(): void
    {
        Http::fake([self::SEND => Http::response('<html>bad gateway</html>', 200)]);

        $this->expectException(ApiException::class);

        (new ZadxChannel)->send('01012345678', '654321');
    }

    public function test_zadx_rate_limits_a_number_is_answered_as_crs_own_429_with_retry_after(): void
    {
        Carbon::setTestNow('2026-09-19 12:00:00');
        Http::fake([self::SEND => Http::response($this->fault('rate_limited_phone_minute'), 429, ['Retry-After' => '42'])]);

        try {
            (new ZadxChannel)->send('01012345678', '654321');
            $this->fail('the rate limit was reported as a success');
        } catch (ApiException $e) {
            $this->assertSame('otp_send_rate_limited', $e->errorCode);
            $this->assertSame(429, $e->status);
            $this->assertSame(42, $e->meta['retry_after']);
        }
    }

    public function test_an_unreachable_zadx_is_a_503_and_is_not_retried(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('cURL error 28: timed out');
        });

        try {
            (new ZadxChannel)->send('01012345678', '654321');
            $this->fail('a timeout was reported as a success');
        } catch (ApiException $e) {
            $this->assertSame('otp_send_failed', $e->errorCode);
        }

        // One attempt. A resend is exactly what ZADX's pending_verification warns against.
        $this->assertSame(1, $attempts);
    }

    public function test_a_code_zadx_would_refuse_never_leaves_the_server(): void
    {
        Http::fake();

        $this->expectException(ApiException::class);

        try {
            (new ZadxChannel)->send('01012345678', '12');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_the_channel_is_bound_by_otp_channel_zadx(): void
    {
        $provider = new AppServiceProvider($this->app);

        config(['services.otp_channel' => 'zadx']);
        $this->app->forgetInstance(OtpChannel::class);
        $provider->register();

        $this->assertInstanceOf(ZadxChannel::class, $this->app->make(OtpChannel::class));
    }

    // ---------------------------------------------------------- through the real endpoints

    private function useZadx(): void
    {
        $this->app->bind(OtpChannel::class, ZadxChannel::class);
        $this->otpSmsMode();
        Carbon::setTestNow('2026-09-19 06:00:00');
        $this->withHeaders(['X-App' => 'app', 'Accept-Language' => 'ar']);
    }

    public function test_a_real_sign_in_code_goes_to_zadx_and_is_counted_as_a_real_send(): void
    {
        $this->useZadx();
        Http::fake([self::SEND => Http::response($this->accepted(), 202)]);

        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01012345678', 'purpose' => 'login'])
            ->assertOk()
            ->assertJsonPath('data.resend_in', 60)
            ->assertJsonPath('data.code_length', 6);

        Http::assertSent(fn (Request $r) => $r->url() === self::SEND
            && $r['to'] === '+201012345678'
            && preg_match('/^\d{6}$/', $r['otp']) === 1);

        $this->assertSame(1, SmsSend::query()->where('phone', '01012345678')->where('kind', 'real')->count());
        $this->assertSame(1, OtpCode::query()->where('phone', '01012345678')->count());
    }

    /** The cooldown is CR7's, so ZADX is never asked for the second code inside the minute. */
    public function test_the_resend_cooldown_still_holds_in_front_of_zadx(): void
    {
        $this->useZadx();
        Http::fake([self::SEND => Http::response($this->accepted(), 202)]);

        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01012345678', 'purpose' => 'login'])->assertOk();

        $this->travel(30)->seconds();

        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01012345678', 'purpose' => 'login'])
            ->assertStatus(429)
            ->assertJsonPath('error', 'otp_resend_cooldown');

        Http::assertSentCount(1);
    }

    public function test_a_failed_send_is_still_counted_and_answers_503(): void
    {
        $this->useZadx();
        Http::fake([self::SEND => Http::response($this->fault('quota_exhausted'), 402)]);

        $this->postJson('/api/v1/auth/otp/send', ['phone' => '01012345678', 'purpose' => 'login'])
            ->assertStatus(503)
            ->assertJsonPath('error', 'otp_send_failed');

        // Recorded before the gateway is called: a broken account is not a way round the caps.
        $this->assertSame(1, SmsSend::query()->where('phone', '01012345678')->count());
    }

    /** A stranger's forgot-password runs every clock and reaches nothing (D-150). */
    public function test_a_shadow_code_for_an_unknown_number_never_reaches_zadx(): void
    {
        $this->useZadx();
        Http::fake();

        $this->postJson('/api/v1/auth/password/forgot', ['login' => '01099988877'])->assertOk();

        Http::assertNothingSent();
        $this->assertSame(1, SmsSend::query()->where('phone', '01099988877')->where('kind', 'shadow')->count());
    }

    // ------------------------------------------------------------------- the panel and the doctor

    public function test_the_gateway_panel_reads_zadx_credits(): void
    {
        Cache::forget('zadx:balance');
        Http::fake(['smsapi.zadx.net/api/v1/sms/balance' => Http::response([
            'has_active_plan' => true, 'plan' => '50 SMS', 'remaining_credits' => 37, 'quota' => 50, 'expires_at' => '2026-10-19T11:14:00+00:00',
        ])]);

        $report = app(SmsGatewayStatus::class)->report();

        $this->assertSame('zadx', $report['provider']);
        $this->assertSame('live', $report['environment']);
        $this->assertTrue($report['delivers']);
        $this->assertSame(37.0, $report['balance']);
        $this->assertStringNotContainsString('sk_test_secret', json_encode($report));

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->header('X-Api-Key') === ['pk_test_key']
            && $r->header('X-Api-Secret') === ['sk_test_secret']);
    }

    public function test_the_gateway_panel_says_when_the_secret_is_missing(): void
    {
        config(['services.zadx.secret' => null]);
        Http::fake();

        $report = app(SmsGatewayStatus::class)->report();

        $this->assertFalse($report['delivers']);
        $this->assertFalse($report['credentials_configured']);
        $this->assertNull($report['balance']);
        Http::assertNothingSent();
    }

    public function test_the_doctor_sends_through_zadx_while_sign_in_stays_on_the_log(): void
    {
        config(['services.otp_channel' => 'log']);
        Http::fake([
            'smsapi.zadx.net/api/v1/sms/balance' => Http::response(['has_active_plan' => true, 'plan' => '50 SMS', 'remaining_credits' => 50, 'quota' => 50]),
            self::SEND => Http::response($this->accepted(), 202),
        ]);

        $this->artisan('cr7:sms-doctor', ['phone' => '01012345678', '--provider' => 'zadx', '--force' => true])
            ->expectsOutputToContain('ZADX accepted it')
            ->doesntExpectOutputToContain('sk_test_secret')
            ->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->url() === self::SEND && $r['otp'] === '000000');
        $this->assertSame('log', config('services.otp_channel'));
    }

    public function test_a_read_only_doctor_run_never_touches_the_send_endpoint(): void
    {
        Http::fake(['smsapi.zadx.net/api/v1/sms/balance' => Http::response(['has_active_plan' => true, 'remaining_credits' => 50, 'quota' => 50])]);

        $this->artisan('cr7:sms-doctor')->assertSuccessful();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/otp/send'));
    }

    public function test_the_doctor_refuses_to_send_with_a_rejected_key(): void
    {
        Http::fake(['smsapi.zadx.net/api/v1/sms/balance' => Http::response($this->fault('invalid_credentials'), 401)]);

        $this->artisan('cr7:sms-doctor', ['phone' => '01012345678', '--force' => true])
            ->expectsOutputToContain('not sending')
            ->assertFailed();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/otp/send'));
    }

    public function test_an_empty_env_line_does_not_hide_the_default(): void
    {
        foreach (['ZADX_SENDER_ID' => '', 'ZADX_BASE_URL' => ''] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        try {
            $services = require base_path('config/services.php');
        } finally {
            foreach (['ZADX_SENDER_ID', 'ZADX_BASE_URL'] as $key) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }

        $this->assertSame('ZADX', $services['zadx']['sender']);
        $this->assertSame('https://smsapi.zadx.net/api/v1', $services['zadx']['base_url']);
    }
}
