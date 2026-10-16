<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\FallsBackToArabic;
use Illuminate\Foundation\Http\FormRequest;

class StoreCityRequest extends FormRequest
{
    use FallsBackToArabic;

    public function authorize(): bool
    {
        return $this->user()->can('locations.manage_cities');
    }

    protected function prepareForValidation(): void
    {
        $this->fillEnglishFromArabic($this, ['name_en' => 'name_ar'], $this->isMethod('post'));
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'center_lat' => ['required', 'numeric', 'between:-90,90'],
            'center_lng' => ['required', 'numeric', 'between:-180,180'],
            'pricing_type' => ['required', 'in:fixed_zone,distance_based'],
            'default_min_order' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ];
    }
}
