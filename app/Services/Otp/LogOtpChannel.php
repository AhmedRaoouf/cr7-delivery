<?php

namespace App\Services\Otp;

use Illuminate\Support\Facades\Log;

/**
 * Local/dev channel — logs the code instead of paying an SMS gateway.
 * Production binds a real gateway with OTP_CHANNEL (`zadx`, `sms_misr`) — see AppServiceProvider.
 */
class LogOtpChannel implements OtpChannel
{
    public function send(string $phone, string $code): void
    {
        Log::info("[OTP] {$phone} -> {$code}");
    }
}
