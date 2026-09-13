<?php

namespace Tests\Concerns;

use App\Services\Otp\OtpChannel;

/**
 * The gateway, as a list. Every code the platform decided to send by SMS lands here, so a
 * test can assert both halves of D-106: that test mode sends **nothing**, and that an
 * admin's code is sent **whatever** the mode says.
 */
final class RecordingOtpChannel implements OtpChannel
{
    /** @var list<array{phone: string, code: string}> */
    public array $sent = [];

    public function send(string $phone, string $code): void
    {
        $this->sent[] = ['phone' => $phone, 'code' => $code];
    }

    public function lastCodeFor(string $phone): ?string
    {
        foreach (array_reverse($this->sent) as $message) {
            if ($message['phone'] === $phone) {
                return $message['code'];
            }
        }

        return null;
    }
}
