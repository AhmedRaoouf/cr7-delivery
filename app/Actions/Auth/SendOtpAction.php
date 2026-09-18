<?php

namespace App\Actions\Auth;

use App\Enums\OtpPurpose;
use App\Exceptions\ApiException;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Otp\LogOtpChannel;
use App\Services\Otp\OtpChannel;
use App\Support\Otp;
use App\Support\SettingsCatalog;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * FR-AUTH-02 / D-106 — issue a 6-digit code to a phone.
 *
 * Every number here is the operator's now: how long a code lives (`otp.ttl_minutes`), how
 * long before another may be sent (`otp.resend_seconds`), and how many one number may be
 * sent in an hour (`otp.max_per_hour`). They were 5, 60 and 5 written into this file.
 *
 * In test mode the gateway is **not called**: the code is the operator's test code, it is
 * stored hashed exactly like a real one so verification cannot tell the difference, and it
 * costs nothing. A code test mode does not reach (D-107 — anything for an admin, and every
 * password change) always goes through the bound channel with a random code.
 */
class SendOtpAction
{
    public function __construct(private readonly OtpChannel $channel) {}

    /**
     * @param  User|null  $account  whose code this is, when the caller knows. Otherwise
     *                              the account holding the phone, if any — which is what
     *                              decides whether test mode may reach it.
     * @return array{resend_in: int, expires_in: int, static_otp: ?string}
     */
    public function execute(string $phone, OtpPurpose $purpose, ?User $account = null): array
    {
        $account ??= User::query()->where('phone', $phone)->first();

        $maxPerHour = max(1, SettingsCatalog::int('otp.max_per_hour'));
        $resendSeconds = max(0, SettingsCatalog::int('otp.resend_seconds'));
        $ttlMinutes = max(1, SettingsCatalog::int('otp.ttl_minutes'));

        $rateKey = "otp-send:{$phone}";
        if (RateLimiter::tooManyAttempts($rateKey, $maxPerHour)) {
            $seconds = RateLimiter::availableIn($rateKey);
            throw new ApiException('otp_send_rate_limited', 'محاولات كثيرة جدًا، حاول لاحقًا', 429, meta: ['retry_after' => $seconds]);
        }

        $last = OtpCode::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if ($last && $last->created_at->diffInSeconds(now()) < $resendSeconds) {
            $retryAfter = (int) ceil($resendSeconds - $last->created_at->diffInSeconds(now()));
            throw new ApiException('otp_resend_cooldown', 'يرجى الانتظار قبل إعادة الإرسال', 429, meta: ['retry_after' => $retryAfter]);
        }

        $testCode = Otp::echoable($purpose, $account);
        $code = $testCode ?? Otp::authCode($purpose, $account);

        OtpCode::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'purpose' => $purpose,
            'attempts' => 0,
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);

        RateLimiter::hit($rateKey, 3600);

        if ($testCode === null) {
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
        } elseif (app()->environment('production')) {
            Log::warning('[OTP] otp.mode is test in production — every customer, captain and place code is the operator\'s test code.');
        }

        return [
            'resend_in' => $resendSeconds,
            'expires_in' => $ttlMinutes * 60,
            'static_otp' => $testCode,
        ];
    }

    /**
     * Batch 67 — what a send looks like for a number that holds no account.
     *
     * `POST /auth/password/forgot` answered «إذا كان الحساب موجودًا…» either way, but
     * only a real send started the resend cooldown and the hourly cap. So the second
     * ask within a minute was `429 otp_resend_cooldown` for a registered number and
     * `200` for a stranger — a free, silent oracle for «is this phone on the platform».
     * This runs the same two clocks for the stranger, sends nothing and stores nothing.
     *
     * @throws ApiException otp_send_rate_limited · otp_resend_cooldown, exactly as a real send would
     */
    public function shadow(string $phone, OtpPurpose $purpose): void
    {
        $maxPerHour = max(1, SettingsCatalog::int('otp.max_per_hour'));
        $resendSeconds = max(0, SettingsCatalog::int('otp.resend_seconds'));

        $rateKey = "otp-send:{$phone}";
        if (RateLimiter::tooManyAttempts($rateKey, $maxPerHour)) {
            throw new ApiException('otp_send_rate_limited', 'محاولات كثيرة جدًا، حاول لاحقًا', 429, meta: ['retry_after' => RateLimiter::availableIn($rateKey)]);
        }

        $cooldownKey = "otp-shadow-cooldown:{$phone}:{$purpose->value}";
        if ($resendSeconds > 0 && RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            throw new ApiException('otp_resend_cooldown', 'يرجى الانتظار قبل إعادة الإرسال', 429, meta: ['retry_after' => RateLimiter::availableIn($cooldownKey)]);
        }

        RateLimiter::hit($rateKey, 3600);

        if ($resendSeconds > 0) {
            RateLimiter::hit($cooldownKey, $resendSeconds);
        }
    }
}
