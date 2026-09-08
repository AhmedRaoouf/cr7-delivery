<?php

namespace App\Http\Resources\Shared;

use App\Enums\BannerLinkType;
use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * doc 08 §2 — one card for `GET /home-popups`, and the admin CRUD's echo of it.
 *
 * **`link_type` + `link_value`, exactly as `/banners` returns them.** The app resolves a
 * banner's destination in one place (`BannerDestination`, keyed on `link_type` and a
 * single `link_value` string), and the operator asked for the popup to land «في المكان
 * الصح بتاعه… زي العروض». Emitting the same pair means that resolver takes a popup
 * unchanged — including the detail that a `category` carries the store category's
 * **slug**, not its id, because that is what the home feed filters on
 * (`filter[store_category]`).
 *
 * The raw ids and both languages are returned beside the resolved values, the way
 * `/banners` returns its whole row: the dashboard's edit form needs the columns it
 * wrote, and a card with no title at all has to be distinguishable from one whose
 * English is missing.
 */
class HomePopupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = Locale::current($request);

        return [
            'id' => $this->id,
            // Nullable by design: a popup may be artwork plus the X and nothing else, so
            // the app must not substitute a default word for any of these.
            'title' => $this->{"title_{$locale}"},
            'body' => $this->{"body_{$locale}"},
            'image' => $this->image,

            // The destination, in the shape `/banners` already speaks.
            'link_type' => $this->link_type,
            'link_value' => $this->linkValue(),

            'restaurant_id' => $this->restaurant_id,
            'product_id' => $this->product_id,
            'store_category_id' => $this->store_category_id,
            'code' => $this->code,
            'url' => $this->url,

            // The buttons. A null primary label is «no button».
            'primary_label' => $this->{"primary_label_{$locale}"},
            'secondary_label' => $this->{"secondary_label_{$locale}"},
            'secondary_action' => $this->secondary_action,

            'frequency' => $this->frequency,
            // Who the card is for. The app never filters on it — the queue it
            // receives is already narrowed — but the dashboard reads the same
            // resource back into its own form.
            'audience' => $this->audience,
            'theme' => $this->theme,
            'sort_order' => $this->sort_order,
            'is_active' => (bool) $this->is_active,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),

            'title_ar' => $this->title_ar,
            'title_en' => $this->title_en,
            'body_ar' => $this->body_ar,
            'body_en' => $this->body_en,
            'primary_label_ar' => $this->primary_label_ar,
            'primary_label_en' => $this->primary_label_en,
            'secondary_label_ar' => $this->secondary_label_ar,
            'secondary_label_en' => $this->secondary_label_en,
        ];
    }

    /**
     * The one string a banner would have carried.
     *
     * A category resolves to its slug rather than its id: the app filters the home feed
     * by `filter[store_category]=<slug>`, and a slug survives the operator renaming the
     * category, which is the reason banners chose it in the first place.
     */
    private function linkValue(): ?string
    {
        $value = match ($this->link_type) {
            BannerLinkType::Restaurant => $this->restaurant_id,
            BannerLinkType::Product => $this->product_id,
            // Eager-loaded by both controllers; `?->` rather than a join because a
            // category deleted under a live popup is a null destination, not a 500.
            BannerLinkType::Category => $this->storeCategory?->slug,
            BannerLinkType::Promo => $this->code,
            BannerLinkType::Url => $this->url,
            default => null,
        };

        return $value === null ? null : (string) $value;
    }
}
