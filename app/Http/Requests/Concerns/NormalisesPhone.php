<?php

namespace App\Http\Requests\Concerns;

/**
 * D-62 — eleven digits, no separators and no country code.
 *
 * `StoreCaptainApplicationRequest` normalised on the way in and every other request that
 * validates a phone did not, so the same number an applicant could submit was rejected
 * when an admin typed it: «رقم الهاتف غير صحيح» for `+20 109 000 9599`, which is the
 * number, spelled the way a person writes it down.
 *
 * Normalising in one place is also what makes `unique:users,phone` mean anything. Stored
 * unnormalised, `+201090009599` and `01090009599` are two different strings and the
 * uniqueness rule lets the second account through.
 */
trait NormalisesPhone
{
    /** @param  list<string>  $keys */
    protected function normalisePhoneFields(array $keys = ['phone']): void
    {
        foreach ($keys as $key) {
            if (! $this->filled($key)) {
                continue;
            }

            $this->merge([$key => self::normalisePhone((string) $this->input($key))]);
        }
    }

    /**
     * `+20 109 000 9599`, `0020109…`, `109…` and `01090009599` all become `01090009599`.
     * Anything that is not one of those shapes is returned digits-only and left for the
     * regex to reject — this normalises, it does not guess.
     */
    public static function normalisePhone(string $raw): string
    {
        // Arabic-Indic digits paste out of a lot of Egyptian keyboards and phone books.
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
}
