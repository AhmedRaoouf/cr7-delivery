<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\Concerns\ValidatesAddressZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAddressRequest extends FormRequest
{
    use ValidatesAddressZone;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'required', 'in:home,work,other'],
            'title' => ['nullable', 'string', 'max:255'],
            'city_id' => ['sometimes', 'required', 'exists:cities,id'],
            'zone_id' => ['sometimes', 'required', 'exists:service_zones,id'],
            'street' => ['nullable', 'string', 'max:255'],
            'building' => ['nullable', 'string', 'max:255'],
            'floor' => ['nullable', 'string', 'max:255'],
            'apartment' => ['nullable', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string'],
            'latitude' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    /**
     * A PATCH may carry the zone without the pin, or the pin without the zone. Either way
     * the pair has to be checked, so the missing half is filled in from the stored row —
     * otherwise moving an address into a cheaper area, alone, skipped the guard entirely.
     */
    public function withValidator(Validator $validator): void
    {
        $address = $this->route('address');

        if ($address !== null) {
            $validator->setData($validator->getData() + [
                'latitude' => $address->latitude,
                'longitude' => $address->longitude,
                'zone_id' => $address->service_zone_id,
            ]);
        }

        $validator->after(fn (Validator $v) => $this->validateZoneAgainstPoint($v));
    }
}
