<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Batch 67 — one guess at a pickup or delivery code, counted under a row lock.
 *
 * `PickupOrderAction` and `DeliverOrderAction` compared the attempts column of the
 * order **as route-model binding had loaded it**, then incremented. Requests that
 * arrive together all load the same count, so N parallel requests each got a guess
 * before any of them was counted — `order.otp_max_attempts` capped the guesses per
 * round trip, not per order. A four-digit code has 10 000 values; the delivery code is
 * what stands between a captain and closing a cash order they never delivered.
 *
 * Here the count is read `FOR UPDATE`, the guess is compared with `hash_equals`, and a
 * wrong guess is committed before the caller throws — the same shape as
 * {@see OtpVerifier}, and for the same reason it must not run inside another
 * transaction.
 */
final class OrderCodeGuard
{
    /**
     * @return array{matched: bool, attempts: int, locked: bool}
     *                                                          `locked` — the order had already used every attempt before this one;
     *                                                          nothing was compared and nothing was counted.
     */
    public static function attempt(Order $order, string $codeColumn, string $attemptsColumn, string $given, int $limit): array
    {
        $result = DB::transaction(function () use ($order, $codeColumn, $attemptsColumn, $given, $limit): array {
            $row = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $attempts = (int) $row->{$attemptsColumn};

            if ($attempts >= $limit) {
                return ['matched' => false, 'attempts' => $attempts, 'locked' => true];
            }

            $expected = (string) $row->getRawOriginal($codeColumn);

            if ($expected !== '' && hash_equals($expected, $given)) {
                return ['matched' => true, 'attempts' => $attempts, 'locked' => false];
            }

            $row->increment($attemptsColumn);

            return ['matched' => false, 'attempts' => $attempts + 1, 'locked' => false];
        });

        // The caller's instance is the one it goes on to use; keep it honest.
        $order->setAttribute($attemptsColumn, $result['attempts']);
        $order->syncOriginalAttribute($attemptsColumn);

        return $result;
    }
}
