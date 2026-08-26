<?php

namespace App\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:national_id,license,vehicle_license,criminal_record,other'],
            'file' => ['required', 'string'],
            'expires_at' => ['nullable', 'date'],
        ];
    }
}
