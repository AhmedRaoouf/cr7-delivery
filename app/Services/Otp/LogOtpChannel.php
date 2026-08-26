<?php

namespace App\Services\Otp;

use Illuminate\Support\Facades\Log;

/**
 * Local/dev channel — logs the code instead of paying an SMS gateway.
 * Swap the binding in AppServiceProvider for a real Vodafone/Twilio channel in production.
 */
class LogOtpChannel implements OtpChannel
{
    public function send(string $phone, string $code): void
    {
        Log::info("[OTP] {$phone} -> {$code}");
    }
}
