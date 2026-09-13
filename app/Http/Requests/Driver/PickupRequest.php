<?php

namespace App\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

class PickupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // D-108 — required by the action when the order's own snapshot says so; an order
        // placed with the pickup code switched off takes no code at all.
        return ['otp' => ['nullable', 'digits:4']];
    }
}
