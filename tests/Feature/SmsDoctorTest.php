<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The gateway has to be provable on a live server without moving a single
 * customer off the sign-in path they are on. These pin that down.
 */
class SmsDoctorTest extends TestCase
{
    private const SMSMISR_KEYS = [
        'SMSMISR_USERNAME', 'SMSMISR_PASSWORD', 'SMSMISR_SENDER', 'SMSMISR_ENVIRONMENT',
        'SMS_MISR_USERNAME', 'SMS_MISR_SENDER',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.otp_channel' => 'log',
            'services.sms_misr.mode' => 'sms',
            'services.sms_misr.url' => 'https://smsmisr.com/api/SMS/',
            'services.sms_misr.balance_url' => 'https://smsmisr.com/api/Balance/',
            'services.sms_misr.environment' => '2',
            'services.sms_misr.language' => '2',
            'services.sms_misr.username' => 'user-uuid',
            'services.sms_misr.password' => 'pass-hex',
            'services.sms_misr.sender' => 'sender-from-env',
            'services.sms_misr.message' => 'كود التحقق الخاص بك في CR7 Delivery هو: :code',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (self::SMSMISR_KEYS as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        parent::tearDown();
    }

    /**
     * SMS Misr's console prints `SMSMISR_USERNAME=` — one word. Pasted into .env
     * under that spelling, our `SMS_MISR_USERNAME` read null for every key and
     * the gateway would have answered 1902 with nothing saying why.
     */
    public function test_the_config_accepts_the_spelling_sms_misr_hands_out(): void
    {
        foreach ([
            'SMSMISR_USERNAME' => '76b25d67-console',
            'SMSMISR_PASSWORD' => '35a8f47-console',
            'SMSMISR_SENDER' => '3e234c3-console',
            'SMSMISR_ENVIRONMENT' => '2',
        ] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        $services = require base_path('config/services.php');

        $this->assertSame('76b25d67-console', $services['sms_misr']['username']);
        $this->assertSame('35a8f47-console', $services['sms_misr']['password']);
        $this->assertSame('3e234c3-console', $services['sms_misr']['sender']);
        $this->assertSame('2', $services['sms_misr']['environment']);
    }

    /**
     * The production .env has had `SMS_MISR_USERNAME=` with nothing after it since
     * the first deploy. Laravel reads that as an empty string, not an absent key,
     * so env()'s default argument never fires and SMS Misr's block appended below
     * it would have been ignored. Measured on the server before this was written.
     */
    public function test_an_empty_line_under_our_spelling_does_not_hide_theirs(): void
    {
        foreach ([
            'SMS_MISR_USERNAME' => '',
            'SMS_MISR_SENDER' => '',
            'SMSMISR_USERNAME' => '76b25d67-console',
            'SMSMISR_SENDER' => '3e234c3-console',
        ] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        $services = require base_path('config/services.php');

        $this->assertSame('76b25d67-console', $services['sms_misr']['username']);
        $this->assertSame('3e234c3-console', $services['sms_misr']['sender']);
    }

    /**
     * The whole point of the command: while `OTP_CHANNEL=log` still carries every
     * customer's sign-in, a test send must reach SMS Misr anyway. Routing it
     * through the bound channel would have written it to the log and reported
     * success, proving nothing.
     */
    public function test_a_test_send_reaches_sms_misr_while_sign_in_stays_on_the_log_channel(): void
    {
        Http::fake([
            'smsmisr.com/api/Balance/*' => Http::response(['code' => '1901', 'balance' => '5000']),
            'smsmisr.com/api/SMS/*' => Http::response(['code' => '1901', 'SMSID' => '9', 'Cost' => '1']),
        ]);

        $this->artisan('cr7:sms-doctor', ['phone' => '01012345678', '--force' => true])
            ->expectsOutputToContain('SMS Misr accepted it')
            ->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/SMS/')
            && $r['mobile'] === '201012345678'
            && $r['sender'] === 'sender-from-env'
            && $r['environment'] === '2');

        // Sign-in was not moved.
        $this->assertSame('log', config('services.otp_channel'));
    }

    /**
     * SMS Misr issues a test token and a production token separately. Trying the
     * other one must not mean editing the server's .env between runs.
     */
    public function test_sender_can_be_overridden_for_one_run(): void
    {
        Http::fake(['smsmisr.com/*' => Http::response(['code' => '1901'])]);

        $this->artisan('cr7:sms-doctor', ['phone' => '01012345678', '--sender' => 'test-token', '--force' => true])
            ->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/SMS/') && $r['sender'] === 'test-token');
    }

    public function test_a_rejected_sender_is_reported_as_a_failure(): void
    {
        Http::fake([
            'smsmisr.com/api/Balance/*' => Http::response(['code' => '1901']),
            'smsmisr.com/api/SMS/*' => Http::response(['code' => '1904'], 200),
        ]);

        $this->artisan('cr7:sms-doctor', ['phone' => '01012345678', '--force' => true])
            ->expectsOutputToContain('send failed')
            ->assertFailed();
    }

    public function test_it_refuses_to_send_with_a_credential_missing(): void
    {
        config(['services.sms_misr.password' => null]);
        Http::fake();

        $this->artisan('cr7:sms-doctor', ['phone' => '01012345678', '--force' => true])
            ->expectsOutputToContain('not sending')
            ->assertFailed();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/api/SMS/'));
    }

    public function test_a_read_only_run_never_touches_the_send_endpoint(): void
    {
        Http::fake(['smsmisr.com/*' => Http::response(['code' => '1901'])]);

        $this->artisan('cr7:sms-doctor')->assertSuccessful();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/api/SMS/'));
        // POST, not the GET SMS Misr's page advertises — production answered 405 to GET.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/Balance/')
            && $r->method() === 'POST'
            && $r['username'] === 'user-uuid');
    }
}
