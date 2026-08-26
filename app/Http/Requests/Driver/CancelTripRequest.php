<?php

namespace App\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

class CancelTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason_code' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
