<?php

namespace App\Http\Resources\Customer;

use App\Support\Catalog\RatingBadge;
use App\Support\Locale;
use App\Support\RatingState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** doc 08 §4 — GET /orders list item. */
class OrderGroupListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = Locale::current($request);

        return [
            'id' => $this->id,
            'group_number' => $this->group_number,
            'rollup_status' => $this->rollup_status,
            'restaurant_count' => $this->restaurant_count,
            'total' => (float) $this->total,
            'placed_at' => $this->placed_at,
            'restaurants' => $this->orders->map(fn ($order) => $order->restaurant->{"name_{$locale}"}),
            // doc 99 §6 — the same places with their mark and rating, for a row that draws
            // them; `restaurants` stays the list of names an older app reads.
            'places' => $this->orders->map(fn ($order) => [
                'id' => $order->restaurant->id,
                'name' => $order->restaurant->{"name_{$locale}"},
                'logo' => $order->restaurant->logo,
                'rating_badge' => RatingBadge::for($order->restaurant),
            ])->values(),
            // «طلباتي» offers the rating too, and it has to agree with the receipt.
            ...RatingState::for($this->resource),
        ];
    }
}
