<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserType;
use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Cuisine;
use App\Models\Driver;
use App\Models\Restaurant;
use App\Models\ServiceZone;
use App\Models\StoreCategory;
use App\Models\User;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\Lists\ListSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Batch 68 — `GET /admin/lookups/{kind}` (ADMIN-LISTS.md §1.27): the rows a filter's
 * picker needs, and nothing else.
 *
 * Every filter on every admin list names a place, a captain, a city, a zone or an
 * assignee by id, and the dashboards filled those pickers by asking the full list
 * endpoints for 200 rows — a whole restaurant with its city and zone per option, to draw
 * a name. Two or three columns a row here, scoped exactly like the list the picker feeds
 * (a city admin's restaurant picker holds their own city's places, D-51), sorted by name,
 * capped rather than paged.
 *
 * Open to every admin: a picker is how a list with its own permission is narrowed, and
 * an operator allowed to read orders must be able to narrow them by place without also
 * holding `restaurants.view`. What leaves is an id and a name.
 */
class LookupController extends Controller
{
    use FiltersLists;

    public const KINDS = ['restaurants', 'drivers', 'cities', 'zones', 'store_categories', 'cuisines', 'admins'];

    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    public function show(Request $request, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);

        $admin = $request->user();
        $params = $this->listParams($request);
        $limit = max(1, min(self::MAX_LIMIT, (int) ($request->input('limit') ?: self::DEFAULT_LIMIT)));

        [$query, $row] = match ($kind) {
            'restaurants' => [
                AdminScope::applyToRestaurants(Restaurant::query(), $admin)
                    ->select(['id', 'name_ar', 'name_en', 'city_id', 'is_active'])
                    ->tap(fn ($q) => $this->whereInList($q, 'city_id', $params->ids('city_id')))
                    ->tap(fn ($q) => ListSearch::apply($q, $params->search(), ['names' => ['name_ar', 'name_en']]))
                    ->orderBy('name_ar'),
                fn (Restaurant $r) => ['id' => $r->id, 'name' => $r->name_ar, 'name_en' => $r->name_en, 'city_id' => $r->city_id, 'is_active' => (bool) $r->is_active],
            ],
            'drivers' => [
                AdminScope::applyToDrivers(Driver::query(), $admin)
                    ->select(['drivers.id', 'drivers.user_id', 'drivers.city_id', 'drivers.is_online', 'drivers.is_active'])
                    ->with('user:id,name,phone')
                    ->tap(fn ($q) => $this->whereInList($q, 'drivers.city_id', $params->ids('city_id')))
                    ->tap(fn ($q) => ListSearch::apply($q, $params->search(), [
                        'relations' => ['user' => ['names' => ['name'], 'phones' => ['phone']]],
                    ]))
                    ->orderBy(User::query()->select('name')->whereColumn('users.id', 'drivers.user_id')),
                fn (Driver $d) => ['id' => $d->id, 'name' => $d->user?->name, 'phone' => $d->user?->phone, 'is_online' => (bool) $d->is_online, 'is_active' => (bool) $d->is_active],
            ],
            'cities' => [
                AdminScope::applyToCities(City::query(), $admin)
                    ->select(['id', 'name_ar', 'name_en', 'is_active'])
                    ->tap(fn ($q) => ListSearch::apply($q, $params->search(), ['names' => ['name_ar', 'name_en']]))
                    ->orderBy('sort_order')->orderBy('name_ar'),
                fn (City $c) => ['id' => $c->id, 'name' => $c->name_ar, 'name_en' => $c->name_en],
            ],
            'zones' => [
                AdminScope::applyToZones(ServiceZone::query(), $admin)
                    ->select(['id', 'name_ar', 'name_en', 'city_id', 'is_active'])
                    ->tap(fn ($q) => $this->whereInList($q, 'city_id', $params->ids('city_id')))
                    ->tap(fn ($q) => ListSearch::apply($q, $params->search(), ['names' => ['name_ar', 'name_en']]))
                    ->orderBy('name_ar'),
                fn (ServiceZone $z) => ['id' => $z->id, 'name' => $z->name_ar, 'name_en' => $z->name_en, 'city_id' => $z->city_id],
            ],
            'store_categories' => [
                StoreCategory::query()
                    ->select(['id', 'name_ar', 'name_en', 'slug', 'is_active'])
                    ->tap(fn ($q) => ListSearch::apply($q, $params->search(), ['names' => ['name_ar', 'name_en']]))
                    ->orderBy('sort_order')->orderBy('name_ar'),
                fn (StoreCategory $c) => ['id' => $c->id, 'name' => $c->name_ar, 'name_en' => $c->name_en, 'slug' => $c->slug],
            ],
            'cuisines' => [
                Cuisine::query()
                    ->select(['id', 'name_ar', 'name_en', 'is_active'])
                    ->tap(fn ($q) => ListSearch::apply($q, $params->search(), ['names' => ['name_ar', 'name_en']]))
                    ->orderBy('sort_order')->orderBy('name_ar'),
                fn (Cuisine $c) => ['id' => $c->id, 'name' => $c->name_ar, 'name_en' => $c->name_en],
            ],
            'admins' => [
                $this->assignees($admin)
                    ->select(['id', 'name'])
                    ->tap(fn ($q) => ListSearch::apply($q, $params->search(), ['names' => ['name']]))
                    ->orderBy('name'),
                fn (User $u) => ['id' => $u->id, 'name' => $u->name],
            ],
        };

        if (($active = $params->bool('is_active')) !== null && $kind !== 'admins') {
            $query->where($query->getModel()->qualifyColumn('is_active'), $active);
        }

        $rows = $query->limit($limit + 1)->get();

        return ApiResponse::success($rows->take($limit)->map($row)->values(), meta: [
            'count' => min($rows->count(), $limit),
            'truncated' => $rows->count() > $limit,
        ]);
    }

    /**
     * Admin names are an assignee picker's (`ADM-41`'s «مُسند إلى»): a global admin, or one
     * who may read complaints, sees them; anyone else gets a 404, like every global-only
     * list (D-141).
     *
     * @return Builder<User>
     */
    private function assignees(User $admin): Builder
    {
        abort_unless(
            AdminScope::isGlobal($admin) || $admin->can('complaints.view') || $admin->can('users.view'),
            404,
        );

        return User::query()
            ->where('type', UserType::Admin)
            ->where('is_active', true);
    }
}
