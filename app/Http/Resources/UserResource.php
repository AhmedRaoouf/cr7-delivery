<?php

namespace App\Http\Resources;

use App\Enums\AdminScopeType;
use App\Enums\UserType;
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
            // doc 12 §7 / D-50 — an admin's authority is `role ∩ scope`, and the Admin
            // dashboard cannot render an honest shell without both. Without them the
            // client either offers every action and lets the server 403 half of them,
            // or hides everything; neither is the spec. Exposed for admins only —
            // a customer or a restaurant has no roles and no scope to publish.
            ...($this->type === UserType::Admin ? [
                'roles' => $this->getRoleNames()->values()->all(),
                'permissions' => $this->getAllPermissions()->pluck('name')->values()->all(),
                'scope' => $this->adminScopePayload(),
            ] : []),
            // The restaurant dashboard resolves its own restaurant from the session
            // rather than making a second call for it on every boot.
            ...($this->type === UserType::Restaurant ? [
                'restaurant_id' => $this->restaurant?->id,
            ] : []),
        ];
    }

    /**
     * doc 12 §10 — a scope answers «which rows», never «what». `global` wins outright,
     * so it is reported alone rather than alongside city/zone rows that would never be
     * consulted.
     *
     * @return array<string, mixed>|null
     */
    private function adminScopePayload(): ?array
    {
        $scopes = $this->adminScopes;

        if ($scopes->isEmpty()) {
            return null;
        }

        if ($scopes->contains(fn ($scope) => $scope->scope_type === AdminScopeType::Global)) {
            return ['type' => 'global', 'city_ids' => [], 'zone_ids' => []];
        }

        $cityIds = $scopes->where('scope_type', AdminScopeType::City)->pluck('city_id')->filter()->values()->all();
        $zoneIds = $scopes->where('scope_type', AdminScopeType::Zone)->pluck('zone_id')->filter()->values()->all();

        return [
            'type' => $zoneIds !== [] && $cityIds === [] ? 'zone' : 'city',
            'city_ids' => $cityIds,
            'zone_ids' => $zoneIds,
        ];
    }
}
