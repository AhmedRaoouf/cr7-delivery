<?php

namespace App\Support;

use App\Enums\OtpPurpose;
use App\Models\User;

/**
 * Every verification code in the platform is minted here.
 *
 * D-106 / D-108 — there is no fixed code any more unless the operator asks for one, and
 * the asking is done on `ADM-46`, not in `.env`. `OTP_STATIC_MODE` made one switch decide
 * two unrelated things: whether a customer's sign-up code was predictable, and whether
 * every pickup and delivery code on the road was `1234`. The second is not a staging
 * convenience, it is a captain who can close any order in town — so order codes are
 * random, always, and only the account codes have a test mode.
 *
 * Test mode is still refused for anything that reaches an **admin** (D-107): the
 * operator's words were «يبقا دا الوحيد اللي مفيش فيه غير sms في اي حاله حتي لو هو ثابت
 * للكل». A password change, and any code whose account is an admin, goes through the
 * bound SMS channel with a random code whatever `otp.mode` says.
 */
final class Otp
{
    public const MODE_SMS = 'sms';

    public const MODE_TEST = 'test';

    /** FR-AUTH-02 — the 6-digit code sent to a phone. */
    public static function authCode(OtpPurpose $purpose, ?User $account = null): string
    {
        return self::echoable($purpose, $account) ?? (string) random_int(100000, 999999);
    }

    /** doc 04 / D-108 — the 4-digit pickup and delivery codes carried on an order. */
    public static function orderCode(): string
    {
        return (string) random_int(1000, 9999);
    }

    /** `otp.mode = test` on the panel: one code, typed by the operator, and no SMS. */
    public static function testMode(): bool
    {
        return SettingsCatalog::get('otp.mode') === self::MODE_TEST;
    }

    /**
     * The operator's test code, six digits. A hand-edited row that is not six digits
     * already reads as the catalogue default; the pad is for the one that is shorter
     * after a stray character was stripped, which would otherwise fail `digits:6` on the
     * way back in and lock everyone out.
     */
    public static function testCode(): string
    {
        $digits = preg_replace('/\D/', '', (string) SettingsCatalog::get('otp.test_code')) ?: '';

        return str_pad(substr($digits, 0, 6), 6, '0', STR_PAD_LEFT);
    }

    /**
     * D-107 — codes test mode never reaches.
     *
     * A password change is always one, whoever asks. Any other purpose is one when the
     * account behind the phone is an admin: `reset` is the obvious case, but a `login` or
     * `verify` code for an admin's number is refused a fixed code for the same reason — a
     * code everybody knows must not be able to land anywhere near the panel.
     */
    public static function isAdminProtected(OtpPurpose $purpose, ?User $account = null): bool
    {
        return $purpose === OtpPurpose::PasswordChange || $account?->isAdmin() === true;
    }

    /** Whether this send uses the test code and skips the gateway. */
    public static function usesTestCode(OtpPurpose $purpose, ?User $account = null): bool
    {
        return self::testMode() && ! self::isAdminProtected($purpose, $account);
    }

    /**
     * The code to hand back to whoever asked for this send, or null to say nothing.
     *
     * Only ever the test code, and only when test mode applies to this send: a random
     * code echoed to its own requester is not a second factor at all, and an admin's code
     * is never echoed because it is never the test code.
     */
    public static function echoable(OtpPurpose $purpose, ?User $account = null): ?string
    {
        return self::usesTestCode($purpose, $account) ? self::testCode() : null;
    }

    /**
     * What `GET /config` publishes as `auth.static_otp` — the test code while test mode is
     * on, for the non-admin flows it covers. The per-send `static_otp` is the one to trust:
     * it is null on a send test mode does not reach.
     */
    public static function publishedTestCode(): ?string
    {
        return self::testMode() ? self::testCode() : null;
    }
}
