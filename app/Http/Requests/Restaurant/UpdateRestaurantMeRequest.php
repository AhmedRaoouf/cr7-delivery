<?php

namespace App\Http\Requests\Restaurant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRestaurantMeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'name_en' => ['sometimes', 'required', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'logo' => ['nullable', 'image', 'max:5120'],
            'cover' => ['nullable', 'image', 'max:5120'],
            'phone' => ['sometimes', 'required', 'string'],
            'whatsapp' => ['nullable', 'string'],
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
            'menu_sheets' => ['nullable', 'array', 'max:'.\App\Support\PdfPages::MAX_PAGES],
            'menu_sheets.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'remove_menu_sheets' => ['nullable', 'boolean'],
        ];
    }
}
