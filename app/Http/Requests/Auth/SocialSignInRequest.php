<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class SocialSignInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', 'in:apple,google'],
            // Three base64url segments. The real check is the signature, in
            // `IdentityTokenVerifier`; this only keeps a 2 MB body out of it.
            'id_token' => ['required', 'string', 'min:20', 'max:8192'],
            // Apple hands the full name to the **client**, once, and never puts it on the
            // token. It is display copy, never identity — the `sub` inside the signed
            // token is the only thing that says who this is.
            'name' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'provider.in' => 'مزوّد الخدمة غير مدعوم',
            'id_token.required' => 'تعذّر التحقق من هويتك لدى مزوّد الخدمة',
        ];
    }
}
