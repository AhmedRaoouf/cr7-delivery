<?php

namespace App\Actions\Customer;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\User;
use App\Support\LateCancellationFee;
use App\Support\RefundPolicy;
use App\Support\RollupStatus;
use Illuminate\Support\Facades\DB;

/**
 * doc 08 §4 — POST /orders/{id}/cancel. {id} is a group id; cancels every sub-order
 * still in a cancellable state and reports which ones could not be cancelled.
 *
 * doc 04 §1, as amended 2026-09-09. The customer's button used to stop at the
 * kitchen door; it now reaches one state past it — `preparing` — and costs
 * `orders.late_cancellation_fee` to use there. `ready` onward is still Admin's.
 * `OrderStatus::customerCanCancel()` is the single place that line is drawn, so
 * the resource the app reads and the action that enforces it cannot disagree.
 */
class CancelOrderGroupAction
{
    /**
     * @return array{group: OrderGroup, cancelled: array<int>, not_cancelled: array<int>, fee: float}
     */
    public function execute(User $user, OrderGroup $group, string $reasonCode, ?string $note): array
    {
        abort_unless($group->user_id === $user->id, 404);

        return DB::transaction(function () use ($user, $group, $reasonCode, $note) {
            $orders = $group->orders()->get();
            $cancellable = $orders->filter(fn (Order $o) => $o->status->customerCanCancel());
            $notCancelled = $orders->reject(fn (Order $o) => $o->status->customerCanCancel())
                ->pluck('id')->all();

            if ($cancellable->isEmpty()) {
                throw new ApiException('order_not_cancellable', 'لا يمكن إلغاء الطلب في مرحلته الحالية', 422);
            }

            // Charged once for the whole decision, and carried by the first
            // sub-order that earned it — see the note on `LateCancellationFee`.
            $fee = LateCancellationFee::appliesTo($cancellable) ? LateCancellationFee::amount() : 0.0;
            $feeCharged = false;
            $cancelled = [];

            foreach ($cancellable as $order) {
                $from = $order->status;
                $chargeable = ! $from->customerCancelIsFree() && $fee > 0 && ! $feeCharged;

                $order->update([
                    'status' => OrderStatus::Cancelled,
                    'cancelled_by' => 'customer',
                    'cancellation_reason_code' => $reasonCode,
                    'cancellation_note' => $note,
                    'cancellation_fee' => $chargeable ? $fee : 0,
                    'cancelled_at' => now(),
                ]);

                $order->statusHistory()->create([
                    'from_status' => $from->value,
                    'to_status' => OrderStatus::Cancelled->value,
                    'actor_type' => OrderStatusActorType::Customer,
                    'actor_id' => $user->id,
                    'reason_code' => $reasonCode,
                    'note' => $note,
                    'meta' => $chargeable ? ['cancellation_fee' => $fee] : null,
                ]);

                if ($chargeable) {
                    $this->collect($user, $group, $order->fresh(), $reasonCode, $note, $fee);
                    $feeCharged = true;
                } elseif ($this->owesARefund($group)) {
                    $this->refund($group, $order->fresh(), $reasonCode, $note, 0.0);
                }

                $cancelled[] = $order->id;
            }

            $orders = $group->orders()->get();
            $rollup = RollupStatus::compute($orders->pluck('status'));
            $group->update(['rollup_status' => $rollup]);
            if ($rollup === 'cancelled') {
                $group->update(['completed_at' => now()]);
            }

            return [
                'group' => $group->fresh(),
                'cancelled' => $cancelled,
                'not_cancelled' => $notCancelled,
                'fee' => $feeCharged ? $fee : 0.0,
            ];
        });
    }

    /**
     * The operator's mechanism: take it out of the refund. When there is no
     * refund — a cash basket has paid nothing — it becomes a balance on the
     * account instead of disappearing.
     */
    private function collect(User $user, OrderGroup $group, Order $order, string $reasonCode, ?string $note, float $fee): void
    {
        if ($this->owesARefund($group)) {
            $this->refund($group, $order, $reasonCode, $note, $fee);

            return;
        }

        $user->increment('outstanding_fees', $fee);
    }

    private function owesARefund(OrderGroup $group): bool
    {
        return LateCancellationFee::hasRefundToNetAgainst($group);
    }

    /**
     * A customer cancellation is a choice, not a platform failure, so
     * `RefundPolicy` already keeps the service fee (D-65). The late fee comes
     * off on top of that, and never below zero — a 20 EGP fee against an 8 EGP
     * refund leaves nothing to pay back, not a debt to the platform.
     */
    private function refund(OrderGroup $group, Order $order, string $reasonCode, ?string $note, float $fee): void
    {
        $payment = $group->payment;

        if ($payment === null) {
            return;
        }

        $amount = max(0, round(RefundPolicy::fullRefundAmount($order, $reasonCode) - $fee, 2));

        $order->refunds()->create([
            'payment_id' => $payment->id,
            'amount' => $amount,
            // A fee taken out of it makes it a partial refund by definition,
            // and `ADM-45` sorts on that column.
            'type' => $fee > 0 ? 'partial' : 'full',
            'reason_code' => $reasonCode,
            'note' => $note,
            'status' => 'pending',
        ]);
    }
}
