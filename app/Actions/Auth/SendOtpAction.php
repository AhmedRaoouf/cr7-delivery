<?php

namespace App\Actions\Auth;

use App\Enums\OtpPurpose;
use App\Exceptions\ApiException;
use App\Models\OtpCode;
use App\Services\Otp\OtpChannel;
use App\Support\Otp;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * FR-AUTH-02: 6-digit code, valid 5 minutes, resend after a 60s cooldown,
 * max 5 sends/hour/phone.
 */
class SendOtpAction
{
    public function __construct(private readonly OtpChannel $channel)
    {
    }

    public function execute(string $phone, OtpPurpose $purpose): void
    {
        $rateKey = "otp-send:{$phone}";
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            $seconds = RateLimiter::availableIn($rateKey);
            throw new ApiException('otp_send_rate_limited', 'محاولات كثيرة جدًا، حاول لاحقًا', 429, meta: ['retry_after' => $seconds]);
        }

        $last = OtpCode::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if ($last && $last->created_at->diffInSeconds(now()) < 60) {
            $retryAfter = 60 - $last->created_at->diffInSeconds(now());
            throw new ApiException('otp_resend_cooldown', 'يرجى الانتظار قبل إعادة الإرسال', 429, meta: ['retry_after' => $retryAfter]);
        }

        $code = Otp::authCode();

        OtpCode::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'purpose' => $purpose,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
        ]);

        RateLimiter::hit($rateKey, 3600);

        $this->channel->send($phone, $code);
    }
}
