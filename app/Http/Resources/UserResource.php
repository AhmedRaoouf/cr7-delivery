<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'type' => $this->type,
            'avatar' => $this->avatar,
            'locale' => $this->locale,
            'theme' => $this->theme,
            'must_change_password' => $this->must_change_password,
            'phone_verified_at' => $this->phone_verified_at,
            'default_address_id' => $this->default_address_id,
            // D-38 — while this is in the future the account may only pay cash.
            // CUS-26 greys the online rows out ahead of the checkout blocker.
            'cash_only_until' => $this->cash_only_until,
            // doc 11 §7's three categories, so CUS-44 renders from the account
            // rather than from whatever this device happens to remember.
            'notification_settings' => [
                'order_updates' => (bool) $this->notify_orders,
                'captain_messages' => (bool) $this->notify_captain,
                'promotions' => (bool) $this->notify_promos,
            ],
        ];
    }
}
