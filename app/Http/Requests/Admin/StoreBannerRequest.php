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
            'title_en' => [$required, 'string', 'max:255'],
            'image_ar' => [$required, 'image', 'max:5120'],
            'image_en' => [$required, 'image', 'max:5120'],
            'link_type' => ['nullable', 'in:restaurant,product,category,promo,url,none'],
            'link_value' => ['nullable', 'string'],
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
