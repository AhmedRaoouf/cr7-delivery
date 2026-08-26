<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeliveryPricingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('locations.manage_pricing');
    }

    public function rules(): array
    {
        return [
            'city_id' => ['required', 'exists:cities,id'],
            'service_zone_id' => ['nullable', 'exists:service_zones,id'],
            'min_distance_km' => ['required', 'numeric', 'min:0'],
            'max_distance_km' => ['required', 'numeric', 'gt:min_distance_km'],
            'fee' => ['required', 'numeric', 'min:0'],
            'free_delivery_over' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ];
    }
}
