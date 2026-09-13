<?php

namespace App\Http\Requests\Concerns;

use App\Support\PhoneNumber;

/**
 * The `login` field of sign-in, «نسيت كلمة المرور» and the reset that follows it.
 *
 * A phone for everybody, and since D-107 an email for an admin. The three requests each
 * carried their own copy of «phone only», so the email came back in all three here, in
 * one place. Whether an email *finds* anyone is not decided here — `User::scopeWhereLogin`
 * reaches admins only, and a place's email answers exactly like a wrong password.
 *
 * Not a `prepareForValidation` of its own: a method of that name on the class would
 * silently win over the trait's, which is how an earlier trait lost its normalisation.
 */
trait ValidatesLogin
{
    /** Normalised before the rule runs: `+20 109 000 9599` is `01090009599`, ` A@B.com ` is `a@b.com`. */
    protected function normaliseLoginField(): void
    {
        if ($this->has('login') && is_string($this->input('login'))) {
            $this->merge(['login' => PhoneNumber::normaliseLogin($this->input('login'))]);
        }
    }

    /** @return list<string> */
    protected function loginRules(): array
    {
        return PhoneNumber::isEmail((string) $this->input('login', ''))
            ? ['required', 'string', 'max:255', 'email']
            : ['required', 'string', 'regex:'.PhoneNumber::PATTERN];
    }

    /** @return array<string, string> */
    protected function loginMessages(): array
    {
        return [
            // The dashboard's field reads «البريد الإلكتروني أو رقم الهاتف»; the app's reads
            // «رقم الهاتف». The empty-field sentence follows the screen that sent it.
            'login.required' => $this->header('X-App') === 'admin'
                ? 'أدخل البريد الإلكتروني أو رقم الهاتف'
                : 'أدخل رقم الهاتف',
            'login.regex' => 'أدخل رقم هاتف صحيح',
            'login.email' => 'أدخل بريدًا إلكترونيًا صحيحًا',
        ];
    }
}
