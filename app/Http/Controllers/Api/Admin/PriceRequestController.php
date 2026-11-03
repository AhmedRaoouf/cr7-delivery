<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\PriceRequestResource;
use App\Models\ProductPriceRequest;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\Catalog\PriceApproval;
use App\Support\Lists\ListSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * doc 99 §1 — D-169, `ADM-60` موافقات الأسعار.
 *
 * `GET /admin/price-requests` (ADMIN-LISTS.md: `filter[status]` defaulting to `pending`,
 * `filter[restaurant_id]`, `filter[search]`, whitelisted sort, `per_page ≤ 100`),
 * `GET /admin/price-requests/{id}`, `POST …/{id}/approve {note?}`, `POST …/{id}/reject
 * {reason}`. A request is in scope when its place is ({@see AdminScope::applyToRestaurants});
 * out of scope is a 404, like a row that does not exist.
 */
class PriceRequestController extends Controller
{
    use FiltersLists;

    public function index(Request $request): JsonResponse
    {
        $params = $this->listParams($request);
        $sort = $params->sort(['created_at', 'reviewed_at', 'id'], '-created_at');

        $query = $this->scoped($request)->with(['product', 'restaurant', 'requester', 'reviewer']);

        // The queue opens on what is waiting; `all` (or a list) asks for history too.
        $statuses = $params->has('status')
            ? $params->enum('status', ProductPriceRequest::STATUSES)
            : [ProductPriceRequest::PENDING];

        $this->whereInList($query, 'product_price_requests.status', $statuses);
        $this->whereInList($query, 'product_price_requests.restaurant_id', $params->ids('restaurant_id'));
        $this->whereBetweenDates($query, 'product_price_requests.created_at', $params->dates());

        ListSearch::apply($query, $params->search(), [
            'relations' => [
                'product' => ['names' => ['name_ar', 'name_en']],
                'restaurant' => ['names' => ['name_ar', 'name_en']],
            ],
        ]);

        $page = $sort->apply($query, [])->paginate($params->perPage());

        return $this->paginated($page, PriceRequestResource::collection($page->getCollection()), sort: $sort);
    }

    public function show(Request $request, ProductPriceRequest $priceRequest): JsonResponse
    {
        $this->ensureInScope($request, $priceRequest);

        return ApiResponse::success(new PriceRequestResource($priceRequest->load(['product', 'restaurant', 'requester', 'reviewer'])));
    }

    public function approve(Request $request, ProductPriceRequest $priceRequest): JsonResponse
    {
        $this->ensureInScope($request, $priceRequest);
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $approved = PriceApproval::approve($priceRequest, $request->user(), $validated['note'] ?? null);

        return ApiResponse::success(
            new PriceRequestResource($approved->fresh()->load(['product', 'restaurant', 'requester', 'reviewer'])),
            'تم اعتماد السعر الجديد، وأصبح ظاهرًا للعملاء',
        );
    }

    public function reject(Request $request, ProductPriceRequest $priceRequest): JsonResponse
    {
        $this->ensureInScope($request, $priceRequest);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $rejected = PriceApproval::reject($priceRequest, $request->user(), $validated['reason']);

        return ApiResponse::success(
            new PriceRequestResource($rejected->fresh()->load(['product', 'restaurant', 'requester', 'reviewer'])),
            'تم رفض السعر الجديد، وأُبلغ المكان بالسبب',
        );
    }

    private function scoped(Request $request): Builder
    {
        return ProductPriceRequest::query()->whereHas(
            'restaurant',
            fn (Builder $places) => AdminScope::applyToRestaurants($places, $request->user()),
        );
    }

    private function ensureInScope(Request $request, ProductPriceRequest $priceRequest): void
    {
        AdminScope::abortUnlessVisible($this->scoped($request), $priceRequest);
    }

    /** Used by the dashboard's summary and the open-alerts dock. */
    public static function pendingQuery(Request $request): Builder
    {
        return ProductPriceRequest::query()
            ->where('status', ProductPriceRequest::PENDING)
            ->whereHas('restaurant', fn (Builder $places) => AdminScope::applyToRestaurants($places, $request->user()));
    }
}
