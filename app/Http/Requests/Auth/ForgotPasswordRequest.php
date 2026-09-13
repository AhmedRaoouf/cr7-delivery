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

    /** A phone, or an admin's email (D-107) — the code goes to the phone on the row either way. */
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
