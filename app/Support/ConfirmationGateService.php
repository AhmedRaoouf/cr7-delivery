<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Models\OrderGroup;
use App\Models\Setting;
use App\Models\User;

/**
 * doc 04 §8-9, doc 16 D-40(a) — once every sub-order in a group has answered
 * (accepted or cancelled), the confirmation gate opens: a single-restaurant cash
 * order is auto-confirmed by the system, everything else waits on the customer.
 */
class ConfirmationGateService
{
    public function maybeOpen(OrderGroup $group, ?User $actor = null): void
    {
        $orders = $group->orders()->get();
        $answered = $orders->every(fn ($o) => in_array($o->status, [OrderStatus::Accepted, OrderStatus::Cancelled], true));

        if (! $answered) {
            return;
        }

        $survivors = $orders->where('status', OrderStatus::Accepted);

        if ($survivors->isEmpty()) {
            $group->update(['rollup_status' => 'cancelled', 'completed_at' => now()]);

            return;
        }

        $isCash = $group->payment_method->value === 'cash';
        $isSingleRestaurant = $group->restaurant_count === 1;

        if ($isCash && $isSingleRestaurant) {
            foreach ($survivors as $order) {
                $order->update(['status' => OrderStatus::Confirmed, 'confirmed_at' => now()]);
                $order->statusHistory()->create([
                    'from_status' => OrderStatus::Accepted->value,
                    'to_status' => OrderStatus::Confirmed->value,
                    'actor_type' => OrderStatusActorType::System,
                    'reason_code' => 'auto_confirmed_single_restaurant_cash',
                ]);
            }

            $group->update([
                'confirmed_at' => now(),
                'confirmed_by' => 'system',
                'rollup_status' => RollupStatus::compute($group->orders()->get()->pluck('status')),
            ]);

            return;
        }

        $timeoutMinutes = (int) (Setting::query()->where('key', 'order.customer_confirm_timeout_minutes')->value('value') ?? 10);

        foreach ($survivors as $order) {
            $order->update(['status' => OrderStatus::AwaitingCustomerConfirmation]);
            $order->statusHistory()->create([
                'from_status' => OrderStatus::Accepted->value,
                'to_status' => OrderStatus::AwaitingCustomerConfirmation->value,
                'actor_type' => OrderStatusActorType::System,
            ]);
        }

        $group->update([
            'confirmation_opened_at' => now(),
            'confirm_deadline_at' => now()->addMinutes($timeoutMinutes),
            'rollup_status' => RollupStatus::compute($group->orders()->get()->pluck('status')),
        ]);
    }
}
