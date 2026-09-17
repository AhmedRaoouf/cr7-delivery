<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\ValidatesLogin;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    use ValidatesLogin;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * `login` is a phone number, for every account type — an admin's included since the
     * operator removed D-107's email sign-in on 2026-09-17.
     *
     * It is normalised before the rule runs, so `+20 109 000 9599`, `0020…`, `109…` and
     * `01090009599` are one account. Something that is not a phone — an email included —
     * is refused here rather than three layers down as «بيانات الدخول غير صحيحة», which
     * reads like a wrong password.
     */
    protected function prepareForValidation(): void
    {
        $this->normaliseLoginField();
    }

    public function rules(): array
    {
        return [
            'login' => $this->loginRules(),
            'password' => ['required', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->loginMessages() + [
            'password.required' => 'أدخل كلمة المرور',
        ];
    }
}
