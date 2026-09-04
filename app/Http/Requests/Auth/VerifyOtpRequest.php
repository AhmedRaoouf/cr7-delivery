<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalisesPhone;
use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    use NormalisesPhone;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The code was issued against the normalised number, so the verify has to look for
     * the same one — otherwise a correct code reads as «رمز التحقق غير صحيح».
     */
    protected function prepareForValidation(): void
    {
        $this->normalisePhoneFields(['phone']);
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
