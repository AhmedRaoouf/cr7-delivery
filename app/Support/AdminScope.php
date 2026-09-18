<?php

namespace App\Support;

use App\Enums\AdminScopeType;
use App\Enums\UserType;
use App\Models\City;
use App\Models\Driver;
use App\Models\Restaurant;
use App\Models\ServiceZone;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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

    /**
     * Is this one order inside this operator's scope?
     *
     * The same rule as {@see self::applyToOrders()}, asked about a row rather than a
     * query — for the places that *push* to an operator instead of answering them. A push
     * ignoring the scope is worse than a wasted notification: tapping it opens the 404
     * that D-51 promises, which reads as a broken app rather than as a boundary.
     */
    public static function coversOrder(User $admin, Model $order): bool
    {
        if (self::isGlobal($admin)) {
            return true;
        }

        $zoneIds = self::scopedZoneIds($admin);
        $cityIds = self::scopedCityIds($admin);

        return in_array((int) $order->service_zone_id, array_map('intval', $zoneIds), true)
            || in_array((int) $order->city_id, array_map('intval', $cityIds), true);
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

    /*
    |--------------------------------------------------------------------------
    | Batch 67 — the entities doc 12 §10.1 lists and nothing scoped
    |--------------------------------------------------------------------------
    |
    | Complaints, payments, refunds, settlements, delivery issues, captain documents,
    | order groups, scheduled orders and moderated accounts were answered to any admin
    | holding the permission, whatever their scope. Each helper below states the rule
    | from doc 12 §10.1 in the query, so a scoped admin's list, counts and single-row
    | lookups (through {@see self::abortUnlessVisible()}) all agree.
    */

    /**
     * doc 12 §10.1 — a complaint follows its linked order; without one, the reporter:
     * a customer by the city of their default address, a captain by their zones, a
     * place by the place. Anything else is global-only.
     */
    public static function applyToComplaints(Builder $query, User $admin): Builder
    {
        if (self::isGlobal($admin)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($admin) {
            $q->whereHas('order', fn (Builder $order) => self::applyToOrders($order, $admin))
                ->orWhere(fn (Builder $unlinked) => $unlinked
                    ->whereNull('order_id')
                    ->whereHas('user', fn (Builder $reporter) => self::applyToAccounts($reporter, $admin)));
        });
    }

    /** doc 12 §10.1 — a payment follows the orders of its group. */
    public static function applyToPayments(Builder $query, User $admin): Builder
    {
        if (self::isGlobal($admin)) {
            return $query;
        }

        return $query->whereHas('orderGroup.orders', fn (Builder $order) => self::applyToOrders($order, $admin));
    }

    /** doc 12 §10.1 — a refund follows its order. */
    public static function applyToRefunds(Builder $query, User $admin): Builder
    {
        if (self::isGlobal($admin)) {
            return $query;
        }

        return $query->whereHas('order', fn (Builder $order) => self::applyToOrders($order, $admin));
    }

    /** A delivery issue follows its order. */
    public static function applyToDeliveryIssues(Builder $query, User $admin): Builder
    {
        return self::applyToRefunds($query, $admin);
    }

    /** An order group is in scope when any of its sub-orders is. */
    public static function applyToOrderGroups(Builder $query, User $admin): Builder
    {
        if (self::isGlobal($admin)) {
            return $query;
        }

        return $query->whereHas('orders', fn (Builder $order) => self::applyToOrders($order, $admin));
    }

    /** A captain's document follows the captain. */
    public static function applyToDriverDocuments(Builder $query, User $admin): Builder
    {
        if (self::isGlobal($admin)) {
            return $query;
        }

        return $query->whereHas('driver', fn (Builder $driver) => self::applyToDrivers($driver, $admin));
    }

    /** A settlement follows its party — the place or the captain it pays. */
    public static function applyToSettlements(Builder $query, User $admin): Builder
    {
        if (self::isGlobal($admin)) {
            return $query;
        }

        $restaurants = self::applyToRestaurants(Restaurant::query(), $admin)->select('restaurants.id');
        $drivers = self::applyToDrivers(Driver::query(), $admin)->select('drivers.id');

        return $query->where(function (Builder $q) use ($restaurants, $drivers) {
            $q->where(fn (Builder $r) => $r->where('party_type', 'restaurant')->whereIn('party_id', $restaurants))
                ->orWhere(fn (Builder $d) => $d->where('party_type', 'driver')->whereIn('party_id', $drivers));
        });
    }

    /** A scheduled basket follows the address it will be delivered to. */
    public static function applyToScheduledOrders(Builder $query, User $admin): Builder
    {
        if (self::isGlobal($admin)) {
            return $query;
        }

        $cityIds = self::scopedCityIds($admin);
        $zoneIds = self::scopedZoneIds($admin);

        return $query->whereHas('address', fn (Builder $address) => $address->where(function (Builder $q) use ($cityIds, $zoneIds) {
            $q->whereIn('service_zone_id', $zoneIds);

            if ($cityIds !== []) {
                $q->orWhereIn('city_id', $cityIds);
            }
        }));
    }

    /**
     * Any non-admin account, by the rule for its type: a customer by the city of their
     * default address, a captain by their zones, a place by the place. Admin accounts
     * are never in a scoped admin's reach (users are global-only).
     */
    public static function applyToAccounts(Builder $query, User $admin): Builder
    {
        if (self::isGlobal($admin)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($admin) {
            $q->where(fn (Builder $customer) => self::applyToCustomers($customer->where('type', UserType::Customer), $admin))
                ->orWhere(fn (Builder $captain) => $captain
                    ->where('type', UserType::Driver)
                    ->whereHas('driver', fn (Builder $driver) => self::applyToDrivers($driver, $admin)))
                ->orWhere(fn (Builder $place) => $place
                    ->where('type', UserType::Restaurant)
                    ->whereHas('restaurant', fn (Builder $restaurant) => self::applyToRestaurants($restaurant, $admin)));
        });
    }

    /**
     * The single-row half of every helper above: out of scope is a 404, the same answer
     * a row that does not exist gets (D-51). Pass the scoped query for the row's type.
     */
    public static function abortUnlessVisible(Builder $scoped, Model $row): void
    {
        abort_unless($scoped->whereKey($row->getKey())->exists(), 404);
    }
}
