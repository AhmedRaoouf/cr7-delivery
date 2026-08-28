<?php

namespace App\Http\Requests\Shared;

use Illuminate\Foundation\Http\FormRequest;

/** APP-03 — public, unauthenticated: the applicant has no account yet (D-45). */
class StoreCaptainApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // D-62 — eleven digits, no separators and no country code, normalised on the
        // way in so what is stored is never what was typed.
        if ($this->filled('phone')) {
            $phone = preg_replace('/\D+/', '', (string) $this->input('phone'));
            $phone = preg_replace('/^(?:\+?20)?0?/', '0', $phone, 1);
            $this->merge(['phone' => $phone]);
        }
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^01[0125][0-9]{8}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'national_id' => ['nullable', 'string', 'max:20'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            // The app sends the city as the name it printed on the picker; an id is
            // accepted too, and the controller resolves whichever arrived.
            'city' => ['nullable', 'string', 'max:120'],
            'vehicle' => ['nullable', 'in:motorcycle,bicycle,car,scooter'],
            'vehicle_type' => ['nullable', 'in:motorcycle,bicycle,car,scooter'],
            'vehicle_plate' => ['nullable', 'string', 'max:20'],
            'licence_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'national_id_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'رقم الهاتف غير صحيح',
        ];
    }
}
