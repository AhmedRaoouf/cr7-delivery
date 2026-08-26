<?php

namespace App\Actions\Customer;

use App\Models\Complaint;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * doc 24 §4.2 — category decides whether a ticket is mirrored to the restaurant it
 * concerns by default; Admin can flip visible_to_restaurant either way afterwards.
 */
class CreateComplaintAction
{
    private const AUTO_MIRROR_CATEGORIES = ['food_quality', 'missing_item', 'order_issue'];

    public function execute(User $user, array $data): Complaint
    {
        $order = isset($data['order_id']) ? Order::query()->where('user_id', $user->id)->find($data['order_id']) : null;

        return Complaint::create([
            'ticket_number' => 'CMP-'.now()->format('ymd').'-'.Str::upper(Str::random(5)),
            'user_id' => $user->id,
            'user_role' => 'customer',
            'order_id' => $order?->id,
            'order_group_id' => $order?->order_group_id,
            'restaurant_id' => $order?->restaurant_id,
            'category' => $data['category'],
            'subject' => $data['subject'] ?? null,
            'description' => $data['description'],
            'attachments' => $data['attachments'] ?? null,
            'status' => 'open',
            'priority' => 'normal',
            'visible_to_restaurant' => $order && in_array($data['category'], self::AUTO_MIRROR_CATEGORIES, true),
        ]);
    }
}
