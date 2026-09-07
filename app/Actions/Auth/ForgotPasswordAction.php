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

        // `! $user->password` used to be part of this. Since 2026-09-07 every customer
        // signs in with one, and the accounts that predate that change have none — so the
        // guard silently refused a code to precisely the people who needed one to set
        // their first password. Only «no such account» is a reason to do nothing, and it
        // is still answered with the same success message either way (doc 08 §1).
        if (! $user) {
            return;
        }

        $this->sendOtp->execute($user->phone, OtpPurpose::Reset);
    }
}
