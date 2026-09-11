<?php

namespace App\Http\Resources\Customer;

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
            // «طلباتي» offers the rating too, and it has to agree with the receipt.
            ...RatingState::for($this->resource),
        ];
    }
}
