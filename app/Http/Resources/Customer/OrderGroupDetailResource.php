<?php

namespace App\Http\Resources\Customer;

use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** doc 08 §4 — GET /orders/{id}: full detail + timeline. */
class OrderGroupDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = Locale::current($request);

        return [
            'id' => $this->id,
            'group_number' => $this->group_number,
            'rollup_status' => $this->rollup_status,
            'restaurant_count' => $this->restaurant_count,
            'payment_method' => $this->payment_method,
            'delivery_address_text' => $this->delivery_address_text,
            'subtotal' => (float) $this->subtotal,
            'discount_amount' => (float) $this->discount_amount,
            'delivery_base_fee' => (float) $this->delivery_base_fee,
            'extra_stop_fee_total' => (float) $this->extra_stop_fee_total,
            'delivery_fee' => (float) $this->delivery_fee,
            'platform_fee' => (float) $this->platform_fee,
            'total' => (float) $this->total,
            'customer_note' => $this->customer_note,
            'delivery_instructions' => $this->delivery_instructions,
            'placed_at' => $this->placed_at,
            // CUS-32 counts down to this. It also lives on GET /orders/{id}/confirmation,
            // but that route throws `confirmation_not_open` unless the gate is open —
            // so the countdown would need a request that fails by design.
            'confirm_deadline_at' => $this->confirm_deadline_at,
            'confirmation_opened_at' => $this->confirmation_opened_at,
            'confirmed_at' => $this->confirmed_at,
            'completed_at' => $this->completed_at,
            'sub_orders' => $this->whenLoaded('orders', fn () => $this->orders->map(fn ($order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'group_sequence' => $order->group_sequence,
                'restaurant' => [
                    'id' => $order->restaurant->id,
                    'name' => $order->restaurant->{"name_{$locale}"},
                    'phone' => $order->restaurant->phone,
                ],
                'status' => $order->status,
                'status_label' => $order->status->label(),
                'subtotal' => (float) $order->subtotal,
                'discount_share' => (float) $order->discount_share,
                'delivery_fee_share' => (float) $order->delivery_fee_share,
                'platform_fee_share' => (float) $order->platform_fee_share,
                'total' => (float) $order->total,
                // doc 04 §11.3 — the customer reads their own delivery code, and only
                // once the captain physically holds the food. Exposing it earlier
                // would let it be handed over before there is anything to hand over.
                // A direct order has none at all (D-49), so this stays null there.
                'delivery_otp' => in_array($order->status->value, ['picked_up', 'on_the_way', 'arrived_customer'], true)
                    ? $order->delivery_otp
                    : null,
                'delivery_otp_resends' => $order->delivery_otp_resends,
                'prep_minutes' => $order->prep_minutes,
                'prep_due_at' => $order->prep_due_at,
                'driver' => $order->driver ? [
                    'id' => $order->driver->id,
                    'name' => $order->driver->user->name,
                    'phone' => in_array($order->status->value, ['driver_assigned', 'arrived_restaurant', 'waiting_at_restaurant', 'picked_up', 'on_the_way', 'arrived_customer', 'delivered'], true)
                        ? $order->driver->user->phone
                        : null,
                    'rating_avg' => (float) $order->driver->rating_avg,
                    'vehicle_type' => $order->driver->vehicle_type,
                ] : null,
                'items' => $order->items->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $locale === 'en' ? $item->product_name_en_snapshot : $item->product_name_ar_snapshot,
                    'image' => $item->product_image_snapshot,
                    'unit_price' => (float) $item->unit_price_snapshot,
                    'options' => $item->options->map(fn ($o) => [
                        'name' => $locale === 'en' ? $o->option_name_en_snapshot : $o->option_name_ar_snapshot,
                        'price_delta' => (float) $o->price_delta_snapshot,
                    ]),
                    'quantity' => $item->quantity,
                    'line_total' => (float) $item->line_total,
                    'note' => $item->note,
                ]),
                'timeline' => $order->statusHistory->map(fn ($h) => [
                    'status' => $h->to_status,
                    'at' => $h->created_at,
                ]),
            ])),
        ];
    }
}
