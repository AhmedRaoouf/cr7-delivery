<?php

namespace App\Support;

use App\Actions\Customer\ConfirmOrderGroupAction;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * doc 04 §8-9, doc 16 D-40(a) — once every sub-order in a group has answered
 * (accepted or cancelled), the confirmation gate opens: a single-restaurant cash
 * order is auto-confirmed by the system, everything else waits on the customer.
 */
class ConfirmationGateService
{
    /**
     * @param  bool  $customerApproved  doc 99 D-172 — the customer has just approved a change to
     *                                  this basket, with its new total in front of them. That
     *                                  approval is the confirmation the gate would otherwise
     *                                  wait for, so it is not asked for a second time.
     */
    public function maybeOpen(OrderGroup $group, ?User $actor = null, bool $customerApproved = false): void
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

        if ($customerApproved) {
            $this->confirmByApproval($group, $survivors, $isCash);

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

    /**
     * The customer's approval of an amendment, counted as their confirmation: what
     * `ConfirmOrderGroupAction` does when they press «تأكيد», without the round trip.
     * Cash is confirmed; a transfer opens its window on the changed total.
     *
     * @param  Collection<int, Order>  $survivors
     */
    private function confirmByApproval(OrderGroup $group, $survivors, bool $isCash): void
    {
        $next = $isCash ? OrderStatus::Confirmed : OrderStatus::AwaitingPayment;

        foreach ($survivors as $order) {
            $order->update(['status' => $next, 'confirmed_at' => now()]);
            $order->statusHistory()->create([
                'from_status' => OrderStatus::Accepted->value,
                'to_status' => $next->value,
                'actor_type' => OrderStatusActorType::Customer,
                'actor_id' => $group->user_id,
                'reason_code' => 'confirmed_by_amendment_approval',
            ]);
        }

        if ($isCash) {
            $group->update([
                'confirmation_opened_at' => now(),
                'confirmed_at' => now(),
                'confirmed_by' => 'customer',
                'rollup_status' => RollupStatus::compute($group->orders()->get()->pluck('status')),
            ]);

            return;
        }

        ConfirmOrderGroupAction::openTransfer($group);

        $group->update([
            'confirmation_opened_at' => now(),
            'rollup_status' => RollupStatus::compute($group->orders()->get()->pluck('status')),
        ]);
    }
}
