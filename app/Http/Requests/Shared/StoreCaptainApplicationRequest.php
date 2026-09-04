<?php

namespace App\Http\Requests\Shared;

use App\Enums\CaptainApplicationStatus;
use App\Http\Requests\Concerns\NormalisesPhone;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

/** APP-03 — public, unauthenticated: the applicant has no account yet (D-45). */
class StoreCaptainApplicationRequest extends FormRequest
{
    use NormalisesPhone;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalisePhoneFields();
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:120'],
            // A phone that already has an account cannot apply: the applicant is asking
            // for the account they already hold, and approving would fail on
            // `users.phone` deep inside the transaction instead of here, on their screen.
            'phone' => [
                'required', 'string', 'regex:/^01[0125][0-9]{8}$/',
                'unique:users,phone',
                // Nor twice while the first one is still in the queue: two pending rows
                // for one number are two accounts waiting to collide on approval.
                Rule::unique('captain_applications', 'phone')->whereIn('status', [
                    CaptainApplicationStatus::Pending->value,
                    CaptainApplicationStatus::UnderReview->value,
                ]),
            ],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
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
            'phone.regex' => 'رقم الهاتف غير صحيح — 11 رقمًا تبدأ بـ 010 أو 011 أو 012 أو 015',
            'phone.unique' => 'هذا الرقم مسجل بالفعل. سجّل الدخول بدلًا من تقديم طلب جديد.',
            'email.unique' => 'هذا البريد مسجل بالفعل. سجّل الدخول بدلًا من تقديم طلب جديد.',
        ];
    }
}
