<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\Shared\PaymentProofResource;
use App\Support\AutoAssign;
use App\Support\Amendments\AmendmentPayload;
use App\Support\OrderParties;
use App\Support\OrderStages;
use App\Support\RefundPolicy;
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
            // doc 100 — the time of every stage, worked out once here so the web and the
            // app show the same seconds: `stages` in the order they happened, the captains
            // taken off it before the current one, and the durations between stages.
            ...($this->resource->relationLoaded('statusHistory') ? self::stageBlocks($this->resource) : []),
            'attempts' => $this->whenLoaded('driverAttempts'),
            'refunds' => $this->whenLoaded('refunds'),
            // doc 99 D-172 — every change proposed to this order, newest first.
            'amendments' => $this->whenLoaded('amendments', fn () => $this->amendments
                ->sortByDesc('id')->values()
                ->map(fn ($amendment) => AmendmentPayload::forAdmin($amendment->setRelation('order', $this->resource)))),
            // D-65 — the refund an admin should be offered by default on this order.
            // D-101 — the same three blocks the parties read, and the whole money block,
            // the platform's margin included. The console used to print the guest phone
            // only, so a marketplace order had no customer number on it at all.
            ...OrderParties::for($this->resource, 'admin'),
            'pickup_code_required' => (bool) ($this->pickup_code_required ?? true),
            'delivery_code_required' => ! $this->resource->isDirect() && (bool) ($this->delivery_code_required ?? true),
            // doc 99 §3 — D-171: how this order gets (or got) its captain.
            'dispatch' => [
                'strategy' => AutoAssign::strategy(),
                'auto_assign_at' => AutoAssign::enabled() && $this->resource->driver_id === null
                    ? AutoAssign::dueAt($this->resource)
                    : null,
                'assigned_via' => $this->resource->assigned_via,
                'no_candidate_since' => AutoAssign::noCandidateSince($this->resource),
            ],
            'policy_full_refund_amount' => RefundPolicy::fullRefundAmount($this->resource),
            'platform_fee_refundable' => RefundPolicy::feeIsRefundable($this->resource),
            'order_group' => $this->whenLoaded('orderGroup', fn () => [
                'id' => $this->orderGroup->id,
                'group_number' => $this->orderGroup->group_number,
                'rollup_status' => $this->orderGroup->rollup_status,
                'payment' => $this->orderGroup->payment,
                // The transfer receipts behind that payment. A payment belongs to the
                // group, so a three-restaurant basket shows the same receipts on each of
                // its sub-orders — which is right: there was one transfer.
                'receipts' => PaymentProofResource::forAdmin($this->orderGroup->payment?->proofs ?? []),
            ]),
        ];
    }

    /** @return array{stages: list<array<string, mixed>>, earlier_captains: list<array<string, mixed>>, durations: array<string, int|null>, stages_timezone: string} */
    private static function stageBlocks(\App\Models\Order $order): array
    {
        $blocks = OrderStages::for($order);

        return [
            'stages' => $blocks['stages'],
            'earlier_captains' => $blocks['earlier_captains'],
            'durations' => $blocks['durations'],
            'stages_timezone' => $blocks['timezone'],
        ];
    }
}
