<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\Concerns\NamesAddressFields;
use App\Http\Requests\Concerns\ValidatesAddressZone;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAddressRequest extends FormRequest
{
    /*
     * `prepareForValidation` is aliased rather than simply overridden, and that
     * is load-bearing. A method defined on the class silently wins over the
     * trait's copy of the same name — no error, no warning — so declaring one
     * here to derive `city_id` quietly deleted {@see NamesAddressFields}'s
     * blank-to-null trim and phone normalisation, and «+20 109 000 9599» started
     * failing the eleven-digit rule it exists to satisfy.
     */
    use NamesAddressFields {
        prepareForValidation as private prepareAddressFields;
    }
    use ValidatesAddressZone;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label' => ['required', 'in:home,work,other'],
            // «غير ذلك» is the only label that does not name itself, so an
            // address filed under it with no title shows up in the book as a
            // row the customer cannot tell from the next one.
            'title' => ['nullable', 'required_if:label,other', 'string', 'max:255'],
            // Derived from `zone_id` when absent — see {@see ValidatesAddressZone::fillCityFromZone()}.
            // The app has no city step any more (operator ruling 2026-09-12).
            'city_id' => ['nullable', 'exists:cities,id'],
            'zone_id' => ['required', 'exists:service_zones,id'],
            // D-111 — no longer required to *save*. The first-launch address
            // (D-110) is a pin and an area, usually without a street, and refusing
            // it would lose the pin the customer did give. Whether an address may be
            // *ordered to* without one is `addresses.required_fields`, and checkout
            // says so by name (`address_incomplete`) before it takes the order.
            'street' => ['nullable', 'string', 'max:255'],
            'building' => ['nullable', 'string', 'max:255'],
            'floor' => ['nullable', 'string', 'max:255'],
            'apartment' => ['nullable', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'regex:'.PhoneNumber::PATTERN],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->prepareAddressFields();
        $this->fillCityFromZone();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->validateZoneAgainstPoint($v));
    }
}
