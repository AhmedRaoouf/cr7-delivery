<?php

namespace App\Http\Requests\Auth;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `login` is a phone number and nothing else (operator, 2026-09-11).
     *
     * It is normalised before the rule runs, so `+20 109 000 9599`, `0020…`, `109…` and
     * `01090009599` are one account and an email is refused here rather than three layers
     * down as «بيانات الدخول غير صحيحة», which reads like a wrong password.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('login') && is_string($this->input('login'))) {
            $this->merge(['login' => PhoneNumber::normalise($this->input('login'))]);
        }
    }

    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'regex:'.PhoneNumber::PATTERN],
            'password' => ['required', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'login.required' => 'أدخل رقم الهاتف',
            'login.regex' => 'أدخل رقم هاتف صحيح',
            'password.required' => 'أدخل كلمة المرور',
        ];
    }
}
