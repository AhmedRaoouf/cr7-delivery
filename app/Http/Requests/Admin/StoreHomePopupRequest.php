<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ValidatesHomePopupLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * «الأدمين بيحدد شكله من الداشبورد… يحط زرار، يحط زرارين، يظبط كل حاجة، الصورة، الكلام
 * على الصورة» — so almost nothing is required. What is checked is coherence: a card that
 * promises a destination has to have one.
 */
class StoreHomePopupRequest extends FormRequest
{
    use ValidatesHomePopupLink;

    /** The route carries `permission:banners.manage`; this is not a second gate. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title_ar' => ['nullable', 'string', 'max:255'],
            'title_en' => ['nullable', 'string', 'max:255'],
            'body_ar' => ['nullable', 'string', 'max:1000'],
            'body_en' => ['nullable', 'string', 'max:1000'],
            'image' => ['nullable', 'image', 'max:5120'],
            // The sibling boolean — see `HandlesUploads`. A multipart body cannot
            // carry a JSON null, so «take the artwork off» has to be its own field;
            // without it the dashboard could replace a picture but never remove one.
            'remove_image' => ['nullable', 'boolean'],

            'link_type' => ['nullable', 'in:restaurant,product,category,promo,url,none'],
            'audience' => ['nullable', 'in:all,first_order,returning'],
            'restaurant_id' => ['nullable', 'integer', 'exists:restaurants,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'store_category_id' => ['nullable', 'integer', 'exists:store_categories,id'],
            'code' => ['nullable', 'string', 'max:60'],
            // Validated as a URL here, not only required: a card that opens `htp://…`
            // is a button that fails on the customer's phone and nowhere else.
            'url' => ['nullable', 'url', 'max:2048'],

            'primary_label_ar' => ['nullable', 'string', 'max:60'],
            'primary_label_en' => ['nullable', 'string', 'max:60'],
            'secondary_label_ar' => ['nullable', 'string', 'max:60'],
            'secondary_label_en' => ['nullable', 'string', 'max:60'],
            'secondary_action' => ['nullable', 'in:dismiss,snooze,link'],

            'frequency' => ['nullable', 'in:always,once_per_day,once'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            // `#RRGGBB` or `#RRGGBBAA`, exactly like `store_categories.color`: free text
            // here reaches the app as a colour it has to parse, and a bad one is a crash
            // on the first screen the customer sees.
            'theme' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->validateHomePopupLink($v));
    }
}
