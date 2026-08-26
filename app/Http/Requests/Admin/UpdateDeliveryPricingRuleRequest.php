<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDeliveryPricingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('locations.manage_pricing');
    }

    public function rules(): array
    {
        return [
            'city_id' => ['sometimes', 'required', 'exists:cities,id'],
            'service_zone_id' => ['nullable', 'exists:service_zones,id'],
            'min_distance_km' => ['sometimes', 'required', 'numeric', 'min:0'],
            'max_distance_km' => ['sometimes', 'required', 'numeric', 'gt:min_distance_km'],
            'fee' => ['sometimes', 'required', 'numeric', 'min:0'],
            'free_delivery_over' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ];
    }
}
