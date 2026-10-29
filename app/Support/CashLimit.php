<?php

namespace App\Support;

use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\ApiException;
use App\Models\Order;
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
 * Operator, 2026-10-29 (doc 98): a customer split ‪1,230‬ ج.م into two cash baskets a minute
 * apart (‪800‬ + ‪430‬), each under the limit, and neither was received. So the amount
 * compared is the new basket **plus every cash order this customer still has open** —
 * see {@see openCashTotal()}.
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

    /** How far back an open cash order still counts — older ones are stuck, not owed. */
    public const OPEN_WINDOW_HOURS = 24;

    /**
     * Whether cash may pay [total] — at or above the limit is refused (D-158). [open] is
     * what this customer already owes in cash on orders under way ({@see openCashTotal()}).
     */
    public static function allows(float $total, float $open = 0.0): bool
    {
        $max = self::max();

        return $max <= 0 || $max - round($total + $open, 2) > 0.005;
    }

    /**
     * The cash this customer still owes on orders under way: every app order of theirs paid
     * in cash, neither delivered nor cancelled, placed within {@see OPEN_WINDOW_HOURS}.
     * Summed per sub-order, so a place that cancelled its half of a basket no longer counts.
     * [exceptGroupId] leaves out the basket being judged itself.
     */
    public static function openCashTotal(?User $user, ?int $exceptGroupId = null): float
    {
        if ($user === null || self::max() <= 0) {
            return 0.0;
        }

        return round((float) Order::query()
            ->where('user_id', $user->id)
            ->where('origin', OrderOrigin::App->value)
            ->where('payment_method', PaymentMethod::Cash->value)
            ->whereNotIn('status', [OrderStatus::Delivered->value, OrderStatus::Cancelled->value])
            ->where('created_at', '>=', now()->subHours(self::OPEN_WINDOW_HOURS))
            ->when($exceptGroupId !== null, fn ($query) => $query->where('order_group_id', '!=', $exceptGroupId))
            ->sum('total'), 2);
    }

    /**
     * The quote's blocker for a cash basket above the limit — the same sentence and the
     * same numbers the refusal carries, so the screen and the 422 cannot disagree.
     *
     * `total` stays this basket's own; `open_cash_total` is what the customer already owes
     * in cash on orders under way, and the limit was compared with the two together.
     *
     * @return array{code: string, message: string, payment_method: string, cash_max_total: float, total: float, open_cash_total: float}
     */
    public static function blocker(float $total, ?User $user = null, float $open = 0.0): array
    {
        return [
            'code' => self::CODE,
            'message' => self::sentence(self::alternatives($user), $open),
            'payment_method' => PaymentMethod::Cash->value,
            'cash_max_total' => self::max(),
            'total' => round($total, 2),
            'open_cash_total' => round($open, 2),
        ];
    }

    /** `meta` of the 422: the limit, the basket's total, and the cash already under way. */
    public static function meta(float $total, float $open = 0.0): array
    {
        return ['cash_max_total' => self::max(), 'total' => round($total, 2), 'open_cash_total' => round($open, 2)];
    }

    /**
     * The refusal of «ادفع نقدًا بدلًا من ذلك» on a transfer already under way: the
     * customer is on the transfer screen, so the way on is to finish it.
     */
    public static function refusalOfSwitch(float $total, float $open = 0.0): ApiException
    {
        $sentence = self::english()
            ? self::lead($open).' — complete the transfer to finish your order'
            : self::lead($open).' — أكمل التحويل لإتمام طلبك';

        return new ApiException(self::CODE, $sentence, 422, meta: self::meta($total, $open));
    }

    /**
     * «الدفع نقدًا متاح للطلبات أقل من ‪1,000‬ ج.م — اختر فودافون كاش أو إنستا باي».
     *
     * Names only the transfers the customer can actually use. With none — both switched
     * off, or the account held to cash (`users.cash_only_until`) — it says the one thing
     * that still works: a smaller basket.
     *
     * With cash already under way it names that amount too, so a basket well under the
     * limit is not refused without a reason the customer can see.
     *
     * @param  list<string>  $alternatives  method values, in the picker's order
     */
    public static function sentence(array $alternatives, float $open = 0.0): string
    {
        $english = self::english();
        $names = array_map(
            static fn (string $method) => PaymentMethods::describe($method)[$english ? 'label_en' : 'label'],
            $alternatives,
        );

        if ($names === []) {
            return $english
                ? self::lead($open).' — remove some items to place this order'
                : self::lead($open).' — قلّل محتوى السلة لإتمام الطلب';
        }

        return $english
            ? self::lead($open).' — choose '.implode(' or ', $names)
            : self::lead($open).' — اختر '.implode(' أو ', $names);
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

    /**
     * «الدفع نقدًا متاح للطلبات أقل من ‪1,000‬ ج.م», and with cash under way
     * «… مجتمعة، ولديك طلبات نقدية جارية بقيمة ‪800‬ ج.م».
     */
    private static function lead(float $open = 0.0): string
    {
        $amount = self::amount(self::max());

        if ($open > 0.005) {
            $owed = self::amount($open);

            return self::english()
                ? "Cash is available for orders under {$amount} EGP combined, and you have {$owed} EGP in cash orders under way"
                : "الدفع نقدًا متاح للطلبات أقل من \u{202A}{$amount}\u{202C} ج.م مجتمعة، ولديك طلبات نقدية جارية بقيمة \u{202A}{$owed}\u{202C} ج.م";
        }

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
