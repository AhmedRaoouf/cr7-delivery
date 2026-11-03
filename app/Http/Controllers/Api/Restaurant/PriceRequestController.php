<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Models\ProductPriceRequest;
use App\Support\ApiResponse;
use App\Support\Catalog\PriceApproval;
use App\Support\Locale;
use App\Support\Media\MediaLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * doc 99 §1 — D-169, the place's side: what it asked the office, and the answers.
 *
 * `GET /restaurant/price-requests?filter[status]=pending|approved|rejected|…` (paginated,
 * every status when the filter is absent) and `DELETE /restaurant/price-requests/{id}` —
 * take a pending request back. Another place's request is a 404.
 */
class PriceRequestController extends Controller
{
    use FiltersLists;

    public function index(Request $request): JsonResponse
    {
        $restaurant = $request->user()->restaurant()->firstOrFail();
        $params = $this->listParams($request);
        $sort = $params->sort(['created_at', 'reviewed_at', 'id'], '-created_at');

        $query = ProductPriceRequest::query()
            ->where('restaurant_id', $restaurant->id)
            ->with('product');

        $this->whereInList($query, 'status', $params->enum('status', ProductPriceRequest::STATUSES));
        $this->whereInList($query, 'product_id', $params->ids('product_id'));

        $page = $sort->apply($query, [])->paginate($params->perPage());
        $locale = Locale::current($request);

        $rows = collect($page->items())->map(fn (ProductPriceRequest $row) => [
            'id' => $row->id,
            'status' => $row->status,
            'product' => $row->product === null ? null : [
                'id' => $row->product->id,
                'name' => $row->product->{"name_{$locale}"} ?: $row->product->name_ar,
                'image' => MediaLibrary::orPlaceholder($row->product->image),
            ],
            'current_price' => (float) $row->current_price,
            'current_discount_price' => $row->current_discount_price === null ? null : (float) $row->current_discount_price,
            'live_price' => $row->product === null ? null : (float) $row->product->price,
            'live_discount_price' => $row->product?->discount_price === null ? null : (float) $row->product->discount_price,
            'requested_price' => $row->requested_price === null ? null : (float) $row->requested_price,
            'requested_discount_price' => $row->requested_discount_price === null ? null : (float) $row->requested_discount_price,
            'clears_discount' => (bool) $row->clears_discount,
            'option_changes' => array_values($row->option_changes ?? []),
            'note' => $row->note,
            'created_at' => $row->created_at,
            'reviewed_at' => $row->reviewed_at,
            'review_note' => $row->review_note,
        ]);

        return $this->paginated($page, $rows, sort: $sort);
    }

    public function destroy(Request $request, ProductPriceRequest $priceRequest): JsonResponse
    {
        $restaurant = $request->user()->restaurant;
        abort_unless($restaurant && (int) $priceRequest->restaurant_id === (int) $restaurant->id, 404);

        $withdrawn = PriceApproval::withdraw($priceRequest);

        return ApiResponse::success(
            ['id' => $withdrawn->id, 'status' => $withdrawn->status],
            'تم سحب طلب تعديل السعر، ويبقى السعر الحالي كما هو',
        );
    }
}
