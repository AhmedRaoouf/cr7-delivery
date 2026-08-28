<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** doc 08 §7 — GET /admin/orders/{id}: snapshot + timeline + dispatch attempts + payment + refunds. */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->resource->toArray(),
            'restaurant' => $this->whenLoaded('restaurant'),
            'driver' => $this->whenLoaded('driver'),
            'user' => $this->whenLoaded('user'),
            'items' => $this->whenLoaded('items'),
            'timeline' => $this->whenLoaded('statusHistory'),
            'attempts' => $this->whenLoaded('driverAttempts'),
            'refunds' => $this->whenLoaded('refunds'),
            // D-65 — the refund an admin should be offered by default on this order.
            'policy_full_refund_amount' => \App\Support\RefundPolicy::fullRefundAmount($this->resource),
            'platform_fee_refundable' => \App\Support\RefundPolicy::feeIsRefundable($this->resource),
            'order_group' => $this->whenLoaded('orderGroup', fn () => [
                'id' => $this->orderGroup->id,
                'group_number' => $this->orderGroup->group_number,
                'rollup_status' => $this->orderGroup->rollup_status,
                'payment' => $this->orderGroup->payment,
            ]),
        ];
    }
}
