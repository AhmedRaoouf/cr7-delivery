<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^01[0125][0-9]{8}$/'],
            'code' => ['required', 'digits:6'],
            'purpose' => ['nullable', 'in:login,register,reset,verify'],
        ];
    }
}
