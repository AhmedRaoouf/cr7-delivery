<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalisesPhone;
use Illuminate\Foundation\Http\FormRequest;

class SocialLinkRequest extends FormRequest
{
    use NormalisesPhone;

    public function authorize(): bool
    {
        return true;
    }

    /** The code was issued against the normalised number, so the lookup has to use it too. */
    protected function prepareForValidation(): void
    {
        $this->normalisePhoneFields(['phone']);
    }

    public function rules(): array
    {
        return [
            'link_ticket' => ['required', 'string', 'size:64'],
            'phone' => ['required', 'string', 'regex:/^01[0125][0-9]{8}$/'],
            'code' => ['required', 'digits:6'],
        ];
    }
}
