<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'registration_token' => ['required', 'string', 'size:64'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            // `confirmed` is deliberately not required: the app shows one field with a
            // reveal toggle, which is the pattern every Egyptian delivery app uses and
            // is measurably better than two fields on a phone keyboard.
            'password' => ['required', 'string', Password::min(8)->letters()->numbers()],
        ];
    }

    public function messages(): array
    {
        return [
            'password.min' => 'كلمة المرور يجب ألا تقل عن 8 أحرف',
            'name.required' => 'الاسم مطلوب',
        ];
    }
}
