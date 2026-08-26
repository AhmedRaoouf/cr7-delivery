<?php

namespace App\Http\Resources\Customer;

use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** doc 08 §4 — POST /orders -> 201 response shape. */
class OrderGroupCreatedResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = Locale::current($request);
        $payment = $this->payment;

        return [
            'id' => $this->id,
            'group_number' => $this->group_number,
            'rollup_status' => $this->rollup_status,
            'restaurant_count' => $this->restaurant_count,
            'payment' => [
                'method' => $payment?->method,
                'status' => $payment?->status,
                'gate_open' => (bool) $payment?->gate_opened_at,
                'wallet_number' => null,
                'amount' => $payment?->gate_opened_at ? (float) $payment->amount : null,
                'deadline_at' => $payment?->deadline_at,
                'note' => 'المبلغ بيتحدد بعد ما كل المطاعم ترد',
            ],
            'subtotal' => (float) $this->subtotal,
            'discount_amount' => (float) $this->discount_amount,
            'delivery_base_fee' => (float) $this->delivery_base_fee,
            'extra_stop_fee_total' => (float) $this->extra_stop_fee_total,
            'delivery_fee' => (float) $this->delivery_fee,
            'total' => (float) $this->total,
            'sub_orders' => $this->orders->map(fn ($order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'group_sequence' => $order->group_sequence,
                'restaurant' => ['id' => $order->restaurant->id, 'name' => $order->restaurant->{"name_{$locale}"}],
                'status' => $order->status,
                'subtotal' => (float) $order->subtotal,
                'discount_share' => (float) $order->discount_share,
                'delivery_fee_share' => (float) $order->delivery_fee_share,
                'total' => (float) $order->total,
            ]),
        ];
    }
}
