<?php

namespace App\Http\Requests\Concerns;

use App\Support\PhoneNumber;

/**
 * The `login` field of sign-in, «نسيت كلمة المرور» and the reset that follows it.
 *
 * A phone, for every account type and every shell. D-107 let an admin type an email here;
 * the operator took it back on 2026-09-17 — «مفيش تسجيل بايميل خالص كله برقم الموبايل» —
 * so an email is refused by the field as a phone that is not a phone, never looked up.
 * The three requests share this so «phone only» cannot come back in one of them alone.
 *
 * Not a `prepareForValidation` of its own: a method of that name on the class would
 * silently win over the trait's, which is how an earlier trait lost its normalisation.
 */
trait ValidatesLogin
{
    /** Normalised before the rule runs: `+20 109 000 9599` is `01090009599`; ` a@b.com ` stays `a@b.com` and fails. */
    protected function normaliseLoginField(): void
    {
        if ($this->has('login') && is_string($this->input('login'))) {
            $this->merge(['login' => PhoneNumber::normaliseLogin($this->input('login'))]);
        }
    }

    /** @return list<string> */
    protected function loginRules(): array
    {
        return ['required', 'string', 'regex:'.PhoneNumber::PATTERN];
    }

    /** @return array<string, string> */
    protected function loginMessages(): array
    {
        return [
            // Every screen that sends `login` labels it «رقم الهاتف» — the app, the place's
            // dashboard and the admin's alike.
            'login.required' => 'أدخل رقم الهاتف',
            'login.regex' => 'أدخل رقم هاتف صحيح',
        ];
    }
}
