<?php

namespace Tests\Concerns;

use App\Services\Otp\OtpChannel;
use App\Support\Settings;

/** Binds {@see RecordingOtpChannel} and moves the panel's verification switches. */
trait FakesOtpChannel
{
    protected function fakeOtpChannel(): RecordingOtpChannel
    {
        $channel = new RecordingOtpChannel;
        $this->app->instance(OtpChannel::class, $channel);

        return $channel;
    }

    /**
     * `Settings::set` rather than a bare row: it is `updateOrCreate`, so it passes whether
     * or not a migration already inserted the catalogue default, and it forgets the cached
     * value, which `Settings::get` otherwise keeps for ever.
     */
    protected function otpTestMode(string $code = '123456'): void
    {
        Settings::set('otp.mode', 'test', 'otp');
        Settings::set('otp.test_code', $code, 'otp');
    }

    protected function otpSmsMode(): void
    {
        Settings::set('otp.mode', 'sms', 'otp');
    }
}
