<?php

namespace App\Http\Resources\Restaurant;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * doc 08 §6.3 / doc 24 §4.3 — the redacted mirror. A different Resource from the
 * admin one, not the same one with fields hidden at render time: first name only,
 * its own sub-order only, no group total, no sibling restaurant, status only.
 */
class ComplaintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $firstName = $this->user ? explode(' ', trim($this->user->name ?? ''))[0] : null;

        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'category' => $this->category,
            'subject' => $this->subject,
            'description' => $this->description,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'customer_first_name' => $firstName,
            'order' => $this->whenLoaded('order', fn () => $this->order ? [
                'order_number' => $this->order->order_number,
                'items' => $this->order->items->map(fn ($i) => ['name' => $i->product_name_ar_snapshot, 'quantity' => $i->quantity]),
                'total' => (float) $this->order->total,
            ] : null),
            'messages' => $this->whenLoaded('messages', fn () => $this->messages->where('visibility', 'all')->values()->map(fn ($m) => [
                'id' => $m->id,
                'sender_role' => $m->sender_role,
                'message' => $m->message,
                // The dashboards read `body`; the mobile client reads `message`. Both
                // travel, so neither has to know about the other's name for the column.
                'body' => $m->message,
                'attachments' => $m->attachments,
                'created_at' => $m->created_at,
            ])),
        ];
    }
}
