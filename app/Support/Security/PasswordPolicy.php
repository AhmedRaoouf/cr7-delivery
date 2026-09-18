<?php

namespace App\Support\Security;

use Illuminate\Validation\Rules\Password;

/**
 * Batch 67 — one rule for every password a person chooses.
 *
 * Registration asked for eight characters with a letter and a digit
 * (`Password::min(8)->letters()->numbers()`, which the app mirrors in
 * `PasswordRule`); the reset and the change asked only for eight characters, so
 * `12345678` — refused at sign-up — was accepted as the new password of a captain, a
 * place or the admin. The ceiling is the size of a request body, not a strength rule:
 * bcrypt reads 72 bytes and ignores the rest.
 */
final class PasswordPolicy
{
    /** @return list<mixed> */
    public static function rules(bool $confirmed = false): array
    {
        return array_values(array_filter([
            'required',
            'string',
            'max:128',
            $confirmed ? 'confirmed' : null,
            Password::min(8)->letters()->numbers(),
        ]));
    }
}
