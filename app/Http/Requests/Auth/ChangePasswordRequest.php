<?php

namespace App\Http\Requests\Auth;

use App\Support\Security\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => PasswordPolicy::rules(confirmed: true),
            // D-107 — required for an admin, and checked by `ChangePasswordAction`, which
            // knows who is asking; ignored for everyone else.
            'code' => ['nullable', 'digits:6'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.digits' => 'رمز التحقق ‪6‬ أرقام',
        ];
    }
}
