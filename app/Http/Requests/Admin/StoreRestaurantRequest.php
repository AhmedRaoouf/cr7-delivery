<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** FR-REST-01 — Admin creates a restaurant; the linked owner login is provisioned server-side. */
class StoreRestaurantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('restaurants.create');
    }

    public function rules(): array
    {
        return [
            'city_id' => ['required', 'exists:cities,id'],
            'service_zone_id' => ['required', 'exists:service_zones,id'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'owner_phone' => ['required', 'string', 'regex:/^01[0125][0-9]{8}$/', 'unique:users,phone'],
            'owner_email' => ['nullable', 'email', 'unique:users,email'],
            'phone' => ['required', 'string'],
            'whatsapp' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'commission_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'min_order_value' => ['nullable', 'numeric', 'min:0'],
            'avg_prep_minutes' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
