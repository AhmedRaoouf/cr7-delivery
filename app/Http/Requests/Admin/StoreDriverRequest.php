<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** FR-DRV-01 — Admin creates a driver; the linked login is provisioned server-side. */
class StoreDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('drivers.create');
    }

    public function rules(): array
    {
        return [
            'city_id' => ['required', 'exists:cities,id'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^01[0125][0-9]{8}$/', 'unique:users,phone'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'national_id' => ['nullable', 'string'],
            'vehicle_type' => ['required', 'in:motorcycle,bicycle,car,scooter'],
            'vehicle_plate' => ['nullable', 'string'],
            'earning_type' => ['required', 'in:fixed,per_km,percent_of_delivery_fee'],
            'earning_value' => ['required', 'numeric', 'min:0'],
            'zone_ids' => ['nullable', 'array'],
            'zone_ids.*' => ['exists:service_zones,id'],
        ];
    }
}
