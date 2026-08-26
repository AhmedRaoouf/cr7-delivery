<?php

namespace App\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

class DeliverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'otp' => ['nullable', 'digits:4'],
            'collected_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
