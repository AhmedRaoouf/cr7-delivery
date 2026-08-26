<?php

namespace App\Support;

use App\Enums\AdminScopeType;
use App\Models\City;
use App\Models\ServiceZone;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * doc 12 §10 / doc 24 §1.2 — a permission answers "what", a scope answers "which rows".
 * Both must pass, as an intersection. Out-of-scope must resolve as 404 (models not found),
 * never 403 — callers filter the query, they never fetch-then-check.
 */
class AdminScope
{
    public static function isGlobal(User $admin): bool
    {
        return $admin->adminScopes()->where('scope_type', AdminScopeType::Global)->exists();
    }

    /** City ids directly granted via a city-type scope row. */
    public static function scopedCityIds(User $admin): array
    {
        return $admin->adminScopes()
            ->where('scope_type', AdminScopeType::City)
            ->whereNotNull('city_id')
            ->pluck('city_id')
            ->all();
    }

    /** Zone ids directly granted via a zone-type scope row, plus every zone inside a scoped city. */
    public static function scopedZoneIds(User $admin): array
    {
        $direct = $admin->adminScopes()
            ->where('scope_type', AdminScopeType::Zone)
            ->whereNotNull('zone_id')
            ->pluck('zone_id')
            ->all();

        $cityIds = self::scopedCityIds($admin);
        $viaCity = $cityIds === []
            ? []
            : ServiceZone::query()->whereIn('city_id', $cityIds)->pluck('id')->all();

        return array_values(array_unique([...$direct, ...$viaCity]));
    }

    /** Cities row-scoped: directly granted, or the parent city of a granted zone. */
    public static function applyToCities(Builder $query, User $admin): Builder
    {
        if (self::isGlobal($admin)) {
            return $query;
        }

        $cityIds = self::scopedCityIds($admin);
        $zoneIds = self::scopedZoneIds($admin);

        return $query->where(function (Builder $q) use ($cityIds, $zoneIds) {
            $q->whereIn('id', $cityIds);

            if ($zoneIds !== []) {
                $q->orWhereHas('serviceZones', fn (Builder $zq) => $zq->whereIn('id', $zoneIds));
            }
        });
    }

    public static function applyToZones(Builder $query, User $admin): Builder
    {
        if (self::isGlobal($admin)) {
            return $query;
        }

        return $query->whereIn('id', self::scopedZoneIds($admin));
    }

    /** doc 24 §1.2 — restaurant is in scope if its city or any served zone is in scope. */
    public static function applyToRestaurants(Builder $query, User $admin): Builder
    {
        if (self::isGlobal($admin)) {
            return $query;
        }

        $cityIds = self::scopedCityIds($admin);
        $zoneIds = self::scopedZoneIds($admin);

        return $query->where(function (Builder $q) use ($cityIds, $zoneIds) {
            $q->whereIn('city_id', $cityIds);

            if ($zoneIds !== []) {
                $q->orWhereHas('servedZones', fn (Builder $zq) => $zq->whereIn('service_zones.id', $zoneIds));
            }
        });
    }

    /** doc 24 §1.2 — driver is in scope if any assigned zone is in scope. */
    public static function applyToDrivers(Builder $query, User $admin): Builder
    {
        if (self::isGlobal($admin)) {
            return $query;
        }

        $zoneIds = self::scopedZoneIds($admin);

        return $query->whereHas('assignedZones', fn (Builder $zq) => $zq->whereIn('service_zones.id', $zoneIds));
    }

    /** doc 24 §1.2 — pricing rules follow their city. */
    public static function applyToPricingRules(Builder $query, User $admin): Builder
    {
        if (self::isGlobal($admin)) {
            return $query;
        }

        return $query->whereIn('city_id', self::scopedCityIds($admin));
    }

    /** doc 24 §1.2 — customer is scoped by their default address city; no address = global-only. */
    public static function applyToCustomers(Builder $query, User $admin): Builder
    {
        if (self::isGlobal($admin)) {
            return $query;
        }

        $cityIds = self::scopedCityIds($admin);

        return $query->whereHas(
            'defaultAddress',
            fn (Builder $aq) => $aq->whereIn('city_id', $cityIds),
        );
    }

    /** doc 24 §1.2 — orders/order_groups follow the delivery zone, else its city. */
    public static function applyToOrders(Builder $query, User $admin): Builder
    {
        if (self::isGlobal($admin)) {
            return $query;
        }

        $cityIds = self::scopedCityIds($admin);
        $zoneIds = self::scopedZoneIds($admin);

        return $query->where(function (Builder $q) use ($cityIds, $zoneIds) {
            $q->whereIn('service_zone_id', $zoneIds);

            if ($cityIds !== []) {
                $q->orWhereIn('city_id', $cityIds);
            }
        });
    }
}
