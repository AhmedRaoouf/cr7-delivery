<?php

namespace App\Support;

use App\Enums\PaymentMethod;
use App\Exceptions\ApiException;
use App\Models\User;

/**
 * Operator, 2026-09-19 (D-158, supersedes D-154): «عايز الكاش يتمنع لو الإجمالي أكبر من أو
 * يساوي الحد — يعني أوردر ‪1000‬ جنيه أو أكتر لازم فودافون كاش أو إنستا باي». D-154 had drawn
 * the line one piastre later — a basket of exactly the limit still paid cash.
 *
 * A basket whose total is **at or above** `payment.cash_max_total` cannot be paid in cash —
 * the customer transfers by Vodafone Cash or InstaPay instead. `0` means no limit (D-131:
 * zero is a value the operator chose, never a placeholder).
 *
 * The amount compared is the one payment D-05 describes: the order group's `total` —
 * food, less the discount, plus the delivery fee, the service fee and any carried
 * late-cancellation fee — exactly as {@see CheckoutPricer} prices it. Nothing here prices
 * anything; every caller hands in the number the order is (or will be) charged.
 *
 * Read by the checkout quote (a blocker, and each method's `available`), by placing and
 * scheduling an order (through that blocker), by «ادفع نقدًا بدلًا من ذلك» on a transfer
 * already under way, and by `GET /config`. A direct order is not a customer's basket — the
 * customer pays only the delivery fee, in cash, to the captain — and never comes here.
 */
final class CashLimit
{
    public const SETTING = 'payment.cash_max_total';

    /** The error code, and the blocker code in the quote. */
    public const CODE = 'cash_not_allowed';

    /** Why the cash row of the quote's `payment_methods` is not available. */
    public const REASON = 'above_cash_limit';

    /** The limit in EGP, or `0.0` when there is none. */
    public static function max(): float
    {
        return round(max(0.0, SettingsCatalog::float(self::SETTING)), 2);
    }

    /** Whether cash may pay [total] — at or above the limit is refused (D-158). */
    public static function allows(float $total): bool
    {
        $max = self::max();

        return $max <= 0 || $max - round($total, 2) > 0.005;
    }

    /**
     * The quote's blocker for a cash basket above the limit — the same sentence and the
     * same numbers the refusal carries, so the screen and the 422 cannot disagree.
     *
     * @return array{code: string, message: string, payment_method: string, cash_max_total: float, total: float}
     */
    public static function blocker(float $total, ?User $user = null): array
    {
        return [
            'code' => self::CODE,
            'message' => self::sentence(self::alternatives($user)),
            'payment_method' => PaymentMethod::Cash->value,
            'cash_max_total' => self::max(),
            'total' => round($total, 2),
        ];
    }

    /** `meta` of the 422: the limit and the total it was compared with. */
    public static function meta(float $total): array
    {
        return ['cash_max_total' => self::max(), 'total' => round($total, 2)];
    }

    /**
     * The refusal of «ادفع نقدًا بدلًا من ذلك» on a transfer already under way: the
     * customer is on the transfer screen, so the way on is to finish it.
     */
    public static function refusalOfSwitch(float $total): ApiException
    {
        $sentence = self::english()
            ? self::lead().' — complete the transfer to finish your order'
            : self::lead().' — أكمل التحويل لإتمام طلبك';

        return new ApiException(self::CODE, $sentence, 422, meta: self::meta($total));
    }

    /**
     * «الدفع نقدًا متاح للطلبات أقل من ‪1,000‬ ج.م — اختر فودافون كاش أو إنستا باي».
     *
     * Names only the transfers the customer can actually use. With none — both switched
     * off, or the account held to cash (`users.cash_only_until`) — it says the one thing
     * that still works: a smaller basket.
     *
     * @param  list<string>  $alternatives  method values, in the picker's order
     */
    public static function sentence(array $alternatives): string
    {
        $english = self::english();
        $names = array_map(
            static fn (string $method) => PaymentMethods::describe($method)[$english ? 'label_en' : 'label'],
            $alternatives,
        );

        if ($names === []) {
            return $english
                ? self::lead().' — remove some items to place this order'
                : self::lead().' — قلّل محتوى السلة لإتمام الطلب';
        }

        return $english
            ? self::lead().' — choose '.implode(' or ', $names)
            : self::lead().' — اختر '.implode(' أو ', $names);
    }

    /**
     * The transfers this customer may use instead: switched on at `ADM-46`, and not held
     * back by the account's cash-only window.
     *
     * @return list<string>
     */
    public static function alternatives(?User $user = null): array
    {
        if ($user?->cash_only_until !== null && $user->cash_only_until->isFuture()) {
            return [];
        }

        return array_values(array_filter(
            [PaymentMethod::VodafoneCash->value, PaymentMethod::Instapay->value],
            static fn (string $method) => PaymentMethods::isEnabled($method),
        ));
    }

    /** «الدفع نقدًا متاح للطلبات أقل من ‪1,000‬ ج.م». */
    private static function lead(): string
    {
        $amount = self::amount(self::max());

        return self::english()
            ? "Cash is available for orders under {$amount} EGP"
            : "الدفع نقدًا متاح للطلبات أقل من \u{202A}{$amount}\u{202C} ج.م";
    }

    /** `1,000` for a round limit, `1,250.50` for one that is not. */
    private static function amount(float $value): string
    {
        return abs($value - round($value)) < 0.005 ? number_format($value) : number_format($value, 2);
    }

    /**
     * Every other blocker is Arabic only, and the app prints the server's sentence as
     * written. This one carries a number, so no `lang/en.json` key can match it — it is
     * built in the request's language instead.
     */
    private static function english(): bool
    {
        return str_starts_with(app()->getLocale(), 'en');
    }
}
