<?php

namespace App\Http\Resources\Shared;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One scheduled basket, as the customer, the restaurant and the operator all read it.
 *
 * `lines` is filtered per caller: a restaurant sees only what was ordered from *it*.
 * A multi-restaurant basket must not show one kitchen what the customer also bought
 * from the one down the road — the same rule D-53 applies to a mirrored complaint.
 */
class ScheduledOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $restaurantId = $user?->type->value === 'restaurant'
            ? $user->restaurant()->value('id')
            : null;

        $snapshot = $this->basket_snapshot ?? [];
        $lines = collect($snapshot['lines'] ?? [])
            ->when($restaurantId !== null, fn ($rows) => $rows->where('restaurant_id', $restaurantId))
            ->values();

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'status_label' => match ($this->status) {
                'scheduled' => 'مجدول',
                'released' => 'تم إرساله',
                'failed' => 'تعذّر تنفيذه',
                'cancelled' => 'ملغى',
                default => $this->status,
            },
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'released_at' => $this->released_at?->toIso8601String(),
            'restaurant_count' => $this->restaurant_count,
            'estimated_total' => (float) $this->estimated_total,
            'order_group_id' => $this->order_group_id,
            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message,
            'payment_method' => $this->payload['payment_method'] ?? null,
            'customer_note' => $this->payload['customer_note'] ?? null,
            'lines' => $lines,
            // A restaurant reading its own slice is told its own subtotal, not the
            // basket's — the number it will be paid on.
            'subtotal' => $restaurantId === null
                ? (float) ($snapshot['subtotal'] ?? 0)
                : round($lines->sum(fn (array $line) => (float) ($line['line_total'] ?? (float) $line['unit_price'] * (int) $line['quantity'])), 2),
            'delivery_fee' => $this->when($restaurantId === null, fn () => (float) ($snapshot['delivery_fee'] ?? 0)),
            'total' => $this->when($restaurantId === null, fn () => (float) ($snapshot['total'] ?? 0)),
            'customer' => $this->when(
                $user?->type->value !== 'customer',
                fn () => [
                    'id' => $this->customer?->id,
                    'name' => $this->customer?->name,
                    // The kitchen has no reason to ring the customer about an order that
                    // has not been placed yet; the operator does.
                    'phone' => $user?->type->value === 'admin' ? $this->customer?->phone : null,
                ],
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
