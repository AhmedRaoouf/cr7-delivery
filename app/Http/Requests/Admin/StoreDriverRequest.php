<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\NormalisesPhone;
use Illuminate\Foundation\Http\FormRequest;

/** FR-DRV-01 — Admin creates a driver; the linked login is provisioned server-side. */
class StoreDriverRequest extends FormRequest
{
    use NormalisesPhone;

    protected function prepareForValidation(): void
    {
        $this->normalisePhoneFields();
    }

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
            'email' => ['nullable', 'email', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/', 'unique:users,email'],
            'national_id' => ['nullable', 'string'],
            // All four are optional and fall back to the platform defaults in
            // `CreateDriverAction`. The vehicle and the plate belong to the captain's
            // own application (`APP-03`), not to the Admin creating the login, and the
            // earning rule is a platform setting — asking an operator to retype 15 per
            // captain is how a fleet ends up on four different splits.
            'vehicle_type' => ['nullable', 'in:motorcycle,bicycle,car,scooter'],
            'vehicle_plate' => ['nullable', 'string'],
            'earning_type' => ['nullable', 'in:fixed,per_km,percent_of_delivery_fee'],
            'earning_value' => ['nullable', 'numeric', 'min:0'],
            'zone_ids' => ['nullable', 'array'],
            'zone_ids.*' => ['exists:service_zones,id'],
            // Optional here and required on `APP-03`, deliberately. An applicant is
            // holding their card while they fill the form in; an Admin creating an
            // account over the phone is not, and blocking the account on a photo they
            // cannot produce stops onboarding rather than improving it. `ADM-18` says
            // out loud which side is still missing.
            'national_id_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'national_id_back_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'رقم الهاتف غير صحيح — 11 رقمًا تبدأ بـ 010 أو 011 أو 012 أو 015',
            'phone.unique' => 'هذا الرقم مسجل بالفعل',
            'email.unique' => 'هذا البريد مسجل بالفعل',
        ];
    }
}
