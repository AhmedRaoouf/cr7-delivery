<?php

namespace App\Http\Resources\Shared;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * doc 08 §2 — GET /cities/{city}/zones. Bilingual for the same reason as
 * {@see CityResource}, and cast for the same reason.
 *
 * `radius_km` and `free_delivery_over` stay nullable: a polygon zone has no
 * radius and most zones have no free-delivery threshold, and a client that
 * reads `0.0` for «no threshold» would draw a «توصيل مجاني» chip on every
 * card. `(float) null` is `0.0`, so the null has to survive the cast.
 */
class ServiceZoneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'city_id' => $this->city_id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'polygon' => $this->polygon,
            'center_lat' => (float) $this->center_lat,
            'center_lng' => (float) $this->center_lng,
            'radius_km' => $this->radius_km !== null ? (float) $this->radius_km : null,
            'delivery_fee' => (float) $this->delivery_fee,
            'free_delivery_over' => $this->free_delivery_over !== null ? (float) $this->free_delivery_over : null,
            'min_order_value' => (float) $this->min_order_value,
            'avg_delivery_minutes' => (int) $this->avg_delivery_minutes,
            'is_active' => (bool) $this->is_active,
            // D-70 — false on the wide legacy circles, which resolve a pin but are not
            // one of the operator's priced areas. A picker that shows them puts a fee on
            // screen that is not on the flyer.
            'is_selectable' => (bool) $this->is_selectable,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
