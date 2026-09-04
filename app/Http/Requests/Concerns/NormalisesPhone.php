<?php

namespace App\Http\Requests\Concerns;

use App\Support\PhoneNumber;

/**
 * D-62 — eleven digits, no separators and no country code, on the way in.
 *
 * The implementation is `PhoneNumber`; this is only the `FormRequest` ergonomics. It used
 * to hold the algorithm itself, which meant a lookup outside a request could not reach it
 * and matched the raw string instead — the sign-in defect written up on `PhoneNumber`.
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

            $this->merge([$key => PhoneNumber::normalise((string) $this->input($key))]);
        }
    }

    /** Kept so existing callers and tests keep working; `PhoneNumber` is the truth. */
    public static function normalisePhone(string $raw): string
    {
        return PhoneNumber::normalise($raw);
    }
}
