<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class SubmitPaymentProofRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'reference' => ['nullable', 'string', 'max:255'],
            'sender_number' => ['nullable', 'string', 'max:255'],
            'amount_claimed' => ['required', 'numeric', 'min:0'],
        ];
    }
}
