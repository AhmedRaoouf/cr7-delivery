<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('drivers.update');
    }

    public function rules(): array
    {
        return [
            'city_id' => ['sometimes', 'required', 'exists:cities,id'],
            'national_id' => ['nullable', 'string'],
            'vehicle_type' => ['sometimes', 'required', 'in:motorcycle,bicycle,car,scooter'],
            'vehicle_plate' => ['nullable', 'string'],
            'zone_ids' => ['nullable', 'array'],
            'zone_ids.*' => ['exists:service_zones,id'],
        ];
    }
}
