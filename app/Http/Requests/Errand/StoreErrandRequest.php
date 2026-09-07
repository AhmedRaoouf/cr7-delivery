<?php

namespace App\Http\Requests\Errand;

use Illuminate\Foundation\Http\FormRequest;

class StoreErrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pickup_lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['required', 'numeric', 'between:-180,180'],
            'pickup_address_text' => ['required', 'string', 'max:500'],
            'pickup_contact_phone' => ['nullable', 'string', 'max:32'],
            'dropoff_lat' => ['required', 'numeric', 'between:-90,90'],
            'dropoff_lng' => ['required', 'numeric', 'between:-180,180'],
            'dropoff_address_text' => ['required', 'string', 'max:500'],
            'dropoff_contact_phone' => ['nullable', 'string', 'max:32'],
            // The one field that carries the whole job. `min:10` because «طرد» alone is
            // not an instruction anybody can ride on, and a captain who accepts a job
            // they cannot understand is a cancellation with extra steps.
            'details' => ['required', 'string', 'min:10', 'max:2000'],
            'offered_fee' => ['required', 'numeric', 'min:1', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'details.min' => 'اكتب تفاصيل المشوار بوضوح — ماذا تريد بالضبط؟',
            'offered_fee.min' => 'أدخل المبلغ الذي ستدفعه للكابتن.',
        ];
    }
}
