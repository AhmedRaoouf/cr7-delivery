<?php

namespace App\Actions\Restaurant;

use App\Enums\OrderStatus;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Setting;
use App\Support\BoardQueue;
use App\Support\Notifications\OrderNotifications;

/** doc 08 §6 — PATCH /restaurant/orders/{id}/prep-time: one extension per order, max 15 min. */
class ExtendPrepTimeAction
{
    public function execute(Order $order, int $extraMinutes): Order
    {
        // doc 99 §3 — while the food is still in the kitchen, even when its captain is
        // already assigned; the captain is told the new time.
        $onTheCaptainsTrip = BoardQueue::kitchenPending($order);

        if ($order->status !== OrderStatus::Preparing && ! $onTheCaptainsTrip) {
            throw new ApiException('invalid_transition', 'لا يمكن تمديد الوقت في حالة الطلب الحالية', 422);
        }

        if ($order->prep_extended_minutes > 0) {
            throw new ApiException('prep_extension_used', 'تم استخدام فرصة تمديد الوقت بالفعل', 422);
        }

        $max = (int) (Setting::query()->where('key', 'restaurant.prep_extension_max_minutes')->value('value') ?? 15);

        if ($extraMinutes < 1 || $extraMinutes > $max) {
            throw new ApiException('validation_failed', "الحد الأقصى للتمديد {$max} دقيقة", 422);
        }

        $order->update([
            'prep_extended_minutes' => $extraMinutes,
            'prep_due_at' => $order->prep_due_at->clone()->addMinutes($extraMinutes),
        ]);

        if ($onTheCaptainsTrip) {
            app(OrderNotifications::class)->prepExtended($order, $extraMinutes);
        }

        return $order->fresh();
    }
}
