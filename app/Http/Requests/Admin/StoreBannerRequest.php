<?php

namespace App\Http\Requests\Admin;

use App\Models\Banner;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Models\StoreCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('banners.manage');
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'title_ar' => [$required, 'string', 'max:255'],
            // English copy and English artwork are optional: most banners carry one
            // piece of art and the app already falls back to the Arabic side. Making
            // them required forced a designer to produce two of everything, and the
            // dashboard could not create a banner at all.
            'title_en' => ['nullable', 'string', 'max:255'],
            'image_ar' => [$required, 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'image_en' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'link_type' => ['nullable', 'in:restaurant,product,category,promo,url,none'],
            'link_value' => ['nullable', 'string'],
            'audience' => ['nullable', 'in:all,first_order,returning'],
            'city_ids' => ['nullable', 'array'],
            'sort_order' => ['nullable', 'integer'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
            'remove_image_ar' => ['nullable', 'boolean'],
            'remove_image_en' => ['nullable', 'boolean'],
        ];
    }

    /**
     * 2026-10-14 — the destination has to exist. «بيف بيكاتا من رملة» was saved as place `1`
     * and «سماش تشيكرز» as product `6`, both سندوتش عمر's, because a typed number was
     * stored as it came. A slide that opens the wrong shop is worse than one that opens
     * nothing, so an id, slug or code that names nothing is refused here.
     *
     * Checked only when the request touches the destination, so switching an old slide
     * off is never blocked by what it already points at.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->has('link_type') && ! $this->has('link_value')) {
                return;
            }

            $existing = $this->route('banner');
            $existing = $existing instanceof Banner ? $existing : null;
            $type = (string) ($this->input('link_type') ?? $existing?->link_type?->value ?? 'none');
            $value = trim((string) ($this->has('link_value') ? $this->input('link_value') : $existing?->link_value));

            if ($type === 'none') {
                return;
            }

            if ($value === '') {
                $validator->errors()->add('link_value', __('اختر وجهة البانر.'));

                return;
            }

            $found = match ($type) {
                'restaurant' => ctype_digit($value) && Restaurant::query()->whereKey((int) $value)->exists(),
                'product' => ctype_digit($value) && Product::query()->whereKey((int) $value)->exists(),
                'category' => StoreCategory::query()->where('slug', $value)->exists(),
                'promo' => PromoCode::query()->where('code', $value)->exists(),
                'url' => filter_var($value, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $value) === 1,
                default => true,
            };

            if (! $found) {
                $validator->errors()->add('link_value', match ($type) {
                    'restaurant' => __('هذا المكان غير موجود.'),
                    'product' => __('هذا المنتج غير موجود.'),
                    'category' => __('هذا التصنيف غير موجود.'),
                    'promo' => __('كود الخصم هذا غير موجود.'),
                    default => __('الرابط غير صالح.'),
                });
            }
        }];
    }
}
