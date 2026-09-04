<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalisesPhone;
use Illuminate\Foundation\Http\FormRequest;

class SendOtpRequest extends FormRequest
{
    use NormalisesPhone;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * OTP is how a customer both registers and signs in (FR-AUTH-01). Unnormalised,
     * `+20 109 000 9599` misses the existing row and `VerifyOtpAction` creates a SECOND
     * customer account for a number that already has one.
     */
    protected function prepareForValidation(): void
    {
        $this->normalisePhoneFields(['phone']);
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^01[0125][0-9]{8}$/'],
            'purpose' => ['nullable', 'in:login,register,reset,verify'],
        ];
    }
}
