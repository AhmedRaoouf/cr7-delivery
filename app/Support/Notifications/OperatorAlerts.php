<?php

namespace App\Support\Notifications;

use App\Enums\UserType;
use App\Models\Order;
use App\Models\User;
use App\Support\AdminScope;
use App\Support\SettingsCatalog;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * doc 62 §9 — the operator alerts doc 11 §4 listed and nothing sent.
 *
 * `alert.restaurant_timeout`, `alert.payment_review`, `alert.delivery_issue` and
 * `alert.otp_locked` were rows in the matrix with no caller, so each of these waited for
 * somebody to happen to open the right screen: a receipt on a fifteen-minute deadline, a
 * captain locked out at a door, a kitchen that never answered. They go to the operators
 * **whose scope covers the order** (D-51) — an out-of-scope admin would tap the push into
 * the 404 that same rule guarantees.
 */
final class OperatorAlerts
{
    public static function restaurantTimeout(Order $order): void
    {
        self::toOperators($order, 'order.restaurant_timeout_admin', [
            'restaurant' => $order->restaurant?->name_ar,
            'order_number' => $order->order_number,
            'minutes' => SettingsCatalog::int('restaurant.accept_timeout_minutes'),
        ]);
    }

    public static function deliveryIssue(Order $order): void
    {
        self::toOperators($order, 'order.delivery_issue_admin', [
            'order_number' => $order->order_number,
            'driver' => $order->driver?->user?->name,
        ]);
    }

    /** `$kind` is `pickup` or `delivery`. */
    public static function codeLocked(Order $order, string $kind): void
    {
        self::toOperators($order, 'order.otp_locked_admin', [
            'kind' => $kind === 'pickup'
                ? ['ar' => 'كود الاستلام', 'en' => 'pickup code']
                : ['ar' => 'كود التسليم', 'en' => 'delivery code'],
            'order_number' => $order->order_number,
            'attempts' => SettingsCatalog::int('order.otp_max_attempts'),
            'driver' => $order->driver?->user?->name,
        ]);
    }

    public static function paymentProofUploaded(Order $order, float $amount, string $method): void
    {
        self::toOperators($order, 'payment.proof_uploaded_admin', [
            'order_number' => $order->orderGroup?->group_number ?? $order->order_number,
            'amount' => number_format($amount, 2).' ج.م',
            'method' => match ($method) {
                'instapay' => ['ar' => 'إنستا باي', 'en' => 'InstaPay'],
                'vodafone_cash' => ['ar' => 'فودافون كاش', 'en' => 'Vodafone Cash'],
                default => ['ar' => $method, 'en' => $method],
            },
        ]);
    }

    /** @param array<string, mixed> $context */
    private static function toOperators(Order $order, string $type, array $context): void
    {
        try {
            $admins = User::query()->where('type', UserType::Admin)->where('is_active', true)->get();
            $dispatcher = app(NotificationDispatcher::class);

            foreach ($admins as $admin) {
                if (! AdminScope::coversOrder($admin, $order)) {
                    continue;
                }

                $dispatcher->send($admin, $type, $context, '/orders/'.$order->id, $order->id, ['order_id' => (string) $order->id]);
            }
        } catch (Throwable $e) {
            // An alert is never worth failing the action that raised it.
            Log::warning('operator alert failed', ['type' => $type, 'order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
