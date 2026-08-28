<?php

namespace App\Support;

/**
 * Every verification code in the platform is minted here.
 *
 * Before this, `random_int()` was written out at seven call sites — the login
 * code, the pickup code, the delivery code, both admin captain-swap verbs, the
 * direct-order pickup code and the admin force-pickup. A staging server with no
 * SMS gateway needs all seven to be predictable at once, and seven copies of a
 * rule is seven chances to miss one.
 *
 * @see config/otp.php — the switch, and the warning attached to it.
 */
final class Otp
{
    /** FR-AUTH-02 — the 6-digit code sent to a phone to sign in. */
    public static function authCode(): string
    {
        if (self::isStatic()) {
            return self::pad((string) config('otp.static_code', '123456'), 6);
        }

        return (string) random_int(100000, 999999);
    }

    /** doc 04 — the 4-digit pickup and delivery codes carried on an order. */
    public static function orderCode(): string
    {
        if (self::isStatic()) {
            return self::pad((string) config('otp.static_order_code', '1234'), 4);
        }

        return (string) random_int(1000, 9999);
    }

    public static function isStatic(): bool
    {
        return (bool) config('otp.static', false);
    }

    /**
     * The code to hand back on `POST /auth/otp/send`, or null to say nothing.
     *
     * Null unless the code is *static*: a random code echoed to its own
     * requester is not a second factor at all.
     */
    public static function echoable(): ?string
    {
        if (! self::isStatic() || ! config('otp.echo_in_response', false)) {
            return null;
        }

        return self::pad((string) config('otp.static_code', '123456'), 6);
    }

    /**
     * A misconfigured `OTP_STATIC_CODE=12` would otherwise sail through
     * `digits:6` validation on the way back in and lock everyone out of an
     * account they cannot report as broken.
     */
    private static function pad(string $code, int $length): string
    {
        $digits = preg_replace('/\D/', '', $code) ?: '';

        return str_pad(substr($digits, 0, $length), $length, '0', STR_PAD_LEFT);
    }
}
