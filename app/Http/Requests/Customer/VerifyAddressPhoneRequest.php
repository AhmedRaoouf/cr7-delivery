<?php

namespace App\Http\Requests\Customer;

/** D-112 — `POST /addresses/phone/verify`: the number, and the code it was sent. */
class VerifyAddressPhoneRequest extends AddressPhoneRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'code' => ['required', 'digits:6'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'code.required' => 'أدخل رمز التحقق',
            'code.digits' => 'رمز التحقق ‪6‬ أرقام',
        ];
    }
}
