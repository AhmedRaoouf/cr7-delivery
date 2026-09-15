<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Errand\ErrandLifecycleAction;
use App\Enums\ErrandStatus;
use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Http\Resources\Shared\ErrandRequestResource;
use App\Models\Driver;
use App\Models\ErrandRequest;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\DriverTrip;
use App\Support\Geo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * doc 40 — «اطلب كابتن»: the operator's half.
 *
 * Exists for one reason: an errand nobody took. `ErrandDispatcher::escalateStranded`
 * notifies after five minutes; this is where the operator acts on that notification, by
 * picking a captain themselves.
 *
 * Scoped by city, the same way orders are (D-51): a Gamasa operator does not see New
 * Mansoura's errands, and an out-of-scope id answers **404**, never 403.
 */
class ErrandController extends Controller
{
    use FiltersLists;

    public function index(Request $request): JsonResponse
    {
        // `errand_requests` carries `city_id` and `service_zone_id` under the same names
        // `orders` does, so the order scope applies verbatim (D-51).
        $query = AdminScope::applyToOrders(
            ErrandRequest::query()->with(['customer', 'driver.user']),
            $request->user(),
        );

        if ($status = $request->query('filter.status') ?? $request->input('status')) {
            $query->where('status', $status);
        }

        if ($request->boolean('filter.unclaimed')) {
            $query->where('status', ErrandStatus::Open->value)->whereNull('driver_id');
        }

        if ($search = $request->input('search')) {
            $query->where(fn ($q) => $q
                ->where('reference', 'like', '%'.$search.'%')
                ->orWhere('pickup_address_text', 'like', '%'.$search.'%')
                ->orWhere('dropoff_address_text', 'like', '%'.$search.'%'));
        }

        $errands = $query->latest()->paginate($request->integer('per_page', 20));

        return ApiResponse::success(
            ErrandRequestResource::collection($errands->items()),
            meta: [
                'current_page' => $errands->currentPage(),
                'per_page' => $errands->perPage(),
                'total' => $errands->total(),
                'last_page' => $errands->lastPage(),
                // The one number the alert dock and this screen must agree on — counted
                // inside the same scope, so a city admin's badge matches their own list.
                'unclaimed' => AdminScope::applyToOrders(ErrandRequest::query(), $request->user())
                    ->where('status', ErrandStatus::Open->value)
                    ->whereNull('driver_id')
                    ->count(),
            ],
        );
    }

    public function show(Request $request, ErrandRequest $errand): JsonResponse
    {
        $this->ensureInScope($request, $errand);

        return ApiResponse::success(
            new ErrandRequestResource($errand->load(['customer', 'driver.user', 'events.actor'])),
        );
    }

    /** The captains an operator may hand this errand to, nearest to the pickup first. */
    public function eligibleDrivers(Request $request, ErrandRequest $errand): JsonResponse
    {
        $this->ensureInScope($request, $errand);

        $drivers = Driver::query()
            ->with('user')
            ->where('is_active', true)
            ->withCount(['orders as trip_count' => fn ($q) => $q->whereIn('status', DriverTrip::STATES)])
            ->get()
            ->map(fn (Driver $driver) => [
                'id' => $driver->id,
                'name' => $driver->user?->name,
                'phone' => $driver->user?->phone,
                'is_online' => (bool) $driver->is_online,
                'is_available' => (bool) $driver->is_available,
                'active_order_id' => $driver->active_order_id,
                // doc 63 gate 4 — a captain carrying orders cannot be handed an errand; the
                // count says why `assign` would refuse them.
                'trip_count' => (int) $driver->trip_count,
                'rating_avg' => (float) $driver->rating_avg,
                'distance_km' => $driver->current_lat === null || $driver->current_lng === null
                    ? null
                    : round(Geo::haversineKm(
                        (float) $driver->current_lat,
                        (float) $driver->current_lng,
                        (float) $errand->pickup_lat,
                        (float) $errand->pickup_lng,
                    ), 2),
            ])
            // Nulls last: a captain with no fix is still assignable, just not rankable.
            ->sortBy(fn (array $row) => $row['distance_km'] ?? PHP_FLOAT_MAX)
            ->values();

        return ApiResponse::success($drivers);
    }

    public function assign(Request $request, ErrandRequest $errand, ErrandLifecycleAction $action): JsonResponse
    {
        $this->ensureInScope($request, $errand);

        $validated = $request->validate([
            'driver_id' => ['required', 'exists:drivers,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $driver = Driver::query()->findOrFail($validated['driver_id']);

        return ApiResponse::success(
            new ErrandRequestResource($action->assign($request->user(), $errand, $driver, $validated['note'] ?? null)),
            'تم إرسال المشوار إلى الكابتن — بانتظار قبوله',
        );
    }

    public function cancel(Request $request, ErrandRequest $errand, ErrandLifecycleAction $action): JsonResponse
    {
        $this->ensureInScope($request, $errand);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return ApiResponse::success(
            new ErrandRequestResource($action->cancel($errand, $request->user(), 'admin', $validated['reason'])),
            'تم إلغاء المشوار',
        );
    }

    private function ensureInScope(Request $request, ErrandRequest $errand): void
    {
        abort_unless(
            AdminScope::applyToOrders(ErrandRequest::query(), $request->user())
                ->whereKey($errand->id)
                ->exists(),
            404,
        );
    }
}
