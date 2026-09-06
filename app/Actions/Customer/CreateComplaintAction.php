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

        /** @var list<string> $attachments */
        $attachments = array_values(array_filter(
            $data['attachments'] ?? [],
            fn ($url) => is_string($url) && $url !== '',
        ));

        $role = $data['user_role'] ?? 'customer';

        $complaint = Complaint::create([
            'ticket_number' => 'CMP-'.now()->format('ymd').'-'.Str::upper(Str::random(5)),
            'user_id' => $user->id,
            'user_role' => $role,
            'order_id' => $order?->id,
            'order_group_id' => $order?->order_group_id,
            'restaurant_id' => $order?->restaurant_id,
            'category' => $data['category'],
            'subject' => $data['subject'] ?? null,
            'description' => $data['description'],
            'attachments' => $attachments === [] ? null : $attachments,
            'status' => 'open',
            'priority' => 'normal',
            'visible_to_restaurant' => $order && in_array($data['category'], self::AUTO_MIRROR_CATEGORIES, true),
        ]);

        /*
         * The ticket's own body becomes message #1 of its thread.
         *
         * Every reader of a complaint — the customer on `CUS-42`, the restaurant on
         * `RES-31`, the operator on `ADM-41` — renders `messages` and nothing else. So
         * what the customer actually typed, and every photo they attached, was written
         * to `complaints.description` / `complaints.attachments` and then shown to
         * **nobody**: all three screens opened on «لا توجد رسائل بعد» under a subject
         * line. Seeding it here fixes all three at once and keeps the thread honest —
         * the first thing in the conversation is the thing that started it.
         */
        $complaint->messages()->create([
            'sender_id' => $user->id,
            'sender_role' => $role,
            'visibility' => 'all',
            'message' => $data['description'],
            'attachments' => $attachments === [] ? null : $attachments,
        ]);

        return $complaint;
    }
}
