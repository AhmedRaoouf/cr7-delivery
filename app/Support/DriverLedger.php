<?php

namespace App\Support;

use App\Models\Driver;
use App\Models\DriverTransaction;
use App\Models\Order;
use App\Models\Settlement;
use App\Models\User;
use App\Support\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * D-71 — a captain's two meters, and the one number that settles them.
 *
 * Operator, 2026-09-05: «لو هي كاش بتروح لحساب الدليفري … لو هي إنستا باي أو فودافون كاش
 * بتروح لحساب الأدمن. وبعد كده بيتصفوا في الآخر مع بعض … كل واحد بيبقى محسوب له قدامه،
 * وكل واحد عليه كام».
 *
 * ## The two meters
 *
 * | Meter | Whose money | Grows when | Shrinks when |
 * |---|---|---|---|
 * | `cash_in_hand`  | the platform's | the captain takes cash from a customer | the captain hands it to the office |
 * | `wallet_balance`| the captain's («مستحقاتك») | a delivery completes · **he pays a place at pickup** | the platform pays them |
 *
 * D-103 (2026-09-13) — «الكابتن يدفع للمكان وقت الاستلام». On a cash order the captain
 * pays the place its own figure when he takes the bag, out of his own pocket, and
 * collects the whole cash total at the door. From the second he pays, the platform owes
 * him that money back — so `place_advance` is written to the wallet **at pickup**, and a
 * delivery that dies after pickup leaves the platform owing him the food he paid for
 * rather than leaving him short with nothing on the books.
 *
 * They are deliberately **not** one column. A captain carrying 1 000 EGP of customers'
 * money and owed 200 EGP of their own is in a completely different situation from one
 * carrying nothing and owed 800, and a single netted figure tells the office neither.
 *
 * ## The settlement number
 *
 *     net_due_to_platform = cash_in_hand − wallet_balance
 *
 * Positive, the captain pays the office; negative, the office pays the captain. The
 * operator's own example is the direct-order case, where the customer pays nothing but
 * the delivery fee: 1 000 EGP collected, 850 earned at the standard 85 %, 150 due — which
 * is what this returns.
 *
 * A worked cash order — food 100, delivery 20: `place_advance` +100 at pickup,
 * `cash_collected` +120 and `order_earning` +17 at the door; `120 − (100 + 17) = 3`, which
 * is the platform's 15 % of the fee. The same basket paid online is `order_earning` +17
 * and nothing else: the platform owes him 17.
 */
class DriverLedger
{
    /**
     * The numbers every screen on both sides shows — and which way the net points, in
     * words, so no screen has to decide whether a minus sign means «you owe» or «owed».
     */
    public static function balances(Driver $driver): array
    {
        $cash = (float) $driver->cash_in_hand;
        $wallet = (float) $driver->wallet_balance;
        $net = round($cash - $wallet, 2);

        return [
            'cash_in_hand' => round($cash, 2),
            'wallet_balance' => round($wallet, 2),
            'net_due_to_platform' => $net,
            ...self::direction($net),
        ];
    }

    /** @return array{direction: string, label: string, label_en: string, amount: float} */
    public static function direction(float $net): array
    {
        return match (true) {
            $net > 0.004 => ['direction' => 'captain_owes', 'label' => 'على الكابتن للمنصة', 'label_en' => 'The captain owes the platform', 'amount' => round($net, 2)],
            $net < -0.004 => ['direction' => 'platform_owes', 'label' => 'للكابتن عند المنصة', 'label_en' => 'The platform owes the captain', 'amount' => round(-$net, 2)],
            default => ['direction' => 'settled', 'label' => 'لا توجد مبالغ معلّقة', 'label_en' => 'Nothing outstanding', 'amount' => 0.0],
        };
    }

    /** A delivery completed: the captain earned their share of the fee. */
    public static function recordEarning(Driver $driver, Order $order): DriverTransaction
    {
        $driver->increment('wallet_balance', (float) $order->driver_earning);

        return $driver->transactions()->create([
            'order_id' => $order->id,
            'type' => 'order_earning',
            'amount' => (float) $order->driver_earning,
            'balance_after' => (float) $driver->fresh()->wallet_balance,
            'created_by' => null,
        ]);
    }

    /**
     * An errand completed («اطلب كابتن»).
     *
     * D-75 makes the platform's whole income 15 % of the delivery money, and an errand's
     * fee **is** delivery money — but `ErrandLifecycleAction` moved none of it: no wallet
     * credit, no cash row, no margin anywhere. A captain doing errands all day earned
     * nothing the ledger knew about, and the platform's share of every one of them was
     * lost. The two rows are written exactly as a food order's are: the captain's 85 %
     * into the wallet, and the fee they are holding in cash against the cash meter, so
     * `net_due_to_platform` comes out at the 15 %.
     *
     * `order_id` is null and the reference goes in the note: an errand has no order row,
     * and inventing one to carry a ledger line would be worse.
     *
     * @return array{earning: DriverTransaction, cash: DriverTransaction|null}
     */
    public static function recordErrand(Driver $driver, string $reference, float $driverEarning, float $cashFee, ?int $errandId = null): array
    {
        $driver->increment('wallet_balance', round($driverEarning, 2));

        $earning = $driver->transactions()->create([
            'order_id' => null,
            'errand_request_id' => $errandId,
            'type' => 'order_earning',
            'amount' => round($driverEarning, 2),
            'balance_after' => (float) $driver->fresh()->wallet_balance,
            'note' => 'مشوار '.$reference,
            'created_by' => null,
        ]);

        if ($cashFee <= 0) {
            return ['earning' => $earning, 'cash' => null];
        }

        $driver->increment('cash_in_hand', round($cashFee, 2));

        return [
            'earning' => $earning,
            'cash' => $driver->transactions()->create([
                'order_id' => null,
                'errand_request_id' => $errandId,
                'type' => 'cash_collected',
                'amount' => round($cashFee, 2),
                'balance_after' => (float) $driver->fresh()->cash_in_hand,
                'note' => 'رسوم مشوار '.$reference,
                'created_by' => null,
            ]),
        ];
    }

    /**
     * The customer paid cash. The captain is now holding the platform's money.
     *
     * `amount` is positive and `balance_after` is `cash_in_hand`, not the wallet:
     * the row belongs to the cash meter and reading it against the wallet — which is
     * what the first version of this did — made every cash trip look like it left the
     * wallet untouched at a figure it had never held.
     */
    public static function recordCashCollected(Driver $driver, Order $order, float $amount): DriverTransaction
    {
        $driver->increment('cash_in_hand', $amount);

        return $driver->transactions()->create([
            'order_id' => $order->id,
            'type' => 'cash_collected',
            'amount' => round($amount, 2),
            'balance_after' => (float) $driver->fresh()->cash_in_hand,
            'created_by' => null,
        ]);
    }

    /**
     * D-103 — the captain paid the place at pickup. Written once per order: a forced
     * pickup and the real one cannot pay the place twice.
     */
    public static function recordPlaceAdvance(Driver $driver, Order $order): ?DriverTransaction
    {
        $amount = $order->placePaidAtPickup();

        if ($amount <= 0) {
            return null;
        }

        $already = $driver->transactions()
            ->where('order_id', $order->id)
            ->where('type', 'place_advance')
            ->exists();

        if ($already) {
            return null;
        }

        $driver->increment('wallet_balance', $amount);
        $order->forceFill(['place_paid_amount' => $amount])->save();

        return $driver->transactions()->create([
            'order_id' => $order->id,
            'type' => 'place_advance',
            'amount' => $amount,
            'balance_after' => (float) $driver->fresh()->wallet_balance,
            'note' => 'دفعه للمكان عند الاستلام',
            'created_by' => null,
        ]);
    }

    /**
     * doc 62 §4.3 — every ledger row no statement has closed yet, grouped the way the
     * operator settles: «خد منه فلوسه الأوردرات اللي عملها وخد فلوسها كاش، وعطيته فلوسه
     * الأوردرات اللي اتبعتت إنستا باي أو فودافون كاش».
     *
     * The captain's own screen and the office's settle dialog read this same document, so
     * «عليك ‪3‬» on the phone and «على الكابتن ‪3‬» at the counter cannot be two numbers.
     *
     * `opening_balance` is whatever the current meters hold that these rows do not explain
     * — the closing figure of the last partial settlement, or a balance written before the
     * ledger kept rows. It is what makes `opening + Σ rows = net_due_to_platform` exact.
     *
     * @return array<string, mixed>
     */
    public static function unsettled(Driver $driver): array
    {
        $rows = $driver->transactions()
            ->whereNull('settlement_id')
            ->with([
                'order:id,order_number,payment_method,origin,delivered_at,picked_up_at,status,total,delivery_fee_share,driver_earning,free_delivery_reason',
                'errandRequest:id,reference,status',
            ])
            ->orderBy('id')
            ->get();

        $cashOrders = [];
        $onlineOrders = [];
        $errands = [];
        $adjustments = [];
        $exchanges = [];

        foreach ($rows->whereNotNull('order_id')->groupBy('order_id') as $orderRows) {
            $order = $orderRows->first()->order;
            $collected = round((float) $orderRows->where('type', 'cash_collected')->sum('amount'), 2);
            $advance = round((float) $orderRows->where('type', 'place_advance')->sum('amount'), 2);
            $earning = round((float) $orderRows->where('type', 'order_earning')->sum('amount'), 2);

            $line = [
                'order_id' => $order?->id,
                'order_number' => $order?->order_number,
                'payment_method' => $order?->payment_method?->value,
                'status' => $order?->status?->value,
                'delivered_at' => $order?->delivered_at?->toIso8601String(),
                'delivery_fee' => round((float) ($order?->delivery_fee_share ?? 0), 2),
                'free_delivery_reason' => $order?->free_delivery_reason,
                'earning' => $earning,
            ];

            if ($order?->payment_method?->value === 'cash' || $collected > 0 || $advance > 0) {
                $cashOrders[] = $line + [
                    'collected' => $collected,
                    'paid_to_place' => $advance,
                    // What of this order's cash is the platform's once the captain has
                    // taken back the food he paid for and his own share of the fee.
                    'platform_share' => round($collected - $advance - $earning, 2),
                ];
            } else {
                $onlineOrders[] = $line;
            }
        }

        $errandRows = $rows->whereNull('order_id')->whereIn('type', ['order_earning', 'cash_collected']);

        foreach ($errandRows->groupBy(fn ($row) => $row->errand_request_id ?? 'note:'.$row->note) as $group) {
            $collected = round((float) $group->where('type', 'cash_collected')->sum('amount'), 2);
            $earning = round((float) $group->where('type', 'order_earning')->sum('amount'), 2);

            $errands[] = [
                'errand_id' => $group->first()->errand_request_id,
                'reference' => $group->first()->errandRequest?->reference ?? $group->first()->note,
                'collected' => $collected,
                'earning' => $earning,
                'platform_share' => round($collected - $earning, 2),
            ];
        }

        foreach ($rows->whereIn('type', ['bonus', 'deduction']) as $row) {
            $adjustments[] = [
                'id' => $row->id,
                'type' => $row->type,
                'amount' => round((float) $row->amount, 2),
                'note' => $row->note,
                'created_at' => $row->created_at?->toIso8601String(),
            ];
        }

        foreach ($rows->whereIn('type', ['cash_handover', 'payout', 'cash_settlement']) as $row) {
            $exchanges[] = [
                'id' => $row->id,
                'type' => $row->type,
                'amount' => round((float) $row->amount, 2),
                'note' => $row->note,
                'created_at' => $row->created_at?->toIso8601String(),
            ];
        }

        // Σ cash-meter rows − Σ wallet-meter rows: the net these rows alone explain.
        $explained = round(
            $rows->filter(fn ($r) => self::meterOf($r->type) === 'cash')->sum('amount')
            - $rows->filter(fn ($r) => self::meterOf($r->type) === 'wallet')->sum('amount'),
            2,
        );

        $balances = self::balances($driver);

        return [
            'balances' => $balances,
            'opening_balance' => round($balances['net_due_to_platform'] - $explained, 2),
            'cash_orders' => $cashOrders,
            'online_orders' => $onlineOrders,
            'errands' => $errands,
            'adjustments' => $adjustments,
            'exchanges' => $exchanges,
            'totals' => [
                'cash_orders_count' => count($cashOrders),
                'cash_collected' => round(array_sum(array_column($cashOrders, 'collected')), 2),
                'paid_to_places' => round(array_sum(array_column($cashOrders, 'paid_to_place')), 2),
                'cash_orders_platform_share' => round(array_sum(array_column($cashOrders, 'platform_share')), 2),
                'online_orders_count' => count($onlineOrders),
                'online_orders_captain_share' => round(array_sum(array_column($onlineOrders, 'earning')), 2),
                'earnings' => round(array_sum(array_column($cashOrders, 'earning')) + array_sum(array_column($onlineOrders, 'earning')) + array_sum(array_column($errands, 'earning')), 2),
                'errands_platform_share' => round(array_sum(array_column($errands, 'platform_share')), 2),
                'adjustments' => round(array_sum(array_column($adjustments, 'amount')), 2),
                'exchanges' => round(array_sum(array_column($exchanges, 'amount')), 2),
                'net_due_to_platform' => $balances['net_due_to_platform'],
            ],
            'last_settlement' => self::lastSettlement($driver),
        ];
    }

    /**
     * The office received cash from the captain, and/or paid the captain what they are
     * owed — and the statement both sides can open afterwards.
     *
     * `full` zeroes both meters: everything the captain was carrying came in, everything
     * he was owed went out, and the only money that physically changed hands is the
     * difference. `partial` records the two figures typed, and whatever is left becomes
     * the next statement's opening balance. Either way every row the document above
     * listed is closed onto this statement, so the next «غير مسوّى» starts empty.
     *
     * @return array{settlement: Settlement, transactions: list<DriverTransaction>, balances: array}
     */
    public static function settle(
        Driver $driver,
        User $admin,
        float $cashReceived,
        float $paidToDriver,
        ?string $method,
        ?string $reference,
        ?string $note,
        bool $full = false,
    ): array {
        return DB::transaction(function () use ($driver, $admin, $cashReceived, $paidToDriver, $method, $reference, $note, $full) {
            $driver = Driver::query()->lockForUpdate()->findOrFail($driver->id);
            $document = self::unsettled($driver);
            $opening = (float) $document['opening_balance'];

            if ($full) {
                $cashReceived = max(0.0, (float) $driver->cash_in_hand);
                $paidToDriver = max(0.0, (float) $driver->wallet_balance);
            }

            $rows = [];
            $label = trim(implode(' · ', array_filter([$method, $reference, $note])));
            $open = $driver->transactions()->whereNull('settlement_id')->pluck('id');

            $settlement = Settlement::create([
                'party_type' => 'driver',
                'party_id' => $driver->id,
                'period_start' => $driver->transactions()->whereIn('id', $open)->min('created_at') ?? now(),
                'period_end' => now(),
                'delivery_fees_total' => round(collect($document['cash_orders'])->sum('delivery_fee') + collect($document['online_orders'])->sum('delivery_fee'), 2),
                'cash_collected' => $document['totals']['cash_collected'],
                'net_amount' => $document['totals']['net_due_to_platform'],
                'opening_balance' => $opening,
                'cash_received' => round($cashReceived, 2),
                'paid_out' => round($paidToDriver, 2),
                'status' => 'paid',
                'payment_reference' => $reference,
                'note' => $note,
                'created_by' => $admin->id,
                'paid_at' => now(),
            ]);

            if ($cashReceived > 0) {
                $driver->decrement('cash_in_hand', $cashReceived);
                $rows[] = $driver->transactions()->create([
                    'settlement_id' => $settlement->id,
                    'type' => 'cash_handover',
                    'amount' => -round($cashReceived, 2),
                    'balance_after' => (float) $driver->fresh()->cash_in_hand,
                    'note' => $label !== '' ? $label : null,
                    'created_by' => $admin->id,
                ]);
            }

            if ($paidToDriver > 0) {
                $driver->decrement('wallet_balance', $paidToDriver);
                $rows[] = $driver->transactions()->create([
                    'settlement_id' => $settlement->id,
                    'type' => 'payout',
                    'amount' => -round($paidToDriver, 2),
                    'balance_after' => (float) $driver->fresh()->wallet_balance,
                    'note' => $label !== '' ? $label : null,
                    'created_by' => $admin->id,
                ]);
            }

            DriverTransaction::query()->whereIn('id', $open)->update(['settlement_id' => $settlement->id]);

            $fresh = $driver->fresh();
            $balances = self::balances($fresh);

            $settlement->update([
                'closing_balance' => $balances['net_due_to_platform'],
                'breakdown' => array_merge($document, ['balances_after' => $balances]),
            ]);

            foreach ($document['cash_orders'] as $line) {
                $settlement->items()->create([
                    'order_id' => $line['order_id'],
                    'type' => 'driver_cash_order',
                    'gross' => $line['collected'],
                    'delivery_fee' => $line['delivery_fee'],
                    'driver_earning' => $line['earning'],
                    'net' => $line['platform_share'],
                ]);
            }

            foreach ($document['online_orders'] as $line) {
                $settlement->items()->create([
                    'order_id' => $line['order_id'],
                    'type' => 'driver_online_order',
                    'delivery_fee' => $line['delivery_fee'],
                    'driver_earning' => $line['earning'],
                    'net' => -$line['earning'],
                ]);
            }

            AuditLogger::record($admin, 'drivers.settle', $driver, [
                'settlement_id' => $settlement->id,
                'full' => $full,
                'cash_received' => round($cashReceived, 2),
                'paid_to_driver' => round($paidToDriver, 2),
                'method' => $method,
                'reference' => $reference,
            ]);

            DB::afterCommit(function () use ($fresh, $settlement, $cashReceived, $paidToDriver): void {
                app(NotificationDispatcher::class)->send(
                    $fresh->user,
                    'settlement.recorded',
                    [
                        'summary' => self::settlementSentence($cashReceived, $paidToDriver),
                        'balance' => self::closingSentence((float) $settlement->closing_balance),
                    ],
                    'cr7://earnings',
                    null,
                    ['settlement_id' => (string) $settlement->id, 'kind' => 'settlement'],
                );
            });

            return ['settlement' => $settlement->fresh(), 'transactions' => $rows, 'balances' => $balances];
        });
    }

    /**
     * The one sentence the captain is told: what physically changed hands.
     *
     * A full settlement records both gross movements — the cash he carried came in, the
     * dues he was owed went out — but only their difference moved across the counter,
     * and «سلّمت ‪120‬ واستلمت ‪117‬» for a three-pound handover reads like two payments.
     *
     * @return array{ar: string, en: string}
     */
    public static function settlementSentence(float $cashReceived, float $paidToDriver): array
    {
        $net = round($cashReceived - $paidToDriver, 2);

        return match (true) {
            $net > 0 => ['ar' => 'سلّمت للمنصة ‪'.number_format($net, 2).'‬ ج.م', 'en' => 'You handed the platform '.number_format($net, 2).' EGP'],
            $net < 0 => ['ar' => 'استلمت من المنصة ‪'.number_format(-$net, 2).'‬ ج.م', 'en' => 'The platform paid you '.number_format(-$net, 2).' EGP'],
            default => ['ar' => 'تمت المقاصة بين ما حصّلته وما تستحقه', 'en' => 'What you collected and what you were owed cancelled out'],
        };
    }

    /** @return array{ar: string, en: string} */
    public static function closingSentence(float $closing): array
    {
        $direction = self::direction($closing);

        return match ($direction['direction']) {
            'captain_owes' => ['ar' => 'يتبقى عليك ‪'.number_format($direction['amount'], 2).'‬ ج.م', 'en' => number_format($direction['amount'], 2).' EGP still due from you'],
            'platform_owes' => ['ar' => 'يتبقى لك ‪'.number_format($direction['amount'], 2).'‬ ج.م', 'en' => number_format($direction['amount'], 2).' EGP still due to you'],
            default => ['ar' => 'حسابك مسوّى بالكامل', 'en' => 'Your account is fully settled'],
        };
    }

    /** @return array<string, mixed>|null */
    private static function lastSettlement(Driver $driver): ?array
    {
        $last = Settlement::query()
            ->where('party_type', 'driver')
            ->where('party_id', $driver->id)
            ->latest('id')
            ->first();

        return $last === null ? null : [
            'id' => $last->id,
            'paid_at' => $last->paid_at?->toIso8601String(),
            'cash_received' => (float) $last->cash_received,
            'paid_out' => (float) $last->paid_out,
            'closing_balance' => (float) $last->closing_balance,
        ];
    }

    /**
     * Which meter a ledger row belongs to. The captain's own screen reads this to
     * answer the only question they actually have — «ده تبعي ولا لأ».
     */
    public static function meterOf(string $type): string
    {
        // `place_advance` is the platform's debt to the captain, so it sits on his side.
        return match ($type) {
            'cash_collected', 'cash_handover', 'cash_settlement' => 'cash',
            default => 'wallet',
        };
    }
}
