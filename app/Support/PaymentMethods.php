<?php

namespace App\Support;

use App\Enums\PaymentMethod;
use App\Models\User;

/**
 * D-109 — «طرق الدفع اشيل واحده او اسيب واحده والارقام اللي هيتحول عليها».
 *
 * Which of the three methods the customer is offered, and where the money goes for each,
 * are `ADM-46`'s. Every place that lists methods reads them from here — `GET /config`,
 * the checkout quote, the payment gate — so the list the customer picks from and the list
 * the checkout accepts cannot disagree. Checkout refuses a switched-off method by name
 * (`payment_method_disabled`) rather than letting a stale app place an order the operator
 * has stopped taking.
 *
 * Each method carries **its own** destination. The customer's picker used to print the
 * Vodafone number under InstaPay, because one `wallet_number` was all there was to print.
 */
final class PaymentMethods
{
    /** method => the switch that offers it */
    private const SWITCHES = [
        'cash' => 'payment.cash_enabled',
        'vodafone_cash' => 'payment.vodafone_cash_enabled',
        'instapay' => 'payment.instapay_enabled',
    ];

    /** method => [Arabic, English] */
    private const LABELS = [
        'cash' => ['الدفع عند الاستلام', 'Cash on delivery'],
        'vodafone_cash' => ['فودافون كاش', 'Vodafone Cash'],
        'instapay' => ['إنستا باي', 'InstaPay'],
    ];

    public static function isEnabled(PaymentMethod|string $method): bool
    {
        $key = self::SWITCHES[$method instanceof PaymentMethod ? $method->value : $method] ?? null;

        return $key !== null && SettingsCatalog::bool($key);
    }

    /**
     * The switched-on methods, in the order the picker draws them.
     *
     * @return list<array<string, mixed>>
     */
    public static function enabled(): array
    {
        $methods = array_filter(array_keys(self::SWITCHES), static fn (string $method) => self::isEnabled($method));

        return array_values(array_map(static fn (string $method) => self::describe($method), $methods));
    }

    /**
     * The switched-on methods for one priced basket, each saying whether this customer
     * may use it for this total — the checkout quote's `payment_methods`.
     *
     * `enabled` keeps its D-109 meaning (switched on at `ADM-46`, so `true` on every row
     * listed) because an installed app filters the picker on it. `available` is the new
     * answer for this basket and this account, and `reason` says why not:
     * `above_cash_limit` (the basket is over `payment.cash_max_total`) or
     * `cash_only_restricted` (the account is held to cash, `users.cash_only_until`).
     *
     * @return list<array<string, mixed>>
     */
    public static function offeredFor(float $total, ?User $user = null): array
    {
        $cashOnly = $user?->cash_only_until !== null && $user->cash_only_until->isFuture();

        return array_map(static function (array $method) use ($total, $cashOnly) {
            $reason = match (true) {
                $method['value'] === PaymentMethod::Cash->value && ! CashLimit::allows($total) => CashLimit::REASON,
                $method['value'] !== PaymentMethod::Cash->value && $cashOnly => 'cash_only_restricted',
                default => null,
            };

            return $method + ['enabled' => true, 'available' => $reason === null, 'reason' => $reason];
        }, self::enabled());
    }

    /**
     * One method as the customer sees it: `{value, label, label_en}`, plus `number` and
     * `number_alt` for Vodafone Cash or `handle` for InstaPay.
     *
     * @return array<string, mixed>
     */
    public static function describe(PaymentMethod|string $method): array
    {
        $value = $method instanceof PaymentMethod ? $method->value : $method;
        [$ar, $en] = self::LABELS[$value] ?? [$value, $value];

        return ['value' => $value, 'label' => $ar, 'label_en' => $en] + self::destination($value);
    }

    /** @return array<string, ?string> */
    public static function destination(PaymentMethod|string $method): array
    {
        return match ($method instanceof PaymentMethod ? $method->value : $method) {
            'vodafone_cash' => [
                'number' => SettingsCatalog::get('payment.vodafone_cash_number'),
                'number_alt' => SettingsCatalog::get('payment.vodafone_cash_number_alt'),
            ],
            'instapay' => ['handle' => SettingsCatalog::get('payment.instapay_handle')],
            default => [],
        };
    }

    /** The refusal, said the same way by the quote, the order and the gate. */
    public static function disabledMessage(): string
    {
        return 'طريقة الدفع هذه غير متاحة حاليًا';
    }
}
