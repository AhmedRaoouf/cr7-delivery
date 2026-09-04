<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\NormalisesPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDriverRequest extends FormRequest
{
    use NormalisesPhone;

    public function authorize(): bool
    {
        return $this->user()->can('drivers.update');
    }

    protected function prepareForValidation(): void
    {
        $this->normalisePhoneFields();
    }

    public function rules(): array
    {
        // `name`, `phone` and `email` live on `users`, not `drivers`. They were absent
        // here, so the edit form sent all three on every save and validation dropped
        // them — the screen reported success and the captain's name never changed.
        $userId = $this->route('driver')?->user_id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => [
                'sometimes', 'required', 'string', 'regex:/^01[0125][0-9]{8}$/',
                Rule::unique('users', 'phone')->ignore($userId),
            ],
            'email' => ['nullable', 'email', Rule::unique('users', 'email')->ignore($userId)],
            'city_id' => ['sometimes', 'required', 'exists:cities,id'],
            'national_id' => ['nullable', 'string'],
            'vehicle_type' => ['sometimes', 'required', 'in:motorcycle,bicycle,car,scooter'],
            'vehicle_plate' => ['nullable', 'string'],
            'earning_type' => ['sometimes', 'required', 'in:fixed,per_km,percent_of_delivery_fee'],
            'earning_value' => ['sometimes', 'required', 'numeric', 'min:0'],
            'zone_ids' => ['nullable', 'array'],
            'zone_ids.*' => ['exists:service_zones,id'],
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
