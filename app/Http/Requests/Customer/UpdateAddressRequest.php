<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\Concerns\NamesAddressFields;
use App\Http\Requests\Concerns\ValidatesAddressZone;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAddressRequest extends FormRequest
{
    /* Aliased, not overridden — see the note in {@see StoreAddressRequest}. */
    use NamesAddressFields {
        prepareForValidation as private prepareAddressFields;
    }
    use ValidatesAddressZone;

    public function authorize(): bool
    {
        // Batch 67 — ownership before validation. Validation ran first, so another
        // customer's row answered 422 where a missing one answered 404 — an existence
        // oracle for somebody else's address (doc 12 §5: violations are a 404).
        abort_unless($this->route('address')?->user_id === $this->user()?->id, 404);

        return true;
    }

    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'required', 'in:home,work,other'],
            'title' => ['nullable', 'required_if:label,other', 'string', 'max:255'],
            // Derived from `zone_id` when the client sends an area and no city —
            // the app has no city step any more (operator ruling 2026-09-12).
            'city_id' => ['sometimes', 'required', 'exists:cities,id'],
            'zone_id' => ['sometimes', 'required', 'exists:service_zones,id'],
            // D-111 — may be cleared; completeness is checkout's question, not the form's.
            'street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'building' => ['nullable', 'string', 'max:255'],
            'floor' => ['nullable', 'string', 'max:255'],
            'apartment' => ['nullable', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'regex:'.PhoneNumber::PATTERN],
            'latitude' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->prepareAddressFields();
        $this->fillCityFromZone();
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
