<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\ValidatesLogin;
use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    use ValidatesLogin;

    public function authorize(): bool
    {
        return true;
    }

    /** A phone, or an admin's email (D-107) — the same login «نسيت كلمة المرور» was given. */
    protected function prepareForValidation(): void
    {
        $this->normaliseLoginField();
    }

    public function rules(): array
    {
        return [
            'login' => $this->loginRules(),
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->loginMessages();
    }
}
