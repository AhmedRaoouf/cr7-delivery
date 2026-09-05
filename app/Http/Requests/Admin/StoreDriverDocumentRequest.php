<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreDriverDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('drivers.review_documents');
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:national_id,national_id_back,license,vehicle_license,criminal_record,other'],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'expires_at' => ['nullable', 'date'],
        ];
    }
}
