<?php

use App\Models\OrderGroup;
use App\Models\User;
use App\Support\AdminScope;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels
|--------------------------------------------------------------------------
|
| Every channel here is private, and every callback answers the same question the
| matching HTTP endpoint answers. That is deliberate: `OrderChanged` carries no row
| data, so the worst a mis-scoped subscription could leak is the fact that *some*
| order moved — but D-52 says out-of-scope is invisible, not merely unreadable, so
| the scope check is done here as well.
|
| These routes are registered in `bootstrap/app.php` on the `auth:sanctum` guard,
| at `/api/v1/broadcasting/auth`. Laravel's default `/broadcasting/auth` runs on the
| `web` guard, which no client of this API ever holds (D-69) — it would answer 403
| for everybody.
|
*/

/** The restaurant board. One channel per restaurant, joinable only by its own staff. */
Broadcast::channel('restaurant.{restaurantId}', function (User $user, int $restaurantId): bool {
    return $user->isRestaurant() && (int) ($user->restaurant?->id) === $restaurantId;
});

/** Admin fan-out, split per city so a scoped sub-admin is not told about other cities (D-51). */
Broadcast::channel('admin.city.{cityId}', function (User $user, int $cityId): bool {
    if (! $user->isAdmin()) {
        return false;
    }

    return AdminScope::isGlobal($user)
        || in_array($cityId, array_map('intval', AdminScope::scopedCityIds($user)), true);
});

/** Orders with no city land here; only a global admin sees them. */
Broadcast::channel('admin.orders', function (User $user): bool {
    return $user->isAdmin() && AdminScope::isGlobal($user);
});

/** The customer's own basket, followed through delivery. */
Broadcast::channel('order-group.{groupId}', function (User $user, int $groupId): bool {
    return OrderGroup::query()->whereKey($groupId)->where('user_id', $user->id)->exists();
});

/** The captain's own job feed. */
Broadcast::channel('driver.{driverId}', function (User $user, int $driverId): bool {
    return $user->isDriver() && (int) ($user->driver?->id) === $driverId;
});
