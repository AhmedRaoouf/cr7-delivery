<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Order;
use App\Models\User;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\DeliveryCommission;
use App\Support\DriverLedger;
use App\Support\Lists\ListSearch;
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
        $params = $this->listParams($request);
        $sort = $params->sort(['cash_in_hand', 'wallet_balance', 'name'], '-cash_in_hand');

        $query = AdminScope::applyToDrivers(Driver::query(), $request->user())
            ->with(['user:id,name,phone', 'city:id,name_ar,name_en']);

        $this->whereInList($query, 'drivers.city_id', $params->ids('city_id'));

        if (($zones = $params->ids('zone_id')) !== []) {
            $query->whereHas('assignedZones', fn ($z) => $z->whereIn('service_zones.id', $zones));
        }

        foreach (['is_online', 'is_active'] as $flag) {
            if (($value = $params->bool($flag)) !== null) {
                $query->where('drivers.'.$flag, $value);
            }
        }

        // «عليه فلوس» is the net, not the cash: what the captain carries less what the
        // platform already owes them — `DriverLedger::direction()`'s own threshold.
        if (($owes = $params->bool('owes')) !== null) {
            $query->whereRaw('(drivers.cash_in_hand - drivers.wallet_balance) '.($owes ? '>' : '<=').' 0.004');
        }

        ListSearch::apply($query, $params->search(), [
            'id' => false,
            'relations' => ['user' => ['names' => ['name'], 'phones' => ['phone']]],
        ]);

        // «مين عليه فلوس» is the question this screen is opened to answer, so the captains
        // carrying the most of the platform's money sort to the top.
        $drivers = $sort->apply($query, [
            'name' => fn ($q, string $direction) => $q->orderBy(
                User::query()->select('name')->whereColumn('users.id', 'drivers.user_id'),
                $direction,
            ),
        ])->paginate($params->perPage());

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

        // The page totals, and the platform's own side of it — what the fleet is carrying
        // against what the platform earned out of the same fees. The whole scope, not the
        // filter: the tiles above the table do not move when the table is searched.
        $totals = AdminScope::applyToDrivers(Driver::query(), $request->user())
            ->selectRaw('COALESCE(SUM(cash_in_hand), 0) as cash_in_hand, COALESCE(SUM(wallet_balance), 0) as wallet_balance')
            ->toBase()
            ->first();

        return $this->paginated($drivers, $rows, [
            'totals' => [
                'cash_in_hand' => round((float) $totals->cash_in_hand, 2),
                'wallet_balance' => round((float) $totals->wallet_balance, 2),
                'net_due_to_platform' => round((float) $totals->cash_in_hand - (float) $totals->wallet_balance, 2),
            ],
            'commission_rate' => DeliveryCommission::rate(),
        ], $sort);
    }

    /** `GET /admin/drivers/{driver}/ledger` — one captain's balances and every row behind them. */
    public function ledger(Request $request, Driver $driver): JsonResponse
    {
        $this->ensureInScope($request, $driver);

        $params = $this->listParams($request);
        $sort = $params->sort(['created_at'], '-created_at');

        $query = $driver->transactions()->getQuery()
            ->with('order:id,order_number,payment_method,total,delivery_fee_share,driver_earning,platform_delivery_margin');

        $this->whereInList($query, 'driver_transactions.type', $params->enum('type', [
            'order_earning', 'bonus', 'deduction', 'cash_settlement', 'cash_collected', 'cash_handover', 'payout', 'place_advance',
        ]));
        $this->whereBetweenDates($query, 'driver_transactions.created_at', $params->dates());

        $transactions = $sort->apply($query, [])->paginate($params->perPage(30));

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

        return $this->paginated($transactions, $rows, [
            'driver' => [
                'id' => $driver->id,
                'name' => $driver->user->name,
                'phone' => $driver->user->phone,
                'earning_type' => $driver->earning_type,
                'earning_value' => (float) $driver->earning_value,
            ],
            'balances' => DriverLedger::balances($driver),
            'lifetime' => $this->lifetime($driver),
        ], $sort);
    }

    /** `GET /admin/drivers/{driver}/unsettled` — doc 62 §4.3, the same document the captain reads. */
    public function unsettled(Request $request, Driver $driver): JsonResponse
    {
        $this->ensureInScope($request, $driver);

        return ApiResponse::success(DriverLedger::unsettled($driver) + [
            'driver' => [
                'id' => $driver->id,
                'name' => $driver->user->name,
                'phone' => $driver->user->phone,
            ],
        ]);
    }

    /**
     * `POST /admin/drivers/{driver}/settle` — the office received cash, and/or paid the
     * captain, and writes the statement both of them can open afterwards.
     *
     * `mode: full` is «تمت التسوية»: everything the captain carries comes in, everything
     * he is owed goes out, and only the difference physically changes hands. `partial`
     * records the two figures typed; what is left opens the next statement.
     */
    public function settle(Request $request, Driver $driver): JsonResponse
    {
        $this->ensureInScope($request, $driver);

        $validated = $request->validate([
            'mode' => ['nullable', 'in:full,partial'],
            'cash_received' => ['nullable', 'numeric', 'min:0'],
            'paid_to_driver' => ['nullable', 'numeric', 'min:0'],
            'method' => ['nullable', 'in:cash,vodafone_cash,instapay'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $full = ($validated['mode'] ?? 'partial') === 'full';
        $cashReceived = (float) ($validated['cash_received'] ?? 0);
        $paidToDriver = (float) ($validated['paid_to_driver'] ?? 0);

        if ($full) {
            if ((float) $driver->cash_in_hand <= 0 && (float) $driver->wallet_balance <= 0
                && $driver->transactions()->whereNull('settlement_id')->doesntExist()) {
                throw new ApiException('nothing_to_settle', 'لا توجد مبالغ معلّقة على هذا الكابتن', 422);
            }

            $result = DriverLedger::settle(
                $driver,
                $request->user(),
                0,
                0,
                $validated['method'] ?? null,
                $validated['reference'] ?? null,
                $validated['note'] ?? null,
                full: true,
            );

            return ApiResponse::success($result, 'تمت تسوية حساب الكابتن', status: 201);
        }

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
