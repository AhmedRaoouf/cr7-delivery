<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * D-62 — a stored Egyptian mobile is eleven digits, no separators, no country code.
 *
 * This lived inside `NormalisesPhone`, which is a `FormRequest` concern, so only code
 * that happened to be a request could reach it. Every *lookup* — sign-in, forgot
 * password, OTP — matched the raw string instead, and `+20 109 000 9599` did not equal
 * the `01090009599` sitting in the row. The answer was «بيانات الدخول غير صحيحة» for
 * the correct number and the correct password.
 *
 * Normalising in one place is also the precondition for `unique:users,phone` meaning
 * anything: unnormalised, `+201090009599` and `01090009599` are two strings, and the
 * second account is created without complaint.
 */
final class PhoneNumber
{
    /**
     * `+20 109 000 9599`, `0020109…`, `109…` and `01090009599` all become `01090009599`.
     * Anything that is not one of those shapes comes back digits-only and is left for the
     * regex to reject — this normalises, it does not guess.
     */
    public static function normalise(string $raw): string
    {
        // Arabic-Indic digits paste out of most Egyptian keyboards and phone books.
        $digits = strtr($raw, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        $digits = preg_replace('/\D+/', '', $digits) ?? '';

        // Country code, however it was written: 0020…, 20…, +20 all reduce to the local form.
        if (str_starts_with($digits, '0020')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '20') && strlen($digits) > 10) {
            $digits = substr($digits, 2);
        }

        // A local number is 11 digits starting `01`. Ten digits starting `1` is the same
        // number with the trunk zero dropped, which is how it appears after a country code.
        if (strlen($digits) === 10 && str_starts_with($digits, '1')) {
            $digits = '0'.$digits;
        }

        return $digits;
    }

    /** The stored shape: eleven digits, `01` plus one of the four network digits. */
    public const PATTERN = '/^01[0125][0-9]{8}$/';

    /** True when the identifier is an email rather than a phone. */
    public static function isEmail(string $login): bool
    {
        return Str::contains($login, '@');
    }

    /**
     * Operator ruling 2026-09-11: «شيل تسجيل الدخول بالايميل». A `login` is a phone and
     * nothing else, so this only normalises — an email arrives here as its digits, which
     * is nothing, and `LoginRequest` refuses it before any lookup runs.
     */
    public static function normaliseLogin(string $login): string
    {
        return self::normalise($login);
    }

    /** True when the normalised form is a real Egyptian mobile. */
    public static function isValid(string $raw): bool
    {
        return (bool) preg_match(self::PATTERN, self::normalise($raw));
    }
}
