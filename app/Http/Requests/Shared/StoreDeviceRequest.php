<?php

namespace App\Http\Requests\Shared;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'platform' => ['required', 'in:android,ios,web'],
            'app' => ['required', 'in:app,restaurant,admin'],
            'device_id' => ['required', 'string'],
        ];
    }
}
