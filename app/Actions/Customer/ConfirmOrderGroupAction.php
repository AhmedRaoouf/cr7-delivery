<?php

namespace App\Actions\Customer;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Models\OrderGroup;
use App\Models\Setting;
use App\Models\User;
use App\Support\RollupStatus;
use Illuminate\Support\Facades\DB;

/**
 * doc 08 §4 — POST /orders/{id}/confirm. Cash -> every awaiting_customer_confirmation
 * sub-order becomes confirmed. Online -> sub-orders move to awaiting_payment and the
 * payment gate opens (doc 04 §7.2).
 */
class ConfirmOrderGroupAction
{
    public function execute(User $user, OrderGroup $group): OrderGroup
    {
        abort_unless($group->user_id === $user->id, 404);

        if ($group->confirmed_at) {
            return $group->fresh(['orders.restaurant', 'payment']);
        }

        if ($group->confirm_deadline_at && $group->confirm_deadline_at->isPast()) {
            throw new ApiException('confirmation_expired', 'انتهت مهلة تأكيد الطلب', 422);
        }

        $orders = $group->orders()->get();
        $awaiting = $orders->reject(fn ($o) => $o->status === OrderStatus::Cancelled);

        if ($awaiting->isEmpty() || $awaiting->contains(fn ($o) => $o->status !== OrderStatus::AwaitingCustomerConfirmation)) {
            throw new ApiException('confirmation_not_open', 'باب تأكيد الطلب غير مفتوح بعد', 422);
        }

        return DB::transaction(function () use ($user, $group, $awaiting) {
            $isCash = $group->payment_method->value === 'cash';
            $nextStatus = $isCash ? OrderStatus::Confirmed : OrderStatus::AwaitingPayment;

            foreach ($awaiting as $order) {
                $order->update(['status' => $nextStatus, 'confirmed_at' => now()]);
                $order->statusHistory()->create([
                    'from_status' => OrderStatus::AwaitingCustomerConfirmation->value,
                    'to_status' => $nextStatus->value,
                    'actor_type' => OrderStatusActorType::Customer,
                    'actor_id' => $user->id,
                ]);
            }

            if ($isCash) {
                $group->update([
                    'confirmed_at' => now(),
                    'confirmed_by' => 'customer',
                    'rollup_status' => RollupStatus::compute($group->orders()->get()->pluck('status')),
                ]);
            } else {
                $deadlineMinutes = (int) (Setting::query()->where('key', 'payment.transfer_deadline_minutes')->value('value') ?? 15);

                $group->payment()->update([
                    'status' => 'awaiting_proof',
                    'amount' => $group->total,
                    'gate_opened_at' => now(),
                    'deadline_at' => now()->addMinutes($deadlineMinutes),
                ]);

                $group->update(['rollup_status' => RollupStatus::compute($group->orders()->get()->pluck('status'))]);
            }

            return $group->fresh(['orders.restaurant', 'payment']);
        });
    }
}
