<?php

namespace App\Http\Controllers\Api\Customer;

use App\Actions\Errand\CreateErrandAction;
use App\Actions\Errand\ErrandLifecycleAction;
use App\Actions\Errand\QuoteErrandAction;
use App\Enums\ErrandStatus;
use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Errand\QuoteErrandRequest;
use App\Http\Requests\Errand\StoreErrandRequest;
use App\Http\Resources\Shared\ErrandRequestResource;
use App\Models\ErrandRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 40 — «اطلب كابتن»: the customer's half. */
class ErrandController extends Controller
{
    use FiltersLists;

    public function quote(QuoteErrandRequest $request, QuoteErrandAction $action): JsonResponse
    {
        return ApiResponse::success($action->execute(
            (float) $request->validated('pickup_lat'),
            (float) $request->validated('pickup_lng'),
            (float) $request->validated('dropoff_lat'),
            (float) $request->validated('dropoff_lng'),
        ));
    }

    public function store(StoreErrandRequest $request, CreateErrandAction $action): JsonResponse
    {
        $errand = $action->execute($request->user(), $request->validated());

        return ApiResponse::success(
            new ErrandRequestResource($errand),
            'تم نشر المشوار — جارٍ إشعار الكباتن المتاحين',
            status: 201,
        );
    }

    public function index(Request $request): JsonResponse
    {
        $params = $this->listParams($request);
        $sort = $params->sort(['created_at'], '-created_at');

        $query = ErrandRequest::query()
            ->where('user_id', $request->user()->id)
            ->with(['driver.user']);

        $this->whereInList($query, 'errand_requests.status', $params->enum('status', array_column(ErrandStatus::cases(), 'value')));

        $errands = $sort->apply($query, [])->paginate($params->perPage());

        return $this->paginated($errands, ErrandRequestResource::collection($errands->items()), sort: $sort);
    }

    public function show(Request $request, ErrandRequest $errand): JsonResponse
    {
        abort_unless($errand->user_id === $request->user()->id, 404);

        return ApiResponse::success(
            new ErrandRequestResource($errand->load(['driver.user', 'events'])),
        );
    }

    public function cancel(Request $request, ErrandRequest $errand, ErrandLifecycleAction $action): JsonResponse
    {
        abort_unless($errand->user_id === $request->user()->id, 404);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return ApiResponse::success(
            new ErrandRequestResource($action->cancel($errand, $request->user(), 'customer', $validated['reason'] ?? null)),
            'تم إلغاء المشوار',
        );
    }
}
