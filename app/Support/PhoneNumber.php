<?php

namespace App\Support;

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

    /**
     * A `login` is a phone, for every account type — the email D-107 gave an admin is gone.
     *
     * Operator 2026-09-17: «مفيش تسجيل بايميل خالص كله برقم الموبايل». Normalised like any
     * phone — except that something which plainly is not one (an `@`, a letter, or no digit
     * at all) comes back as typed, trimmed, so the pattern refuses it as «أدخل رقم هاتف
     * صحيح». Stripped to its digits, `ops@example.com` would read as an empty field, and
     * `01000000099@x.com` as the admin's own number.
     */
    public static function normaliseLogin(string $login): string
    {
        $trimmed = trim($login);

        if (preg_match('/[@\p{L}]/u', $trimmed) === 1) {
            return $trimmed;
        }

        $digits = self::normalise($login);

        return $digits === '' ? $trimmed : $digits;
    }

    /**
     * D-107 — «أرسلنا الرمز إلى ‪010•••••99‬». Enough for an admin to recognise their own
     * phone when a password change sends it a code; not enough for anyone else to dial it. The
     * first three digits name the network, the last two are what a person remembers, and
     * the run between them is always five dots, so the mask says nothing about length.
     */
    public static function mask(string $phone): string
    {
        $digits = self::normalise($phone);

        if (strlen($digits) <= 5) {
            return '•••••';
        }

        return substr($digits, 0, 3).'•••••'.substr($digits, -2);
    }

    /** True when the normalised form is a real Egyptian mobile. */
    public static function isValid(string $raw): bool
    {
        return (bool) preg_match(self::PATTERN, self::normalise($raw));
    }
}
