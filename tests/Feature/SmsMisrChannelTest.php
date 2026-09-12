<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Services\Otp\SmsMisrChannel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The gateway that says yes when it means no.
 *
 * SMS Misr answers `200 OK` for a wrong password, an unapproved sender and an
 * empty wallet alike — the fault is in the body. A channel that reads the HTTP
 * status therefore tells the customer the code is on its way and tells the log
 * nothing, which is the one failure mode nobody notices until sign-in is down.
 *
 * Per the standing rule: fake the response AND assert the request. The defect
 * these tests were written for — Arabic text posted under `language=1`, which
 * is GSM-7 English — is invisible to any test that only fakes a 200.
 */
class SmsMisrChannelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.sms_misr.url' => 'https://smsmisr.com/api/SMS/',
            'services.sms_misr.otp_url' => 'https://smsmisr.com/api/OTP/',
            'services.sms_misr.mode' => 'sms',
            'services.sms_misr.environment' => '1',
            'services.sms_misr.language' => '2',
            'services.sms_misr.username' => 'cr7',
            'services.sms_misr.password' => 'secret',
            'services.sms_misr.sender' => 'CR7Delivery',
            'services.sms_misr.template' => null,
            'services.sms_misr.message' => 'كود التحقق الخاص بك في CR7 Delivery هو: :code',
        ]);
    }

    public function test_a_successful_send_posts_arabic_encoding_and_an_international_number(): void
    {
        Http::fake(['smsmisr.com/*' => Http::response(['code' => '1901', 'SMSID' => '123', 'Cost' => '1'])]);

        (new SmsMisrChannel)->send('01012345678', '654321');

        Http::assertSent(function (Request $request) {
            $this->assertSame('https://smsmisr.com/api/SMS/', $request->url());

            // The bug this file exists for: `1` is English/GSM-7 and drops every
            // Arabic letter in the body above.
            $this->assertSame('2', $request['language']);

            // No leading zero, country code prefixed.
            $this->assertSame('201012345678', $request['mobile']);

            $this->assertSame('1', $request['environment']);
            $this->assertSame('cr7', $request['username']);
            $this->assertSame('secret', $request['password']);
            $this->assertSame('CR7Delivery', $request['sender']);
            $this->assertStringContainsString('654321', $request['message']);

            // `/api/SMS/` takes free text, never a template id.
            $this->assertArrayNotHasKey('template', $request->data());

            return true;
        });
    }

    /**
     * Every one of these is an HTTP 200. That is the whole point.
     */
    #[DataProvider('gatewayFaults')]
    public function test_a_documented_fault_is_a_failure_even_on_http_200(string $code): void
    {
        Http::fake(['smsmisr.com/*' => Http::response(['code' => $code], 200)]);

        $this->expectException(ApiException::class);

        (new SmsMisrChannel)->send('01012345678', '654321');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function gatewayFaults(): array
    {
        return [
            'invalid credentials' => ['1903'],
            'sender not approved' => ['1904'],
            'invalid mobile' => ['1905'],
            'out of credit' => ['1906'],
            'gateway maintenance' => ['1907'],
            'rate limited' => ['1200'],
            // `6000` is `/api/OTP/`'s success and is not `/api/SMS/`'s — but the
            // channel accepts either rather than coupling the check to the mode,
            // so this asserts the one code that must never pass: an empty body.
            'empty body' => [''],
        ];
    }

    public function test_a_body_that_is_not_json_is_a_failure(): void
    {
        Http::fake(['smsmisr.com/*' => Http::response('Invalid URL', 200)]);

        $this->expectException(ApiException::class);

        (new SmsMisrChannel)->send('01012345678', '654321');
    }

    public function test_otp_mode_posts_a_template_to_the_other_endpoint(): void
    {
        config([
            'services.sms_misr.mode' => 'otp',
            'services.sms_misr.template' => '4402',
        ]);

        Http::fake(['smsmisr.com/*' => Http::response(['code' => '6000'])]);

        (new SmsMisrChannel)->send('01012345678', '654321');

        Http::assertSent(function (Request $request) {
            $this->assertSame('https://smsmisr.com/api/OTP/', $request->url());
            $this->assertSame('4402', $request['template']);
            $this->assertSame('654321', $request['otp']);

            // The template holds the wording, so neither of these is ours to send.
            $this->assertArrayNotHasKey('message', $request->data());
            $this->assertArrayNotHasKey('language', $request->data());

            return true;
        });
    }

    #[DataProvider('phoneNumbers')]
    public function test_a_phone_number_reaches_the_gateway_in_international_form(string $stored, string $sent): void
    {
        Http::fake(['smsmisr.com/*' => Http::response(['code' => '1901'])]);

        (new SmsMisrChannel)->send($stored, '654321');

        Http::assertSent(fn (Request $request) => $request['mobile'] === $sent);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function phoneNumbers(): array
    {
        return [
            'local with leading zero' => ['01012345678', '201012345678'],
            'already international' => ['201012345678', '201012345678'],
            'with a plus' => ['+201012345678', '201012345678'],
            'spaced and dashed' => ['0101 234-5678', '201012345678'],
            'no leading zero' => ['1012345678', '201012345678'],
        ];
    }
}
