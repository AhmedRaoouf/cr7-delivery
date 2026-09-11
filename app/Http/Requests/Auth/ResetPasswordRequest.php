<?php

namespace App\Http\Requests\Auth;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Phone only, since the email sign-in went (operator, 2026-09-11). */
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
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'login.required' => 'أدخل رقم الهاتف',
            'login.regex' => 'أدخل رقم هاتف صحيح',
        ];
    }
}
