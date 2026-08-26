<?php

namespace App\Services\Otp;

interface OtpChannel
{
    public function send(string $phone, string $code): void;
}
