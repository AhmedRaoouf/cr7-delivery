<?php

namespace App\Actions\Customer;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Models\OrderGroup;
use App\Models\User;
use App\Support\RollupStatus;
use Illuminate\Support\Facades\DB;

/**
 * doc 08 §4 — POST /orders/{id}/cancel. {id} is a group id; cancels every sub-order
 * still in a cancellable state and reports which ones could not be cancelled
 * (doc 04 §6.3 — preparing or later needs Admin).
 */
class CancelOrderGroupAction
{
    private const CANCELLABLE = [
        OrderStatus::Pending, OrderStatus::RestaurantTimeout, OrderStatus::Accepted,
        OrderStatus::AwaitingCustomerConfirmation, OrderStatus::AwaitingPayment,
    ];

    /** @return array{group: OrderGroup, cancelled: array<int>, not_cancelled: array<int>} */
    public function execute(User $user, OrderGroup $group, string $reasonCode, ?string $note): array
    {
        abort_unless($group->user_id === $user->id, 404);

        return DB::transaction(function () use ($user, $group, $reasonCode, $note) {
            $orders = $group->orders()->get();
            $cancelled = [];
            $notCancelled = [];

            foreach ($orders as $order) {
                if (! in_array($order->status, self::CANCELLABLE, true)) {
                    $notCancelled[] = $order->id;

                    continue;
                }

                $from = $order->status;
                $order->update([
                    'status' => OrderStatus::Cancelled,
                    'cancelled_by' => 'customer',
                    'cancellation_reason_code' => $reasonCode,
                    'cancellation_note' => $note,
                    'cancelled_at' => now(),
                ]);

                $order->statusHistory()->create([
                    'from_status' => $from->value,
                    'to_status' => OrderStatus::Cancelled->value,
                    'actor_type' => OrderStatusActorType::Customer,
                    'actor_id' => $user->id,
                    'reason_code' => $reasonCode,
                    'note' => $note,
                ]);

                $cancelled[] = $order->id;
            }

            if ($cancelled === [] && $notCancelled !== []) {
                throw new ApiException('order_not_cancellable', 'لا يمكن إلغاء الطلب في مرحلته الحالية', 422);
            }

            $orders = $group->orders()->get();
            $rollup = RollupStatus::compute($orders->pluck('status'));
            $group->update(['rollup_status' => $rollup]);
            if ($rollup === 'cancelled') {
                $group->update(['completed_at' => now()]);
            }

            return ['group' => $group->fresh(), 'cancelled' => $cancelled, 'not_cancelled' => $notCancelled];
        });
    }
}
