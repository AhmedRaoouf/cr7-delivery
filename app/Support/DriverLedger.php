<?php

namespace App\Support;

use App\Models\Driver;
use App\Models\DriverTransaction;
use App\Models\Order;
use App\Models\User;
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
 * | `wallet_balance`| the captain's  | a delivery completes                   | the platform pays them |
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
 * On a marketplace order the captain also carries the food money, which is the platform's
 * until it reaches the restaurant, so `net_due_to_platform` on a cash order is most of the
 * basket. That is correct and is the whole reason `cash_in_hand` is tracked at all.
 */
class DriverLedger
{
    /** The three numbers every screen on both sides shows. */
    public static function balances(Driver $driver): array
    {
        $cash = (float) $driver->cash_in_hand;
        $wallet = (float) $driver->wallet_balance;

        return [
            'cash_in_hand' => round($cash, 2),
            'wallet_balance' => round($wallet, 2),
            'net_due_to_platform' => round($cash - $wallet, 2),
        ];
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
    public static function recordErrand(Driver $driver, string $reference, float $driverEarning, float $cashFee): array
    {
        $driver->increment('wallet_balance', round($driverEarning, 2));

        $earning = $driver->transactions()->create([
            'order_id' => null,
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
     * The office received cash from the captain, and/or paid the captain what they are
     * owed. One transaction so a half-recorded settlement cannot exist.
     *
     * @return array{transactions: list<DriverTransaction>, balances: array}
     */
    public static function settle(
        Driver $driver,
        User $admin,
        float $cashReceived,
        float $paidToDriver,
        ?string $method,
        ?string $reference,
        ?string $note,
    ): array {
        return DB::transaction(function () use ($driver, $admin, $cashReceived, $paidToDriver, $method, $reference, $note) {
            $rows = [];
            $label = trim(implode(' · ', array_filter([$method, $reference, $note])));

            if ($cashReceived > 0) {
                $driver->decrement('cash_in_hand', $cashReceived);
                $rows[] = $driver->transactions()->create([
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
                    'type' => 'payout',
                    'amount' => -round($paidToDriver, 2),
                    'balance_after' => (float) $driver->fresh()->wallet_balance,
                    'note' => $label !== '' ? $label : null,
                    'created_by' => $admin->id,
                ]);
            }

            AuditLogger::record($admin, 'drivers.settle', $driver, [
                'cash_received' => round($cashReceived, 2),
                'paid_to_driver' => round($paidToDriver, 2),
                'method' => $method,
                'reference' => $reference,
            ]);

            return ['transactions' => $rows, 'balances' => self::balances($driver->fresh())];
        });
    }

    /**
     * Which meter a ledger row belongs to. The captain's own screen reads this to
     * answer the only question they actually have — «ده تبعي ولا لأ».
     */
    public static function meterOf(string $type): string
    {
        return match ($type) {
            'cash_collected', 'cash_handover', 'cash_settlement' => 'cash',
            default => 'wallet',
        };
    }
}
