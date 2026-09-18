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
use App\Support\Lists\ListSearch;
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
        $params = $this->listParams($request);
        $sort = $params->sort(['created_at', 'offered_fee'], '-created_at');

        // `errand_requests` carries `city_id` and `service_zone_id` under the same names
        // `orders` does, so the order scope applies verbatim (D-51).
        $query = AdminScope::applyToOrders(
            ErrandRequest::query()->with(['customer', 'driver.user']),
            $request->user(),
        );

        $this->whereInList($query, 'errand_requests.status', $params->enum('status', array_column(ErrandStatus::cases(), 'value')));
        $this->whereInList($query, 'errand_requests.city_id', $params->ids('city_id'));
        $this->whereInList($query, 'errand_requests.service_zone_id', $params->ids('zone_id'));
        $this->whereInList($query, 'errand_requests.driver_id', $params->ids('driver_id'));
        $this->whereInList($query, 'errand_requests.user_id', $params->ids('customer_id'));

        if ($params->bool('unclaimed') === true) {
            $query->where('errand_requests.status', ErrandStatus::Open->value)->whereNull('errand_requests.driver_id');
        }

        $this->whereBetweenDates($query, 'errand_requests.created_at', $params->dates());

        ListSearch::apply($query, $params->search(), [
            'refs' => ['reference'],
            'names' => ['pickup_address_text', 'dropoff_address_text'],
            'relations' => ['customer' => ['names' => ['name'], 'phones' => ['phone']]],
        ]);

        $errands = $sort->apply($query, [])->paginate($params->perPage());

        return $this->paginated(
            $errands,
            ErrandRequestResource::collection($errands->items()),
            [
                // The one number the alert dock and this screen must agree on — counted
                // inside the same scope, so a city admin's badge matches their own list.
                'unclaimed' => AdminScope::applyToOrders(ErrandRequest::query(), $request->user())
                    ->where('status', ErrandStatus::Open->value)
                    ->whereNull('driver_id')
                    ->count(),
            ],
            $sort,
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
