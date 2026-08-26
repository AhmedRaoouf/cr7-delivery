<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class StoreComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => ['required', 'in:order_issue,late_delivery,missing_item,food_quality,driver_behaviour,payment,app_problem,other'],
            'order_id' => ['nullable', 'exists:orders,id'],
            'subject' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:2000'],
            'attachments' => ['nullable', 'array'],
        ];
    }
}
