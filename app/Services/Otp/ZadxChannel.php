<?php

namespace App\Services\Otp;

use App\Exceptions\ApiException;
use App\Support\OtpGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Real gateway — ZADX (smsportal.zadx.net). Activated by `OTP_CHANNEL=zadx`.
 *
 * What the API asks of a caller, and where this class answers it:
 *
 * 1. **Two headers on every call**, `X-Api-Key` and `X-Api-Secret`, and a third,
 *    `Idempotency-Key`, on every write. The same key with a different body is a
 *    `409 idempotency_conflict`, and every code CR7 sends is a different body, so the key
 *    is a fresh UUID per send. There is no retry here to protect: ZADX itself never
 *    resends a message, and neither does this class — a timeout is `pending_verification`
 *    on their side, credits reserved, and an automatic second send is exactly what that
 *    status warns against.
 *
 * 2. **`/otp/send` takes the code, not the text.** ZADX owns the OTP template
 *    («كود التحقق الخاص بك هو: {otp}»), which is why nothing here builds a message. The
 *    code must be 4–6 alphanumerics; CR7's is six digits ({@see OtpGuard::CODE_LENGTH}).
 *    OTP traffic is exempt from ZADX's content screening, so a code can never cost the
 *    account a strike.
 *
 * 3. **Failure is an HTTP status this time** — unlike SMS Misr, which answers 200 on
 *    everything. A send is accepted when the status is 2xx *and* the body's `status` is
 *    one ZADX gives an accepted message. The fault, when there is one, is
 *    `{"error": {"code", "message"}}`.
 *
 * 4. **ZADX caps a recipient at 1 a minute and 5 an hour, and an app at 60 a minute.**
 *    CR7's own resend wait (`otp.resend_seconds`, 60) and hourly count
 *    (`otp.max_per_hour`, 5) are the same numbers on purpose, so ZADX's limit is a
 *    backstop that should never fire first. When it does — the operator raised a CR7 cap
 *    above ZADX's — it is answered as CR7's own 429 `otp_send_rate_limited` with ZADX's
 *    `Retry-After`, not as an outage, because the customer is only being asked to wait.
 *
 * A code is a credential, so it is never logged; the phone and ZADX's message id are, and
 * the id is what the «Messages» page on the ZADX dashboard is searched by.
 */
class ZadxChannel implements OtpChannel
{
    /** Statuses ZADX gives a message it took: see «Success response shape» in their docs. */
    private const ACCEPTED = ['queued', 'sent', 'delivered', 'pending_verification'];

    /** Below this many credits the log says so on every send — the account is nearly dry. */
    private const LOW_CREDITS = 10;

    /**
     * The documented faults, so the log names the thing to fix instead of a code somebody
     * has to go and look up while sign-in is down.
     */
    private const CODES = [
        'missing_credentials' => 'no X-Api-Key / X-Api-Secret was sent — check ZADX_API_KEY and ZADX_API_SECRET',
        'invalid_credentials' => 'ZADX rejected the key or secret — refresh them from the app page on the ZADX dashboard',
        'no_active_subscription' => 'the ZADX app has no active plan — buy or renew one',
        'quota_exhausted' => 'the ZADX plan is out of credits — add a plan or upgrade',
        'app_inactive' => 'the ZADX app is suspended or revoked',
        'ip_not_allowed' => "this server's IP is not on the app's allow-list on the ZADX dashboard",
        'sender_id_not_allowed' => 'ZADX_SENDER_ID is not assigned to this app',
        'mode_not_allowed' => 'the app is not allowed to use /otp/send — check its mode on the ZADX dashboard',
        'idempotency_conflict' => 'an Idempotency-Key was reused with a different body',
        'missing_idempotency_key' => 'no Idempotency-Key was sent',
        'invalid_phone' => 'ZADX does not accept this as an Egyptian mobile number',
        'rate_limited_app' => 'the app is over its per-minute allowance',
        'rate_limited_phone_minute' => 'this number already received a message in the last minute',
        'rate_limited_phone_hour' => 'this number already received five messages in the last hour',
        'provider_failed' => 'the carrier explicitly rejected the message (credits refunded)',
        'provider_unavailable' => 'the carrier is unreachable',
        'driver_exception' => 'ZADX failed internally while sending',
    ];

    public function send(string $phone, string $code): void
    {
        if (! preg_match('/^[A-Za-z0-9]{4,6}$/', $code)) {
            // ZADX would answer 422; refusing here keeps a malformed code out of the wire
            // and out of the log alike.
            Log::warning('zadx.send_refused', ['phone' => $phone, 'reason' => 'code is not 4 to 6 alphanumerics']);

            throw $this->failed();
        }

        try {
            $response = $this->client()
                ->withHeaders(['Idempotency-Key' => 'cr7-otp-'.Str::uuid()])
                ->post($this->url('/otp/send'), array_filter([
                    'to' => $this->normalizePhone($phone),
                    'otp' => $code,
                    'sender_id' => (string) config('services.zadx.sender'),
                    'locale' => (string) config('services.zadx.locale', 'ar'),
                ], fn (string $value) => $value !== ''));
        } catch (ConnectionException $e) {
            // Nothing is known about whether the message left. Never retried (see above).
            Log::warning('zadx.unreachable', ['phone' => $phone, 'error' => Str::limit($e->getMessage(), 200)]);

            throw $this->failed();
        }

        $body = $this->decode($response);
        $status = (string) ($body['status'] ?? '');
        $fault = (string) ($body['error']['code'] ?? '');

        if ($response->successful() && in_array($status, self::ACCEPTED, true)) {
            Log::info('zadx.response', [
                'phone' => $phone,
                'http' => $response->status(),
                'status' => $status,
                'message_id' => $body['id'] ?? null,
                'cost_credits' => $body['cost_credits'] ?? null,
                'remaining_credits' => $body['remaining_credits'] ?? null,
            ]);

            if ($status === 'pending_verification') {
                Log::warning('zadx.pending_verification', ['phone' => $phone, 'message_id' => $body['id'] ?? null]);
            }

            if (is_numeric($body['remaining_credits'] ?? null) && $body['remaining_credits'] <= self::LOW_CREDITS) {
                Log::warning('zadx.low_credits', ['remaining_credits' => $body['remaining_credits']]);
            }

            return;
        }

        if ($response->status() === 429) {
            Log::warning('zadx.rate_limited', ['phone' => $phone, 'code' => $fault, 'meaning' => self::CODES[$fault] ?? 'undocumented code']);

            throw OtpGuard::rateLimited(now()->addSeconds(max(1, (int) $response->header('Retry-After') ?: 60)));
        }

        // Every other fault is an account, credential or carrier problem, and none of
        // them heals itself: the operator needs this one at warning level.
        Log::warning('zadx.send_failed', [
            'phone' => $phone,
            'http' => $response->status(),
            'code' => $fault !== '' ? $fault : ($status !== '' ? $status : 'no_error_code'),
            'meaning' => self::CODES[$fault] ?? 'undocumented code',
            'message' => Str::limit((string) ($body['error']['message'] ?? ''), 200),
        ]);

        throw $this->failed();
    }

    /**
     * The app's plan: `remaining_credits`, `quota`, `expires_at`. Free, and the only call
     * that proves a key and secret without sending anything. Null when ZADX cannot be
     * reached or does not answer 200 — `$status` then carries why.
     *
     * @return array<string, mixed>|null
     */
    public function balance(?int &$status = null): ?array
    {
        try {
            $response = $this->client(6)->get($this->url('/sms/balance'));
        } catch (ConnectionException) {
            $status = 0;

            return null;
        }

        $status = $response->status();

        return $response->successful() && is_array($response->json()) ? $response->json() : null;
    }

    public static function configured(): bool
    {
        return filled(config('services.zadx.key')) && filled(config('services.zadx.secret'));
    }

    private function client(int $timeout = 0): PendingRequest
    {
        return Http::asJson()
            ->acceptJson()
            ->timeout($timeout ?: (int) config('services.zadx.timeout', 15))
            ->withHeaders([
                'X-Api-Key' => (string) config('services.zadx.key'),
                'X-Api-Secret' => (string) config('services.zadx.secret'),
            ]);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.zadx.base_url'), '/').$path;
    }

    private function failed(): ApiException
    {
        return new ApiException('otp_send_failed', 'تعذر إرسال رسالة التحقق، حاول مرة أخرى', 503);
    }

    /**
     * A body that is not JSON is a failure, not a success — the error envelope is the only
     * other shape ZADX documents.
     *
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    /** ZADX documents `01010626698` and `+201010626698`; the second cannot be misread. */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return '+'.(str_starts_with($digits, '20') ? $digits : '20'.ltrim($digits, '0'));
    }
}
