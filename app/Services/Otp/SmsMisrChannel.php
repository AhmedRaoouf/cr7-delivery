<?php

namespace App\Services\Otp;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Real gateway — smsmisr.com/api/SMS/. Activated by setting OTP_CHANNEL=sms_misr
 * in .env once the account is live (see AppServiceProvider::register()).
 *
 * SMS Misr's documented success code is logged on every call (not hard-asserted here)
 * because it isn't confirmed against a live account yet — check storage/logs/laravel.log
 * after the first real send and tighten the success check below if needed.
 */
class SmsMisrChannel implements OtpChannel
{
    public function send(string $phone, string $code): void
    {
        $response = Http::asForm()->timeout(30)->post(config('services.sms_misr.url'), [
            'environment' => config('services.sms_misr.environment'),
            'username' => config('services.sms_misr.username'),
            'password' => config('services.sms_misr.password'),
            'sender' => config('services.sms_misr.sender'),
            'mobile' => $this->normalizePhone($phone),
            'language' => '1',
            'message' => "كود التحقق الخاص بك في CR7 Delivery هو: {$code}",
        ]);

        Log::info('sms_misr.response', ['phone' => $phone, 'status' => $response->status(), 'body' => $response->body()]);

        if ($response->failed()) {
            throw new ApiException('otp_send_failed', 'تعذر إرسال رسالة التحقق، حاول مرة أخرى', 503);
        }
    }

    /** SMS Misr expects country code + local number with no leading zero, e.g. 201012345678. */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        return str_starts_with($digits, '20') ? $digits : '20'.ltrim($digits, '0');
    }
}
