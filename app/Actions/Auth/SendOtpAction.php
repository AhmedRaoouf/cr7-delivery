<?php

namespace App\Actions\Auth;

use App\Enums\OtpPurpose;
use App\Exceptions\ApiException;
use App\Models\OtpCode;
use App\Models\SmsSend;
use App\Models\User;
use App\Services\Otp\LogOtpChannel;
use App\Services\Otp\OtpChannel;
use App\Support\Otp;
use App\Support\OtpBudget;
use App\Support\OtpGuard;
use App\Support\OtpRequester;
use App\Support\OtpVerifier;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * FR-AUTH-02 / D-106 — issue a 6-digit code to a phone.
 *
 * Batch 68 — every rule that decides whether the code may go is {@see OtpGuard}'s (per
 * number, per address, per install) and {@see OtpBudget}'s (the platform's own ceiling),
 * and each send is a row in `sms_sends` they count from. The order a request is judged
 * in: a block on the number · the escalating wait and the number's hourly count · the
 * number's daily count (the request past it blocks) · the caller's address and install ·
 * the platform budget.
 *
 * **One live code per number and purpose.** A new send retires every earlier code that is
 * still live, and {@see OtpVerifier} only ever checks the latest — so a code
 * read off an old message, or guessed at across re-sends, is worth nothing once a new one
 * has gone out.
 *
 * In test mode the gateway is **not called**: the code is the operator's test code, it is
 * stored hashed exactly like a real one so verification cannot tell the difference, and it
 * costs nothing — the caps still count it, so the flows stay testable, but the budget does
 * not. A code test mode does not reach (D-107 — anything for an admin, and every password
 * change) always goes through the bound channel with a random code.
 */
class SendOtpAction
{
    public function __construct(private readonly OtpChannel $channel) {}

    /**
     * @param  User|null  $account  whose code this is, when the caller knows. Otherwise
     *                              the account holding the phone, if any — which is what
     *                              decides whether test mode may reach it.
     * @return array{resend_in: int, expires_in: int, code_length: int, max_attempts: int, static_otp: ?string}
     *
     * @throws ApiException otp_phone_blocked 423 · otp_resend_cooldown 429 ·
     *                      otp_send_rate_limited 429 · otp_unavailable 503
     */
    public function execute(string $phone, OtpPurpose $purpose, ?User $account = null, ?OtpRequester $requester = null): array
    {
        $account ??= User::query()->where('phone', $phone)->first();

        return $this->issue($phone, $purpose, $account, $requester ?? OtpRequester::fromRequest(request()), shadow: false);
    }

    /**
     * Batch 67 / 68 — what a send looks like for a number that holds no account.
     *
     * `POST /auth/password/forgot` answers the same sentence either way, so the only
     * thing that could tell a registered number from a stranger is **which clocks run**.
     * They all run here, exactly as for a customer's number: the same escalating wait, the
     * same hourly and daily counts and the same block past them, the same per-address and
     * per-install counts, and the same breaker — and a shadow code is stored, random and
     * never sent, so `POST /auth/password/reset` counts attempts, expires and locks for a
     * stranger exactly as for anybody else. Nothing leaves the server and it costs nothing.
     *
     * The one difference that remains is deliberate: an admin's reset is never refused by
     * the budget breaker, so while the breaker is tripped an admin's number answers `200`
     * where everybody else's answers `503`.
     *
     * @return array{resend_in: int, expires_in: int, code_length: int, max_attempts: int, static_otp: ?string}
     */
    public function shadow(string $phone, OtpPurpose $purpose, ?OtpRequester $requester = null): array
    {
        return $this->issue($phone, $purpose, null, $requester ?? OtpRequester::fromRequest(request()), shadow: true);
    }

    /** @return array{resend_in: int, expires_in: int, code_length: int, max_attempts: int, static_otp: ?string} */
    private function issue(string $phone, OtpPurpose $purpose, ?User $account, OtpRequester $requester, bool $shadow): array
    {
        // A stranger stands in for a customer: the test code in test mode, a random code
        // otherwise. It never reaches the gateway either way.
        $testCode = $shadow
            ? (Otp::testMode() ? Otp::testCode() : null)
            : Otp::echoable($purpose, $account);

        $kind = match (true) {
            $shadow => SmsSend::KIND_SHADOW,
            $testCode !== null => SmsSend::KIND_TEST,
            default => SmsSend::KIND_REAL,
        };

        $owner = self::isOwnerSend($purpose, $account);
        $breaker = $testCode === null && ! self::bypassesBudget($purpose, $account);

        // One number at a time, so two requests racing each other cannot both slip under a
        // count that has room for one.
        $lock = Cache::lock('otp-send-lock:'.$phone, 10);

        try {
            $code = $lock->block(5, function () use ($phone, $purpose, $account, $requester, $owner, $breaker, $kind, $testCode): string {
                OtpGuard::admit($phone, $purpose, $requester, $owner);

                if ($breaker) {
                    OtpBudget::assertOpen();
                }

                OtpGuard::record($phone, $purpose, $kind, $requester);

                // One live code: the earlier ones stop working the moment this one exists.
                OtpCode::query()
                    ->where('phone', $phone)
                    ->where('purpose', $purpose)
                    ->whereNull('consumed_at')
                    ->where('expires_at', '>', now())
                    ->update(['expires_at' => now()]);

                $code = $testCode ?? Otp::authCode($purpose, $account);

                OtpCode::create([
                    'phone' => $phone,
                    'code_hash' => Hash::make($code),
                    'purpose' => $purpose,
                    'attempts' => 0,
                    'expires_at' => now()->addSeconds(OtpGuard::ttlSeconds()),
                ]);

                return $code;
            });
        } catch (LockTimeoutException) {
            throw OtpGuard::rateLimited(now()->addSeconds(5));
        }

        if ($kind === SmsSend::KIND_REAL) {
            // The failure that looks like a working server: `otp.mode = sms` on the
            // panel while the log is still the bound channel. The code is random and
            // lands in storage/logs and nowhere else, `/auth/otp/send` answers 200, and
            // nobody receives anything. Said on every send, where it happens.
            if ($this->channel instanceof LogOtpChannel) {
                Log::warning('[OTP] a real code was issued and OTP_CHANNEL is the log — nobody will receive it. Bind SMS Misr, or switch otp.mode to test on ADM-46 while there is no gateway.', [
                    'purpose' => $purpose->value,
                ]);
            }

            $this->channel->send($phone, $code);

            OtpBudget::afterRealSend();
        } elseif ($kind === SmsSend::KIND_TEST && app()->environment('production')) {
            Log::warning('[OTP] otp.mode is test in production — every customer, captain and place code is the operator\'s test code.');
        }

        return [
            'resend_in' => OtpGuard::resendIn($phone, $purpose, $owner),
            'expires_in' => OtpGuard::ttlSeconds(),
            'code_length' => OtpGuard::CODE_LENGTH,
            'max_attempts' => OtpGuard::maxAttempts(),
            'static_otp' => $shadow ? null : $testCode,
        ];
    }

    /**
     * A signed-in admin's own password-change code (D-107): held only by its own wait and
     * its own hourly count — see {@see OtpGuard}. Anybody can run up the anonymous counts
     * on a number they know; that must not lock the operator out of the one flow that
     * needs a live session and the current password.
     */
    public static function isOwnerSend(OtpPurpose $purpose, ?User $account): bool
    {
        return $purpose === OtpPurpose::PasswordChange && $account?->isAdmin() === true;
    }

    /**
     * The codes the budget breaker never refuses: an admin's password reset and password
     * change. See {@see OtpBudget}.
     */
    public static function bypassesBudget(OtpPurpose $purpose, ?User $account): bool
    {
        return $account?->isAdmin() === true
            && in_array($purpose, [OtpPurpose::Reset, OtpPurpose::PasswordChange], true);
    }
}
