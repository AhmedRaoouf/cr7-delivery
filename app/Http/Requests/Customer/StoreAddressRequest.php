<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\Concerns\NamesAddressFields;
use App\Http\Requests\Concerns\ValidatesAddressZone;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAddressRequest extends FormRequest
{
    use NamesAddressFields, ValidatesAddressZone;

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
            'city_id' => ['required', 'exists:cities,id'],
            'zone_id' => ['required', 'exists:service_zones,id'],
            // D-83 — the street is the whole of most Gamasa addresses, and an
            // order whose destination is an empty string is a delivery nobody
            // can make. The app has required it since `CUS-24` was built; the
            // server did not, so any other client could save one.
            'street' => ['required', 'string', 'max:255'],
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->validateZoneAgainstPoint($v));
    }
}
