<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'unique:users,email,'.$this->user()->id],
            'avatar' => ['nullable', 'image', 'max:5120'],
            'locale' => ['nullable', 'in:ar,en'],
            'theme' => ['nullable', 'in:dark,light,system'],
            'remove_avatar' => ['nullable', 'boolean'],
        ];
    }
}
