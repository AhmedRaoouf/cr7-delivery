<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\Concerns\NormalisesPhone;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

/**
 * D-112 — `POST /addresses/phone/send-code` and `…/verify`. The number is normalised the
 * way the address stores it, so the code is issued, checked and recorded against the same
 * eleven digits the address form will later send.
 */
class AddressPhoneRequest extends FormRequest
{
    use NormalisesPhone;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalisePhoneFields(['phone']);
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:'.PhoneNumber::PATTERN],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.required' => 'أدخل رقم الهاتف',
            'phone.regex' => 'أدخل رقم هاتف مصري صحيح مكونًا من ‪11‬ رقمًا يبدأ بـ ‪01‬.',
        ];
    }
}
