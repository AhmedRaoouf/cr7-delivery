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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §7 — GET /admin/customers, /{id}, POST /{id}/status, GET /{id}/orders|addresses|complaints. */
class CustomerController extends Controller
{
    use FiltersLists;

    public function index(Request $request): JsonResponse
    {
        $customers = AdminScope::applyToCustomers(User::query()->where('type', UserType::Customer), $request->user())
            ->when($this->filterString($request, 'search') !== '', function ($q) use ($request) {
                $term = '%'.$this->filterString($request, 'search').'%';
                $q->where(fn ($sq) => $sq->where('name', 'like', $term)->orWhere('phone', 'like', $term));
            })
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($customers->items(), meta: [
            'current_page' => $customers->currentPage(),
            'per_page' => $customers->perPage(),
            'total' => $customers->total(),
            'last_page' => $customers->lastPage(),
        ]);
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

        $orders = $customer->orderGroups()->with('orders.restaurant')->latest()->paginate($request->integer('per_page', 20));

        return ApiResponse::success($orders->items(), meta: [
            'current_page' => $orders->currentPage(),
            'per_page' => $orders->perPage(),
            'total' => $orders->total(),
            'last_page' => $orders->lastPage(),
        ]);
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
