<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\ValidatesLogin;
use App\Support\Security\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    use ValidatesLogin;

    public function authorize(): bool
    {
        return true;
    }

    /** A phone — the same login «نسيت كلمة المرور» was given. An email is refused (operator 2026-09-17). */
    protected function prepareForValidation(): void
    {
        $this->normaliseLoginField();
    }

    public function rules(): array
    {
        return [
            'login' => $this->loginRules(),
            'code' => ['required', 'digits:6'],
            'password' => PasswordPolicy::rules(confirmed: true),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->loginMessages();
    }
}
