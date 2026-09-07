<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\Concerns\ValidatesAddressZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAddressRequest extends FormRequest
{
    use ValidatesAddressZone;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label' => ['required', 'in:home,work,other'],
            'title' => ['nullable', 'string', 'max:255'],
            'city_id' => ['required', 'exists:cities,id'],
            'zone_id' => ['required', 'exists:service_zones,id'],
            'street' => ['nullable', 'string', 'max:255'],
            'building' => ['nullable', 'string', 'max:255'],
            'floor' => ['nullable', 'string', 'max:255'],
            'apartment' => ['nullable', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->validateZoneAgainstPoint($v));
    }
}
