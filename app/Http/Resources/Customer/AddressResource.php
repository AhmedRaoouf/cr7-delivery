<?php

namespace App\Http\Resources\Customer;

use App\Support\AddressCompleteness;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * doc 08 §4 — `GET /addresses`, and the answer every address verb gives.
 *
 * It exists for the same reason `CityResource` does: `latitude` and `longitude`
 * are `decimal:7` casts, and returning the model raw sent them as **strings**.
 * Every other geo payload on this API has been a resource since a client
 * crashed on that.
 *
 * It also carries three things the row does not, which the client was
 * reconstructing for itself and getting wrong:
 *
 * - the **names** of the city and the area, so no screen has to hold a
 *   directory of ids to print a line;
 * - `delivery_fee` — D-81, what delivery to this address costs, so choosing an
 *   area is visibly choosing a price;
 * - `effective_contact_phone` — D-83, who the captain will actually call once
 *   the blank field has fallen back to the account's own number;
 * - `is_complete` and `missing_fields` — D-111, what this address still needs
 *   before an order can be sent to it, named in Arabic, so «عناويني» can mark
 *   the row and the form can mark the fields before checkout refuses it;
 * - `uses_account_phone` — D-112, whether the number is the account's own
 *   (stored as null) rather than one the customer added and proved.
 */
class AddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $missing = AddressCompleteness::missing($this->resource);

        return [
            'id' => $this->id,
            'label' => $this->label,
            'title' => $this->title,
            'city_id' => $this->city_id,
            'city' => $this->whenLoaded('city', fn () => [
                'id' => $this->city->id,
                'name_ar' => $this->city->name_ar,
                'name_en' => $this->city->name_en,
            ]),
            'service_zone_id' => $this->service_zone_id,
            // Named `zone` to match the request field (`zone_id`) rather than
            // the column: `AddressController::mapZone()` is the one place the
            // two identities are allowed to disagree.
            'zone' => $this->whenLoaded('serviceZone', fn () => [
                'id' => $this->serviceZone->id,
                'name_ar' => $this->serviceZone->name_ar,
                'name_en' => $this->serviceZone->name_en,
                'delivery_fee' => (float) $this->serviceZone->delivery_fee,
            ]),
            'street' => $this->street,
            'building' => $this->building,
            'floor' => $this->floor,
            'apartment' => $this->apartment,
            'landmark' => $this->landmark,
            'contact_phone' => $this->contact_phone,
            // The address's **owner**, never `$request->user()`: the same
            // resource read by an operator would otherwise answer the
            // operator's own number as the one the captain will call.
            'effective_contact_phone' => $this->resolvedContactPhone(),
            'uses_account_phone' => blank($this->contact_phone) || $this->contact_phone === $this->user?->phone,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'delivery_fee' => $this->deliveryFee(),
            'formatted_address' => $this->composeText(),
            'is_default' => (bool) $this->is_default,
            'is_complete' => $missing === [],
            // `[{field, label, label_en}]`, in the order the form draws them.
            'missing_fields' => $missing,
        ];
    }
}
