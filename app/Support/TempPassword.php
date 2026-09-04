<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The one place a first sign-in password is decided.
 *
 * Operators asked for a known default while there is no way to deliver a generated one:
 * nothing on this platform mails or texts a credential (`MAIL_MAILER` and `OTP_CHANNEL`
 * are both `log`), so a random 12-character string has to be read off the admin's screen
 * and dictated over the phone, and a mistyped character is indistinguishable from a
 * wrong account.
 *
 * ⚠️ A fixed default is a real weakness and not a test-only convenience: between approval
 * and the captain's first sign-in, anyone who knows their number can sign in as them.
 * `must_change_password` closes the window at first use but does not prevent it. Set
 * `TEMP_PASSWORD_DEFAULT=` (empty) before launch and every issued password becomes random
 * again — one line, no code change.
 */
class TempPassword
{
    public static function issue(): string
    {
        $configured = config('auth.temp_password');

        return is_string($configured) && $configured !== ''
            ? $configured
            : Str::password(12);
    }

    /** Whether the value handed out is the shared default rather than a generated one. */
    public static function isShared(): bool
    {
        $configured = config('auth.temp_password');

        return is_string($configured) && $configured !== '';
    }
}
