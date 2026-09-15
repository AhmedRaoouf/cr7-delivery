<?php

namespace App\Actions\Auth;

use App\Enums\OtpPurpose;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Auth\Sessions;
use App\Support\OtpVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * doc 08 §3 — POST /auth/password/reset. Consumes the `reset`-purpose OTP sent to the
 * account's phone — for an admin reached by email (D-107), the phone on the admin's row.
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
        // code was wrong. Setting a first password is exactly what this endpoint is for;
        // only a login with no account at all is refused, and it is refused with the
        // code's message so the endpoint does not become a way to ask which numbers are
        // registered.
        if (! $user) {
            throw new ApiException('otp_invalid', 'رمز التحقق غير صحيح', 422);
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
