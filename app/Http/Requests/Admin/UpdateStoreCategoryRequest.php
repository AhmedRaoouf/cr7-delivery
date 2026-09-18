<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'slug' => ['nullable', 'string', 'max:120'],
            'name_ar' => ['sometimes', 'required', 'string', 'max:120'],
            'name_en' => ['sometimes', 'required', 'string', 'max:120'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'icon' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:2048'],
            // The sibling booleans — see `HandlesUploads`. A multipart body cannot carry
            // a JSON null, so «clear this» has to be its own field.
            'remove_image' => ['nullable', 'boolean'],
            'remove_icon' => ['nullable', 'boolean'],
            // `#RRGGBB` or `#RRGGBBAA`. Free text here reaches the app as a colour it
            // has to parse, and a bad one is a crash on the home screen.
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
