<?php

namespace App\Actions\Auth;

use App\Enums\OtpPurpose;
use App\Models\User;

/** doc 08 §3 — POST /auth/password/forgot. Reuses the OTP channel (purpose = reset) since every account has a phone. */
class ForgotPasswordAction
{
    public function __construct(private readonly SendOtpAction $sendOtp) {}

    public function execute(string $login): void
    {
        $user = User::query()
            ->whereLogin($login)
            ->first();

        if (! $user || ! $user->password) {
            // doc 08 §1 — existence is not leaked; the client sees the same success either way.
            return;
        }

        $this->sendOtp->execute($user->phone, OtpPurpose::Reset);
    }
}
