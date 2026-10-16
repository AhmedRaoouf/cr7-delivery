<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\FallsBackToArabic;
use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceZoneRequest extends FormRequest
{
    use FallsBackToArabic;

    public function authorize(): bool
    {
        return $this->user()->can('locations.manage_zones');
    }

    protected function prepareForValidation(): void
    {
        $this->fillEnglishFromArabic($this, ['name_en' => 'name_ar'], $this->isMethod('post'));
    }

    public function rules(): array
    {
        return [
            'city_id' => ['sometimes', 'required', 'exists:cities,id'],
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'name_en' => ['sometimes', 'required', 'string', 'max:255'],
            'polygon' => ['nullable', 'array'],
            'center_lat' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            'center_lng' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'numeric', 'min:0'],
            'delivery_fee' => ['nullable', 'numeric', 'min:0'],
            'free_delivery_over' => ['nullable', 'numeric', 'min:0'],
            'min_order_value' => ['nullable', 'numeric', 'min:0'],
            'avg_delivery_minutes' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
