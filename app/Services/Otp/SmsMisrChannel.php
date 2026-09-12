<?php

namespace App\Services\Otp;

use App\Exceptions\ApiException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Real gateway — SMS Misr (smsmisr.com). Activated by `OTP_CHANNEL=sms_misr`.
 *
 * Two things about this API decide the whole shape of this class:
 *
 * 1. **It answers HTTP 200 on failure.** A wrong password, an unapproved sender
 *    and an empty wallet all come back `200 OK` with the fault in the JSON body's
 *    `code`. A channel that trusts the HTTP status therefore reports «تم الإرسال»
 *    to a customer who will never receive anything, and the fault is invisible
 *    until somebody reads the log. Only `1901` (SMS) and `6000` (OTP) are success.
 *
 * 2. **`language` is not a locale, it is an encoding.** `1` is English —
 *    GSM-7, which cannot represent an Arabic letter. This channel sent Arabic
 *    text under `language=1` until 2026-09-12. Arabic is `2`, Unicode is `3`;
 *    `3` is the one to try if a mixed Arabic/Latin message arrives as question
 *    marks, because our copy carries the Latin brand name.
 *
 * Two endpoints, chosen by `SMS_MISR_MODE`:
 *   - `sms` (default) — `/api/SMS/`, our own message text, success `1901`.
 *   - `otp`           — `/api/OTP/`, an SMS-Misr-hosted template, success `6000`.
 *     Egypt's A2P rules push transactional traffic onto pre-registered templates,
 *     so an account may be provisioned for this one and refuse the other. It
 *     needs `SMS_MISR_TEMPLATE`, the template id from the SMS Misr console.
 */
class SmsMisrChannel implements OtpChannel
{
    /** `1901` is `/api/SMS/`'s success, `6000` is `/api/OTP/`'s. Nothing else is. */
    private const SUCCESS = ['1901', '6000'];

    /**
     * The documented faults, so the log names the thing to fix rather than a
     * number somebody has to go and look up while sign-in is down.
     */
    private const CODES = [
        '1200' => 'rate limited — SMS Misr wants at least 1 second between requests',
        '1901' => 'success, message submitted',
        '1902' => 'invalid URL — a parameter was not sent',
        '1903' => 'invalid username or password — check SMS_MISR_USERNAME / SMS_MISR_PASSWORD',
        '1904' => 'invalid sender — SMS_MISR_SENDER is not an approved Sender ID on this account',
        '1905' => 'invalid mobile number',
        '1906' => 'insufficient credit — the SMS Misr account is out of points',
        '1907' => 'SMS Misr is under maintenance',
        '1908' => 'invalid date/time in DelayUntil',
        '1909' => 'error in message body',
        '6000' => 'success, OTP request submitted',
        '8001' => 'mobile is null',
        '8002' => 'message is null',
        '8003' => 'language is null',
        '8004' => 'language is null',
        '8005' => 'username is null',
        '8006' => 'username is null',
    ];

    public function send(string $phone, string $code): void
    {
        $otpMode = config('services.sms_misr.mode') === 'otp';

        $response = Http::asForm()->timeout(30)->post(
            $this->endpoint($otpMode),
            $this->payload($phone, $code, $otpMode),
        );

        $body = $this->decode($response);
        $status = (string) ($body['code'] ?? '');

        Log::info('sms_misr.response', [
            'phone' => $phone,
            'http' => $response->status(),
            'code' => $status,
            'meaning' => self::CODES[$status] ?? 'undocumented code',
            'sms_id' => $body['SMSID'] ?? null,
            'cost' => $body['Cost'] ?? null,
        ]);

        if ($response->failed() || ! in_array($status, self::SUCCESS, true)) {
            // The operator needs this one at warning level: every fault above is
            // an account or credential problem, and none of them heals itself.
            Log::warning('sms_misr.send_failed', [
                'phone' => $phone,
                'code' => $status,
                'meaning' => self::CODES[$status] ?? 'undocumented code',
                'body' => $response->body(),
            ]);

            throw new ApiException('otp_send_failed', 'تعذر إرسال رسالة التحقق، حاول مرة أخرى', 503);
        }
    }

    private function endpoint(bool $otpMode): string
    {
        return $otpMode
            ? (string) config('services.sms_misr.otp_url')
            : (string) config('services.sms_misr.url');
    }

    /**
     * @return array<string, string>
     */
    private function payload(string $phone, string $code, bool $otpMode): array
    {
        $common = [
            'environment' => (string) config('services.sms_misr.environment'),
            'username' => (string) config('services.sms_misr.username'),
            'password' => (string) config('services.sms_misr.password'),
            'sender' => (string) config('services.sms_misr.sender'),
            'mobile' => $this->normalizePhone($phone),
        ];

        if ($otpMode) {
            return $common + [
                'template' => (string) config('services.sms_misr.template'),
                'otp' => $code,
            ];
        }

        return $common + [
            'language' => (string) config('services.sms_misr.language'),
            'message' => str_replace(':code', $code, (string) config('services.sms_misr.message')),
        ];
    }

    /**
     * SMS Misr documents a JSON body, and has been seen to answer a bare string
     * on a malformed request. A body we cannot parse is a failure, not a success.
     *
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    /** SMS Misr expects country code + local number with no leading zero, e.g. 201012345678. */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return str_starts_with($digits, '20') ? $digits : '20'.ltrim($digits, '0');
    }
}
