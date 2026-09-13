<?php

namespace App\Actions\Auth;

use App\Enums\OtpPurpose;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\OtpVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-AUTH-01/03 — verifying a code, for the two things a code is used for.
 *
 * **`login` / `verify`** is what it always was: a correct code is a session, and a phone
 * nobody has used before becomes a customer on the spot. That path is kept because every
 * account created before 2026-09-07 has no password, and taking it away would lock all of
 * them out at once.
 *
 * **`register`** no longer creates anything. Operator, 2026-09-07: «أول ما تتأكد من رقمه
 * وتبعت الـSMS ويحط الأوتوبيس صح، بيكون كاتب بقى الباسورد». So the customer types the
 * password *after* being told the code was right, which is two requests, and this one
 * answers with a `registration_token` instead of a session — the proof that this phone
 * answered its own code, good for fifteen minutes and one account.
 *
 * The alternative, holding the password until the code came back and posting both at
 * once, means the password travels beside an unverified phone number and a typo in the
 * code costs the customer the whole form.
 *
 * The code is checked by {@see OtpVerifier} **before** the transaction below opens, so a
 * wrong code keeps costing an attempt — inside the transaction, the rollback undid it.
 */
class VerifyOtpAction
{
    /** How long the proof of a verified phone is worth anything. */
    public const REGISTRATION_TOKEN_MINUTES = 15;

    /** @return array{user: User, token: string, is_new_user: bool}|array{registration_token: string, phone: string, expires_at: string} */
    public function execute(string $phone, string $code, OtpPurpose $purpose): array
    {
        $otp = OtpVerifier::consume($phone, $purpose, $code);

        return DB::transaction(function () use ($phone, $purpose, $otp) {
            // Registration stops here. Nothing is created, no session is issued — the
            // caller gets a token to bring back with a password.
            if ($purpose === OtpPurpose::Register) {
                if (User::query()->where('phone', $phone)->exists()) {
                    throw RegisterCustomerAction::phoneTaken();
                }

                $token = Str::random(64);

                $otp->forceFill([
                    'follow_up_token' => $token,
                    'follow_up_expires_at' => now()->addMinutes(self::REGISTRATION_TOKEN_MINUTES),
                ])->save();

                return [
                    'registration_token' => $token,
                    'phone' => $phone,
                    'expires_at' => $otp->follow_up_expires_at->toIso8601String(),
                ];
            }

            $user = User::query()->firstOrCreate(
                ['phone' => $phone],
                ['type' => UserType::Customer, 'is_active' => true, 'locale' => 'ar', 'theme' => 'dark'],
            );
            $isNewUser = $user->wasRecentlyCreated;

            if (! $user->isCustomer()) {
                // Named, not just refused. «هذا الرقم مسجل بنوع حساب مختلف» left a captain
                // guessing which of their two numbers they had typed; the operator asked
                // for the sentence that says it: «إنت أصلاً موبايلك ده معمول بـcustomer
                // قبل كده».
                throw new ApiException(
                    'wrong_client',
                    "هذا الرقم مسجل ك{$user->type->label()} — سجّل الدخول بكلمة المرور",
                    403,
                    meta: ['account_type' => $user->type->value],
                );
            }

            if (! $user->is_active) {
                throw new ApiException('account_suspended', 'هذا الحساب موقوف', 423);
            }

            $user->forceFill(['phone_verified_at' => now(), 'last_login_at' => now()])->save();

            $token = $user->createToken('customer', [$user->type->value])->plainTextToken;

            return ['user' => $user, 'token' => $token, 'is_new_user' => $isNewUser];
        });
    }
}
