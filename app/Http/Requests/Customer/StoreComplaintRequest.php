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
            // Files, not strings. `CUS-40` lets the customer attach up to three photos,
            // and they used to be sent as **device paths** — `/data/user/0/…/x.jpg` —
            // which were stored verbatim and rendered as a broken image everywhere the
            // ticket was read. The column keeps holding URLs; the transport is now a
            // multipart upload, so the URLs are ones this server wrote.
            'attachments' => ['nullable', 'array', 'max:3'],
            'attachments.*' => ['image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ];
    }
}
