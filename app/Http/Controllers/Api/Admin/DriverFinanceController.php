<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Order;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\DeliveryCommission;
use App\Support\DriverLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * D-71 — «الأدمين بقى من عنده بيبقى شايف كل واحد أخد كام، وكل واحد بيبقى محسوب له قدامه،
 * وكل واحد عليه كام وكده وياخد منه كام».
 *
 * Three reads and one write: the fleet's balances, one captain's ledger, and the
 * settlement that moves money between the two meters {@see DriverLedger} defines.
 */
class DriverFinanceController extends Controller
{
    use FiltersLists;

    /** `GET /admin/finance/drivers` — every captain, what they hold and what they are owed. */
    public function index(Request $request): JsonResponse
    {
        $drivers = AdminScope::applyToDrivers(Driver::query(), $request->user())
            ->with(['user:id,name,phone', 'city:id,name_ar,name_en'])
            ->when($this->filterInt($request, 'city_id'), fn ($q, $cityId) => $q->where('city_id', $cityId))
            ->when($this->filterString($request, 'search') !== '', function ($q) use ($request) {
                $term = '%'.$this->filterString($request, 'search').'%';
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', $term)->orWhere('phone', 'like', $term));
            })
            // «مين عليه فلوس» is the question this screen is opened to answer, so the
            // captains carrying the most of the platform's money sort to the top.
            ->orderByDesc('cash_in_hand')
            ->paginate($request->integer('per_page', 20));

        $rows = collect($drivers->items())->map(fn (Driver $driver) => [
            'driver_id' => $driver->id,
            'name' => $driver->user->name,
            'phone' => $driver->user->phone,
            'city' => $driver->city?->name_ar,
            'is_online' => (bool) $driver->is_online,
            'is_active' => (bool) $driver->is_active,
            'earning_type' => $driver->earning_type,
            'earning_value' => (float) $driver->earning_value,
            ...DriverLedger::balances($driver),
        ]);

        // The page totals, and the platform's own side of it — what the fleet is
        // carrying against what the platform earned out of the same fees.
        $scoped = AdminScope::applyToDrivers(Driver::query(), $request->user());

        return ApiResponse::success($rows, meta: [
            'current_page' => $drivers->currentPage(),
            'per_page' => $drivers->perPage(),
            'total' => $drivers->total(),
            'last_page' => $drivers->lastPage(),
            'totals' => [
                'cash_in_hand' => round((float) (clone $scoped)->sum('cash_in_hand'), 2),
                'wallet_balance' => round((float) (clone $scoped)->sum('wallet_balance'), 2),
                'net_due_to_platform' => round(
                    (float) (clone $scoped)->sum('cash_in_hand') - (float) (clone $scoped)->sum('wallet_balance'),
                    2,
                ),
            ],
            'commission_rate' => DeliveryCommission::rate(),
        ]);
    }

    /** `GET /admin/drivers/{driver}/ledger` — one captain's balances and every row behind them. */
    public function ledger(Request $request, Driver $driver): JsonResponse
    {
        $this->ensureInScope($request, $driver);

        $transactions = $driver->transactions()
            ->with('order:id,order_number,payment_method,total,delivery_fee_share,driver_earning,platform_delivery_margin')
            ->when($this->filterString($request, 'type') !== '', fn ($q) => $q->where('type', $this->filterString($request, 'type')))
            ->latest()
            ->paginate($request->integer('per_page', 30));

        $rows = collect($transactions->items())->map(fn ($t) => [
            'id' => $t->id,
            'type' => $t->type,
            'meter' => DriverLedger::meterOf($t->type),
            'amount' => (float) $t->amount,
            'balance_after' => (float) $t->balance_after,
            'note' => $t->note,
            'created_at' => $t->created_at,
            'order' => $t->order === null ? null : [
                'id' => $t->order->id,
                'order_number' => $t->order->order_number,
                'payment_method' => $t->order->payment_method,
                'total' => (float) $t->order->total,
                'delivery_fee' => (float) $t->order->delivery_fee_share,
                'driver_earning' => (float) $t->order->driver_earning,
                'platform_margin' => (float) $t->order->platform_delivery_margin,
            ],
        ]);

        return ApiResponse::success($rows, meta: [
            'current_page' => $transactions->currentPage(),
            'per_page' => $transactions->perPage(),
            'total' => $transactions->total(),
            'last_page' => $transactions->lastPage(),
            'driver' => [
                'id' => $driver->id,
                'name' => $driver->user->name,
                'phone' => $driver->user->phone,
                'earning_type' => $driver->earning_type,
                'earning_value' => (float) $driver->earning_value,
            ],
            'balances' => DriverLedger::balances($driver),
            'lifetime' => $this->lifetime($driver),
        ]);
    }

    /**
     * `POST /admin/drivers/{driver}/settle` — the office received cash, and/or paid the
     * captain. Both optional, at least one required, applied together.
     */
    public function settle(Request $request, Driver $driver): JsonResponse
    {
        $this->ensureInScope($request, $driver);

        $validated = $request->validate([
            'cash_received' => ['nullable', 'numeric', 'min:0'],
            'paid_to_driver' => ['nullable', 'numeric', 'min:0'],
            'method' => ['nullable', 'in:cash,vodafone_cash,instapay'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $cashReceived = (float) ($validated['cash_received'] ?? 0);
        $paidToDriver = (float) ($validated['paid_to_driver'] ?? 0);

        if ($cashReceived <= 0 && $paidToDriver <= 0) {
            throw new ApiException('nothing_to_settle', 'أدخل مبلغًا واحدًا على الأقل', 422);
        }

        // A handover larger than what the captain is carrying is almost always a typo,
        // and letting it through leaves `cash_in_hand` negative — a number nobody can
        // reconcile later. The captain's own earnings are a separate meter and are
        // allowed to go negative, because a deduction legitimately can.
        if ($cashReceived > (float) $driver->cash_in_hand) {
            throw new ApiException(
                'amount_exceeds_cash_in_hand',
                'المبلغ أكبر من الكاش المسجَّل على الكابتن',
                422,
                meta: ['cash_in_hand' => (float) $driver->cash_in_hand],
            );
        }

        $result = DriverLedger::settle(
            $driver,
            $request->user(),
            $cashReceived,
            $paidToDriver,
            $validated['method'] ?? null,
            $validated['reference'] ?? null,
            $validated['note'] ?? null,
        );

        return ApiResponse::success($result, 'تم تسجيل التسوية بنجاح', status: 201);
    }

    /** Totals since the account was opened — what the ledger rows add up to. */
    private function lifetime(Driver $driver): array
    {
        $delivered = Order::query()->where('driver_id', $driver->id)->where('status', 'delivered');

        return [
            'orders_delivered' => (clone $delivered)->count(),
            'delivery_fees' => round((float) (clone $delivered)->sum('delivery_fee_share'), 2),
            'earned' => round((float) (clone $delivered)->sum('driver_earning'), 2),
            'platform_margin' => round((float) (clone $delivered)->sum('platform_delivery_margin'), 2),
            'cash_orders_total' => round((float) (clone $delivered)->where('payment_method', 'cash')->sum('total'), 2),
            'online_orders_total' => round((float) (clone $delivered)->where('payment_method', '!=', 'cash')->sum('total'), 2),
        ];
    }

    private function ensureInScope(Request $request, Driver $driver): void
    {
        abort_unless(
            AdminScope::applyToDrivers(Driver::query(), $request->user())->whereKey($driver->id)->exists(),
            404,
        );
    }
}
