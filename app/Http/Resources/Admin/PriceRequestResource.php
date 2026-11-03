<?php

namespace App\Http\Resources\Admin;

use App\Models\ProductOption;
use App\Models\ProductPriceRequest;
use App\Support\Catalog\PriceApproval;
use App\Support\Locale;
use App\Support\Media\MediaLibrary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * doc 99 §1 — `ADM-60` موافقات الأسعار: one request, beside what is live now.
 *
 * `current_*` are the live values when the place filed the request; `live_*` are the
 * live values now (an admin may have edited the dish since). `delta` is the requested
 * effective price (discount when there is one) minus the filed one. Each option change
 * carries its `live_price_delta` for the same reason.
 *
 * @mixin ProductPriceRequest
 */
class PriceRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = Locale::current($request);
        $product = $this->product;
        $restaurant = $this->restaurant;
        $prices = PriceApproval::effectivePrices($this->resource);

        $liveDeltas = ProductOption::query()
            ->whereIn('id', collect($this->option_changes ?? [])->pluck('option_id')->map(fn ($id) => (int) $id))
            ->pluck('price_delta', 'id');

        return [
            'id' => $this->id,
            'status' => $this->status,
            'restaurant' => $restaurant === null ? null : [
                'id' => $restaurant->id,
                'name' => $restaurant->{"name_{$locale}"} ?: $restaurant->name_ar,
                'logo' => $restaurant->logo,
            ],
            'product' => $product === null ? null : [
                'id' => $product->id,
                'name' => $product->{"name_{$locale}"} ?: $product->name_ar,
                'image' => MediaLibrary::orPlaceholder($product->image),
                'is_deleted' => $product->trashed(),
            ],
            'current_price' => (float) $this->current_price,
            'current_discount_price' => $this->current_discount_price === null ? null : (float) $this->current_discount_price,
            'live_price' => $product === null ? null : (float) $product->price,
            'live_discount_price' => $product?->discount_price === null ? null : (float) $product->discount_price,
            'requested_price' => $this->requested_price === null ? null : (float) $this->requested_price,
            'requested_discount_price' => $this->requested_discount_price === null ? null : (float) $this->requested_discount_price,
            'clears_discount' => (bool) $this->clears_discount,
            'option_changes' => collect($this->option_changes ?? [])->map(fn (array $row) => $row + [
                'live_price_delta' => isset($liveDeltas[(int) $row['option_id']]) ? (float) $liveDeltas[(int) $row['option_id']] : null,
            ])->values(),
            'delta' => round($prices['requested'] - $prices['current'], 2),
            'requested_by' => $this->requester === null ? null : ['id' => $this->requester->id, 'name' => $this->requester->name],
            'note' => $this->note,
            'created_at' => $this->created_at,
            'reviewed_by' => $this->reviewer === null ? null : ['id' => $this->reviewer->id, 'name' => $this->reviewer->name],
            'reviewed_at' => $this->reviewed_at,
            'review_note' => $this->review_note,
        ];
    }
}
