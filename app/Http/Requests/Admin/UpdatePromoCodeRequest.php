<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePromoCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('promos.manage');
    }

    /**
     * doc 96 — a code is typed in capitals by the customer's keyboard as often as not,
     * so it is stored in capitals; an empty place list means every place and is stored
     * as nothing, not as `[]`.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code'))) ?: null]);
        }

        if ($this->has('restrict_restaurant_ids')) {
            $ids = $this->input('restrict_restaurant_ids');
            $this->merge(['restrict_restaurant_ids' => is_array($ids) && $ids !== []
                ? array_values(array_map(fn ($id) => is_numeric($id) ? (int) $id : $id, $ids))
                : null]);
        }
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'الكود حروف إنجليزية وأرقام فقط، بلا مسافات',
            'code.unique' => 'هذا الكود مستخدم من قبل، اختر غيره',
            'restrict_restaurant_ids.*.exists' => 'أحد الأماكن المختارة غير موجود',
        ];
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'required', 'string', 'max:50', 'unique:promo_codes,code,'.$this->route('promoCode')?->id],
            'title_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'title_en' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', 'in:fixed,percent,free_delivery'],
            'value' => ['sometimes', 'required', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'min_order_value' => ['nullable', 'numeric', 'min:0'],
            'usage_limit_total' => ['nullable', 'integer', 'min:1'],
            'usage_limit_per_user' => ['nullable', 'integer', 'min:1'],
            'first_order_only' => ['nullable', 'boolean'],
            'restrict_city_ids' => ['nullable', 'array'],
            'restrict_zone_ids' => ['nullable', 'array'],
            'restrict_restaurant_ids' => ['nullable', 'array', 'max:200'],
            'restrict_restaurant_ids.*' => ['integer', 'distinct', Rule::exists('restaurants', 'id')->whereNull('deleted_at')],
            'cost_bearer' => ['nullable', 'in:platform,restaurant'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
