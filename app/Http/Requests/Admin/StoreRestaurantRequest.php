<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\NormalisesPhone;
use Illuminate\Foundation\Http\FormRequest;

/** FR-REST-01 — Admin creates a restaurant; the linked owner login is provisioned server-side. */
class StoreRestaurantRequest extends FormRequest
{
    use NormalisesPhone;

    public function authorize(): bool
    {
        return $this->user()->can('restaurants.create');
    }

    /**
     * The owner's phone becomes a `users.phone` and carries `unique:users,phone`, which
     * only means something once the value is normalised. The branch `phone` is
     * deliberately left alone — that is a landline, not an Egyptian mobile, and putting
     * it through the mobile rule would rewrite a correct number.
     */
    protected function prepareForValidation(): void
    {
        $this->normalisePhoneFields(['owner_phone']);
    }

    public function rules(): array
    {
        return [
            'city_id' => ['required', 'exists:cities,id'],
            'service_zone_id' => ['required', 'exists:service_zones,id'],
            // Which of the home screen's cards this shop appears under. Nullable: a shop
            // with no category is still a shop, it just does not appear in the strip.
            'store_category_id' => ['nullable', 'exists:store_categories,id'],
            'is_24_7' => ['nullable', 'boolean'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'owner_phone' => ['required', 'string', 'regex:/^01[0125][0-9]{8}$/', 'unique:users,phone'],
            'owner_email' => ['nullable', 'email', 'unique:users,email'],
            'phone' => ['required', 'string'],
            'whatsapp' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'commission_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'min_order_value' => ['nullable', 'numeric', 'min:0'],
            'avg_prep_minutes' => ['nullable', 'integer', 'min:1'],
            // The window the customer is shown. Both nullable: a shop that sets
            // neither keeps its zone's estimate, and one that sets them equal
            // publishes a single figure rather than a range — «‎35‎ دقيقة».
            'eta_min_minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
            'eta_max_minutes' => ['nullable', 'integer', 'min:1', 'max:240', 'gte:eta_min_minutes'],
            // `ADM-12` offered a logo picker the API had no rule for, so the file was
            // dropped and the response still answered 200 with `logo: null`. The
            // drawing and the contract agree now.
            'logo' => ['nullable', 'image', 'max:5120'],
            'cover' => ['nullable', 'image', 'max:5120'],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_cover' => ['nullable', 'boolean'],
        ];
    }
}
