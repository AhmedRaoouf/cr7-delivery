<?php

namespace App\Support\Amendments;

use App\Models\Order;
use App\Models\OrderAmendment;
use App\Models\OrderGroup;

/**
 * doc 99 §4, D-172 — the `amendment` block every reader of an order sees, cut per reader.
 *
 * Money is the group's (`money_before`, `money_after`, `delta`) because that is what the
 * customer pays; `order_subtotal` inside each is the sub-order's food — what the place is
 * owed. Lines carry both names and a `name` in the request's language.
 */
final class AmendmentPayload
{
    /** @return array<string, mixed> */
    public static function forCustomer(OrderAmendment $amendment, string $locale): array
    {
        $order = $amendment->order;
        $restaurant = $order?->restaurant;
        $group = $amendment->orderGroup;

        return [
            'id' => $amendment->id,
            'order_id' => $amendment->order_id,
            'order_group_id' => $amendment->order_group_id,
            'status' => $amendment->status->value,
            'restaurant' => $restaurant === null ? null : [
                'id' => $restaurant->id,
                'name' => $locale === 'en' ? ($restaurant->name_en ?: $restaurant->name_ar) : $restaurant->name_ar,
                'logo' => $restaurant->logo,
            ],
            ...self::body($amendment, $locale),
            'expires_at' => $amendment->expires_at?->toIso8601String(),
            'seconds_left' => $amendment->secondsLeft(),
            'can_respond' => $amendment->isPending() && $amendment->secondsLeft() > 0,
            'cash_ok' => $group === null || OrderAmendments::cashAllows($group, $group->user, (float) ($amendment->money_after['total'] ?? 0)),
            'responded_at' => $amendment->responded_at?->toIso8601String(),
            'response_note' => $amendment->response_note,
            'created_at' => $amendment->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function forPlace(OrderAmendment $amendment, ?Order $order = null): array
    {
        $order ??= $amendment->order;
        $group = $order?->orderGroup;

        return [
            'id' => $amendment->id,
            'order_id' => $amendment->order_id,
            'status' => $amendment->status->value,
            'proposer_type' => $amendment->proposer_type,
            'prep_minutes' => (int) $amendment->prep_minutes,
            'restaurant_order_ref' => $amendment->restaurant_order_ref,
            ...self::body($amendment, app()->getLocale() === 'en' ? 'en' : 'ar'),
            'expires_at' => $amendment->expires_at?->toIso8601String(),
            'seconds_left' => $amendment->secondsLeft(),
            'cash_ok' => $group === null || OrderAmendments::cashAllows($group, $group->user, (float) ($amendment->money_after['total'] ?? 0)),
            'responded_at' => $amendment->responded_at?->toIso8601String(),
            'response_note' => $amendment->response_note,
            'created_at' => $amendment->created_at?->toIso8601String(),
        ];
    }

    /** The admin's history row: the place's view plus who proposed it. */
    public static function forAdmin(OrderAmendment $amendment): array
    {
        return [
            ...self::forPlace($amendment),
            'proposed_by' => $amendment->proposer === null ? null : ['id' => $amendment->proposer->id, 'name' => $amendment->proposer->name],
            'responded_by' => $amendment->responded_by,
        ];
    }

    /**
     * The group's `pending_amendment` — enough for the tracking screen to open the
     * approval screen and count down.
     *
     * @return array<string, mixed>|null
     */
    public static function pendingSummary(OrderGroup $group): ?array
    {
        $amendment = $group->relationLoaded('pendingAmendment')
            ? $group->pendingAmendment
            : $group->pendingAmendment()->first();

        if ($amendment === null) {
            return null;
        }

        return [
            'id' => $amendment->id,
            'order_id' => $amendment->order_id,
            'delta' => round((float) $amendment->delta, 2),
            'total_after' => round((float) ($amendment->money_after['total'] ?? 0), 2),
            'expires_at' => $amendment->expires_at?->toIso8601String(),
            'seconds_left' => $amendment->secondsLeft(),
        ];
    }

    /** @return array<string, mixed> */
    private static function body(OrderAmendment $amendment, string $locale): array
    {
        $name = fn (array $row) => $locale === 'en' ? (($row['name_en'] ?? null) ?: $row['name_ar']) : $row['name_ar'];

        return [
            'lines' => array_map(fn (array $line) => [
                ...$line,
                'name' => $name($line),
                'options' => array_map(fn (array $o) => [...$o, 'name' => $name($o)], $line['options'] ?? []),
                'line_total_before' => round((float) $line['unit_price'] * (int) $line['quantity_before'], 2),
                'line_total_after' => round((float) $line['unit_price'] * (int) $line['quantity_after'], 2),
                'changed' => (int) $line['quantity_before'] !== (int) $line['quantity_after'],
            ], $amendment->lines ?? []),
            'additions' => array_map(fn (array $row) => [
                ...$row,
                'name' => $name($row),
                'line_total' => round((float) $row['unit_price'] * (int) $row['quantity'], 2),
            ], $amendment->additions ?? []),
            'money_before' => $amendment->money_before,
            'money_after' => $amendment->money_after,
            'delta' => round((float) $amendment->delta, 2),
            'note' => $amendment->note,
        ];
    }
}
