<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRestaurantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('restaurants.update');
    }

    public function rules(): array
    {
        return [
            'city_id' => ['sometimes', 'required', 'exists:cities,id'],
            'service_zone_id' => ['sometimes', 'required', 'exists:service_zones,id'],
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'name_en' => ['sometimes', 'required', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'phone' => ['sometimes', 'required', 'string'],
            'whatsapp' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'latitude' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
            'min_order_value' => ['nullable', 'numeric', 'min:0'],
            'avg_prep_minutes' => ['nullable', 'integer', 'min:1'],
            'menu_layout' => ['nullable', 'in:list,pager'],
            'sort_order' => ['nullable', 'integer'],
            'cuisine_ids' => ['nullable', 'array'],
            'cuisine_ids.*' => ['exists:cuisines,id'],
        ];
    }
}
