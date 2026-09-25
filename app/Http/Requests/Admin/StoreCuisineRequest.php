<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreCuisineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('content.manage');
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'max:255'],
            // Doc 82 — the English name may be left out (it defaults to the Arabic); the
            // picture may not: a file, a library pick (`image_media`) or a stored URL.
            'name_en' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string'],
            'image' => $this->hasFile('image')
                ? ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']
                : ['required_without:image_media', 'nullable', 'string'],
            'image_media' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
