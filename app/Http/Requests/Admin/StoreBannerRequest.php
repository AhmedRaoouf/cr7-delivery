<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreBannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('banners.manage');
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'title_ar' => [$required, 'string', 'max:255'],
            // English copy and English artwork are optional: most banners carry one
            // piece of art and the app already falls back to the Arabic side. Making
            // them required forced a designer to produce two of everything, and the
            // dashboard could not create a banner at all.
            'title_en' => ['nullable', 'string', 'max:255'],
            'image_ar' => [$required, 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'image_en' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'link_type' => ['nullable', 'in:restaurant,product,category,promo,url,none'],
            'link_value' => ['nullable', 'string'],
            'audience' => ['nullable', 'in:all,first_order,returning'],
            'city_ids' => ['nullable', 'array'],
            'sort_order' => ['nullable', 'integer'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
            'remove_image_ar' => ['nullable', 'boolean'],
            'remove_image_en' => ['nullable', 'boolean'],
        ];
    }
}
