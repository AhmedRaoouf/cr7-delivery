<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\ValidatesLogin;
use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    use ValidatesLogin;

    public function authorize(): bool
    {
        return true;
    }

    /** A phone, for every account type — an email is refused (operator 2026-09-17). */
    protected function prepareForValidation(): void
    {
        $this->normaliseLoginField();
    }

    public function rules(): array
    {
        return ['login' => $this->loginRules()];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->loginMessages();
    }
}
