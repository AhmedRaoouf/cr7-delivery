<?php

namespace App\Actions\Auth;

use App\Enums\OtpPurpose;
use App\Models\User;
use App\Support\OtpRequester;

/**
 * doc 08 §3 — POST /auth/password/forgot. Reuses the OTP channel (purpose = reset) since
 * every account has a phone.
 *
 * The login is a phone for every account type. D-107 let an admin ask by email and answered
 * with the phone masked, «‪010•••••99‬»; the operator removed the email on 2026-09-17, so the
 * caller always typed the number the code goes to and there is nothing to mask. An admin's
 * code is still a real SMS whatever `otp.mode` says — that half of D-107 stands.
 */
class ForgotPasswordAction
{
    public function __construct(private readonly SendOtpAction $sendOtp) {}

    /**
     * Batch 68 — answers `{resend_in, expires_in, code_length, max_attempts, static_otp}`,
     * the same shape a code send answers everywhere else, and the same values whether or not
     * the number holds an account. `static_otp` is always null here: the per-send echo is null
     * for an admin's number and the test code for anybody else's, so echoing it would name
     * the admin's number in test mode. A client that wants to prefill in test mode reads
     * `auth.static_otp` from `GET /config`, which is the same value.
     *
     * @return array{resend_in: int, expires_in: int, code_length: int, max_attempts: int, static_otp: null}
     */
    public function execute(string $login, ?OtpRequester $requester = null): array
    {
        $user = User::query()
            ->whereLogin($login)
            ->first();

        // `! $user->password` used to be part of this. Since 2026-09-07 every customer
        // signs in with one, and the accounts that predate that change have none — so the
        // guard silently refused a code to precisely the people who needed one to set
        // their first password. Only «no such account» is a reason to do nothing, and it
        // is still answered with the same success message either way (doc 08 §1).
        $sent = $user
            ? $this->sendOtp->execute($user->phone, OtpPurpose::Reset, $user, $requester)
            // Batch 67/68 — the same clocks as a real send, so the answer cannot tell a
            // stranger's number from a registered one. See {@see SendOtpAction::shadow()}.
            : $this->sendOtp->shadow($login, OtpPurpose::Reset, $requester);

        return array_merge($sent, ['static_otp' => null]);
    }
}
