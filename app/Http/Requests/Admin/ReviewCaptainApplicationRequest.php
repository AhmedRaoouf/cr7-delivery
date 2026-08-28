<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReviewCaptainApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:under_review,approved,rejected'],
            'rejection_reason' => ['required_if:status,rejected', 'nullable', 'string', 'max:255'],
            // Only read when status = approved: the account the admin is creating.
            'city_id' => ['required_if:status,approved', 'nullable', 'integer', 'exists:cities,id'],
            'earning_type' => ['nullable', 'in:fixed,per_km,percent_of_delivery_fee'],
            'earning_value' => ['nullable', 'numeric', 'min:0'],
            'zone_ids' => ['nullable', 'array'],
            'zone_ids.*' => ['integer', 'exists:service_zones,id'],
        ];
    }
}
