<?php

namespace App\Http\Requests\Admin;

use App\Support\PdfPages;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRestaurantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('restaurants.update');
    }

    public function rules(): array
    {
        return [
            'city_id' => ['sometimes', 'required', 'exists:cities,id'],
            'service_zone_id' => ['sometimes', 'required', 'exists:service_zones,id'],
            'store_category_id' => ['nullable', 'exists:store_categories,id'],
            'is_24_7' => ['nullable', 'boolean'],
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'name_en' => ['sometimes', 'required', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'phone' => ['sometimes', 'required', 'string'],
            'whatsapp' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'latitude' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
            'min_order_value' => ['nullable', 'numeric', 'min:0'],
            // doc 97 — added to every area's fee when this place is in the basket.
            'delivery_surcharge' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'avg_prep_minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
            // Accepted here, but not authorised here: `restaurants.set_commission`
            // is its own grant, and the controller refuses a value that would
            // actually move the number for an admin who does not hold it. Without
            // the rule the field was dropped by `validated()` and every edit from
            // the dashboard answered 200 having changed nothing.
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // The window the customer is shown. Both nullable: a shop that sets
            // neither keeps its zone's estimate, and one that sets them equal
            // publishes a single figure rather than a range — «‎35‎ دقيقة».
            'eta_min_minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
            'eta_max_minutes' => ['nullable', 'integer', 'min:1', 'max:240', 'gte:eta_min_minutes'],
            'menu_layout' => ['nullable', 'in:list,pager'],
            'sort_order' => ['nullable', 'integer'],
            'cuisine_ids' => ['nullable', 'array'],
            // `ADM-12` offered a logo picker the API had no rule for, so the file was
            // dropped and the response still answered 200 with `logo: null`. The
            // drawing and the contract agree now.
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_cover' => ['nullable', 'boolean'],
            // D-58 — the printed menu sheets, «المنيو», pill 0 of the tab strip.
            // The column has existed since 2026-08 and had no write path at all,
            // so the only sheets on the platform were the ones in the seeder.
            //
            // A PDF is allowed and is split into one image per page on the way in
            // ({@see \App\Support\PdfPages}), so the app needs no PDF reader and
            // the pages swipe and zoom like any other picture. 10 MB rather than
            // the 5 MB the logo gets: a scanned four-page menu is a big file and
            // the operator has no way to shrink one.
            'menu_sheets' => ['nullable', 'array', 'max:'.PdfPages::MAX_PAGES],
            'menu_sheets.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'remove_menu_sheets' => ['nullable', 'boolean'],
            // Which already-stored pages the form still shows. An upload adds to
            // the gallery rather than replacing it, so removing one page has to
            // be said out loud — see `HandlesUploads::withPublicSheets()`.
            'keep_menu_sheets' => ['nullable', 'array', 'max:'.PdfPages::MAX_PAGES],
            'keep_menu_sheets.*' => ['string', 'max:2048'],
            'cuisine_ids.*' => ['exists:cuisines,id'],
        ];
    }
}
