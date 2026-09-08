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
            'store_category_id' => ['nullable', 'exists:store_categories,id'],
            'is_24_7' => ['nullable', 'boolean'],
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
            // The window the customer is shown. Both nullable: a shop that sets
            // neither keeps its zone's estimate, and one that sets them equal
            // publishes a single figure rather than a range — «‎35‎ دقيقة».
            'eta_min_minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
            'eta_max_minutes' => ['nullable', 'integer', 'min:1', 'max:240', 'gte:eta_min_minutes'],
            'menu_layout' => ['nullable', 'in:list,pager'],
            'sort_order' => ['nullable', 'integer'],
            'cuisine_ids' => ['nullable', 'array'],
            // `ADM-12` offered a logo picker the API had no rule for, so the file was
            // dropped and the response still answered 200 with `logo: null`. The
            // drawing and the contract agree now.
            'logo' => ['nullable', 'image', 'max:5120'],
            'cover' => ['nullable', 'image', 'max:5120'],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_cover' => ['nullable', 'boolean'],
            'cuisine_ids.*' => ['exists:cuisines,id'],
        ];
    }
}
