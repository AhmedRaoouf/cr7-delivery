<?php

namespace App\Actions\Auth;

use App\Enums\OtpPurpose;
use App\Models\User;
use App\Support\Auth\Sessions;
use App\Support\OtpVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * doc 08 §3 — POST /auth/password/reset. Consumes the `reset`-purpose OTP sent to the
 * account's phone, which is also the `login` it was asked with.
 */
class ResetPasswordAction
{
    public function execute(string $login, string $code, string $password): void
    {
        $user = User::query()
            ->whereLogin($login)
            ->first();

        // `! $user->password` used to be part of this guard, which meant every account
        // created before passwords existed — i.e. every customer — was told its own reset
        // code was wrong. Setting a first password is exactly what this endpoint is for.
        //
        // Batch 68 — a login with no account is checked against the shadow code its
        // «نسيت كلمة المرور» stored ({@see SendOtpAction::shadow()}), so it counts
        // attempts, expires, locks and blocks exactly like a registered number's: a
        // stranger's `attempts_left` used to be missing, which named every registered
        // number that had asked for a code. The shadow code was never sent to anybody;
        // were it ever matched, the answer is the same success and nothing is written.
        if (! $user) {
            OtpVerifier::consume($login, OtpPurpose::Reset, $code);

            return;
        }

        // Outside the transaction on purpose — see {@see OtpVerifier}.
        OtpVerifier::consume($user->phone, OtpPurpose::Reset, $code);

        DB::transaction(function () use ($user, $password) {
            $user->forceFill([
                'password' => Hash::make($password),
                'must_change_password' => false,
            ])->save();

            // Every session, refresh tokens included (D-120): whoever held the old
            // password may hold a session made with it.
            Sessions::revokeAll($user);
        });
    }
}
