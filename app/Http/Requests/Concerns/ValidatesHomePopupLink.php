<?php

namespace App\Http\Requests\Concerns;

use App\Models\HomePopup;
use Illuminate\Validation\Validator;

/**
 * A popup that says «اطلب الآن» has to have somewhere to go.
 *
 * `link_type` and the five columns that could hold a destination are independent fields,
 * so nothing in the schema stops a card from being saved as `product` with no
 * `product_id` — and that card renders a button that swallows the tap, which is the
 * defect the banners already had («لما أجي أعمل طلب الآن يوديني على المنتج اللي على
 * العرض»). Checked here rather than with `required_if` because an update sends only the
 * fields it changes: switching a live card from `promo` to `product` must be judged
 * against what the row will be after the merge, not against what the request happens to
 * carry.
 */
trait ValidatesHomePopupLink
{
    protected function validateHomePopupLink(Validator $validator, ?HomePopup $existing = null): void
    {
        $data = $validator->getData();

        // Present-in-the-request wins; otherwise the row as it stands. A key sent
        // explicitly as null is a deliberate clearing and must not fall back.
        $value = function (string $key) use ($data, $existing) {
            if (array_key_exists($key, $data)) {
                return $data[$key];
            }

            $current = $existing?->getAttribute($key);

            return $current instanceof \BackedEnum ? $current->value : $current;
        };

        $destination = match ((string) ($value('link_type') ?? 'none')) {
            'restaurant' => ['restaurant_id', 'اختر المطعم الذي يفتحه الكرت.'],
            'product' => ['product_id', 'اختر المنتج الذي يفتحه الكرت.'],
            'category' => ['store_category_id', 'اختر التصنيف الذي يفتحه الكرت.'],
            'promo' => ['code', 'أدخل كود الخصم الذي يحمله الكرت.'],
            'url' => ['url', 'أدخل الرابط الذي يفتحه الكرت.'],
            default => null,
        };

        if ($destination !== null) {
            [$field, $message] = $destination;

            if (blank($value($field))) {
                $validator->errors()->add($field, $message);
            }
        }

        // «يحط زرار، يحط زرارين» — a second button that navigates but carries no words
        // is a blank rectangle beside «اطلب الآن». The Arabic label is the one checked:
        // it is what almost every customer will read, and `en` falls back to it.
        if ((string) ($value('secondary_action') ?? 'dismiss') === 'link'
            && blank($value('secondary_label_ar'))) {
            $validator->errors()->add(
                'secondary_label_ar',
                'أدخل نص الزر الثاني قبل ربطه بوجهة.',
            );
        }
    }
}
