<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserType;
use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\Auth\Sessions;
use App\Support\Lists\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §7 — GET /admin/customers, /{id}, POST /{id}/status, GET /{id}/orders|addresses|complaints. */
class CustomerController extends Controller
{
    use FiltersLists;

    public function index(Request $request): JsonResponse
    {
        $params = $this->listParams($request);
        $sort = $params->sort(['created_at', 'name', 'last_login_at', 'orders_count'], '-created_at');

        $query = AdminScope::applyToCustomers(User::query()->where('users.type', UserType::Customer), $request->user())
            ->withCount('orderGroups as orders_count');

        // A customer's place is their default address — the same rule the scope reads.
        $cities = $params->ids('city_id');
        $zones = $params->ids('zone_id');

        if ($cities !== [] || $zones !== []) {
            $query->whereHas('defaultAddress', function ($address) use ($cities, $zones) {
                $this->whereInList($address, 'city_id', $cities);
                $this->whereInList($address, 'service_zone_id', $zones);
            });
        }

        if (($active = $params->bool('is_active')) !== null) {
            $query->where('users.is_active', $active);
        }

        if (($blocked = $params->bool('blocked')) !== null) {
            $blocked ? $query->whereNotNull('users.blocked_at') : $query->whereNull('users.blocked_at');
        }

        if (($restricted = $params->bool('restricted')) !== null) {
            $isRestricted = fn ($q) => $q->whereNotNull('users.cash_only_until')->where('users.cash_only_until', '>', now());
            $restricted ? $query->where($isRestricted) : $query->whereNot($isRestricted);
        }

        if (($hasOrders = $params->bool('has_orders')) !== null) {
            $hasOrders ? $query->whereHas('orderGroups') : $query->whereDoesntHave('orderGroups');
        }

        $this->whereBetweenDates($query, 'users.created_at', $params->dates());

        ListSearch::apply($query, $params->search(), [
            'names' => ['name'],
            'phones' => ['phone'],
            'emails' => ['email'],
        ]);

        $customers = $sort->apply($query, ['orders_count' => fn ($q, string $direction) => $q->orderBy('orders_count', $direction)])
            ->paginate($params->perPage());

        return $this->paginated($customers, sort: $sort);
    }

    public function show(Request $request, User $customer): JsonResponse
    {
        $this->ensureInScope($request, $customer);

        return ApiResponse::success($customer->load('defaultAddress'));
    }

    public function status(Request $request, User $customer): JsonResponse
    {
        $this->ensureInScope($request, $customer);

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $customer->update($validated);

        if (! $validated['is_active']) {
            Sessions::revokeAll($customer);
        }

        AuditLogger::record($request->user(), 'customers.toggle_status', $customer, $validated);

        return ApiResponse::success($customer->fresh(), 'تم تحديث حالة العميل بنجاح');
    }

    public function orders(Request $request, User $customer): JsonResponse
    {
        $this->ensureInScope($request, $customer);

        $params = $this->listParams($request);
        $sort = $params->sort(['created_at', 'total'], '-created_at');

        $query = $customer->orderGroups()->getQuery()->with('orders.restaurant');

        if (($groups = $params->enum('status_group', ['active', 'completed', 'cancelled'])) !== []) {
            $query->where(function ($q) use ($groups) {
                foreach ($groups as $group) {
                    match ($group) {
                        'active' => $q->orWhereNotIn('rollup_status', ['delivered', 'cancelled']),
                        'completed' => $q->orWhere('rollup_status', 'delivered'),
                        'cancelled' => $q->orWhere('rollup_status', 'cancelled'),
                    };
                }
            });
        }

        $this->whereBetweenDates($query, 'order_groups.created_at', $params->dates());

        $orders = $sort->apply($query, [])->paginate($params->perPage());

        return $this->paginated($orders, sort: $sort);
    }

    public function addresses(Request $request, User $customer): JsonResponse
    {
        $this->ensureInScope($request, $customer);

        return ApiResponse::success($customer->addresses);
    }

    public function complaints(Request $request, User $customer): JsonResponse
    {
        $this->ensureInScope($request, $customer);

        return ApiResponse::success($customer->complaints()->latest()->get());
    }

    public function stats(Request $request, User $customer): JsonResponse
    {
        $this->ensureInScope($request, $customer);

        $orderGroups = $customer->orderGroups();

        return ApiResponse::success([
            'orders_total' => (clone $orderGroups)->count(),
            'orders_delivered' => (clone $orderGroups)->where('rollup_status', 'delivered')->count(),
            'orders_cancelled' => (clone $orderGroups)->where('rollup_status', 'cancelled')->count(),
            'total_spent' => (float) (clone $orderGroups)->where('rollup_status', 'delivered')->sum('total'),
            'complaints_count' => $customer->complaints()->count(),
            'is_active' => $customer->is_active,
            'cash_only_until' => $customer->cash_only_until,
        ]);
    }

    private function ensureInScope(Request $request, User $customer): void
    {
        abort_unless($customer->type === UserType::Customer, 404);
        abort_unless(
            AdminScope::applyToCustomers(User::query()->where('type', UserType::Customer), $request->user())->whereKey($customer->id)->exists(),
            404,
        );
    }
}
