<?php

namespace App\Actions\Auth;

use App\Enums\OtpPurpose;
use App\Models\User;
use App\Support\PhoneNumber;

/**
 * doc 08 §3 — POST /auth/password/forgot. Reuses the OTP channel (purpose = reset) since
 * every account has a phone.
 *
 * D-107 — an admin may ask by email. The code still goes to the **phone** on the admin's
 * row, by real SMS whatever `otp.mode` says, and the answer carries that phone masked —
 * «‪010•••••99‬» — because somebody who typed an email has otherwise no idea which of
 * their numbers to look at. A phone login gets no mask: the caller already typed it, and
 * a mask answered only for some numbers would say which ones are admins.
 */
class ForgotPasswordAction
{
    public function __construct(private readonly SendOtpAction $sendOtp) {}

    /** @return string|null the masked phone the code went to, for an email login only */
    public function execute(string $login): ?string
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
            return null;
        }

        $this->sendOtp->execute($user->phone, OtpPurpose::Reset, $user);

        return PhoneNumber::isEmail($login) ? PhoneNumber::mask($user->phone) : null;
    }
}
