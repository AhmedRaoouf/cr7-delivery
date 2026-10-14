<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserType;
use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Cuisine;
use App\Models\Driver;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Models\ServiceZone;
use App\Models\StoreCategory;
use App\Models\User;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\Lists\ListSearch;
use App\Support\Media\ThumbUrl;
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

    public const KINDS = [
        'restaurants', 'drivers', 'cities', 'zones', 'store_categories', 'cuisines', 'admins',
        // 2026-10-14 — the destination of a home banner or popup. «بيف بيكاتا من رملة» was
        // saved pointing at place `1` (سندوتش عمر) because the form asked for a number typed
        // by hand. These three carry what a person recognises — the picture, the place, the
        // price — so a slide's target is chosen by looking at it.
        'places', 'products', 'promo_codes',
    ];

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
                    ->select(['id', 'name_ar', 'name_en', 'image', 'is_active', 'is_fallback'])
                    ->tap(fn ($q) => ListSearch::apply($q, $params->search(), ['names' => ['name_ar', 'name_en']]))
                    // Doc 82 — «أخرى» last, so a product form's list reads the real ones first.
                    ->orderBy('is_fallback')->orderBy('sort_order')->orderBy('name_ar'),
                fn (Cuisine $c) => ['id' => $c->id, 'name' => $c->name_ar, 'name_en' => $c->name_en, 'image' => $c->image, 'is_fallback' => (bool) $c->is_fallback],
            ],
            'places' => [
                AdminScope::applyToRestaurants(Restaurant::query(), $admin)
                    ->select([
                        'id', 'name_ar', 'name_en', 'logo', 'cover', 'city_id', 'service_zone_id',
                        'store_category_id', 'rating_avg', 'rating_count', 'is_active', 'is_open_manual', 'is_24_7',
                    ])
                    ->with(['city:id,name_ar', 'serviceZone:id,name_ar', 'storeCategory:id,name_ar', 'openingHours'])
                    ->withCount(['products' => fn ($q) => $q->where('is_available', true)])
                    ->tap(fn ($q) => $this->whereInList($q, 'id', $params->ids('id')))
                    ->tap(fn ($q) => $this->whereInList($q, 'city_id', $params->ids('city_id')))
                    ->tap(fn ($q) => $this->whereInList($q, 'store_category_id', $params->ids('store_category_id')))
                    ->tap(fn ($q) => ListSearch::apply($q, $params->search(), ['names' => ['name_ar', 'name_en'], 'id' => true]))
                    ->orderByDesc('is_active')->orderBy('name_ar'),
                fn (Restaurant $r) => [
                    'id' => $r->id,
                    'name' => $r->name_ar,
                    'name_en' => $r->name_en,
                    'logo' => $r->logo,
                    'logo_thumb' => ThumbUrl::for($r->logo),
                    'cover_thumb' => ThumbUrl::for($r->cover),
                    'category' => $r->storeCategory?->name_ar,
                    'city' => $r->city?->name_ar,
                    'zone' => $r->serviceZone?->name_ar,
                    'rating_avg' => $r->rating_avg === null ? null : (float) $r->rating_avg,
                    'rating_count' => (int) $r->rating_count,
                    'products_count' => (int) $r->products_count,
                    'is_active' => (bool) $r->is_active,
                    'is_open' => $r->isOpenNow(),
                ],
            ],
            'products' => [
                Product::query()
                    ->whereIn('restaurant_id', AdminScope::applyToRestaurants(Restaurant::query(), $admin)->select('id'))
                    ->select(['id', 'restaurant_id', 'menu_category_id', 'name_ar', 'name_en', 'image', 'price', 'discount_price', 'is_available'])
                    ->with(['restaurant:id,name_ar,name_en,logo,is_active', 'menuCategory:id,name_ar'])
                    ->tap(fn ($q) => $this->whereInList($q, 'id', $params->ids('id')))
                    ->tap(fn ($q) => $this->whereInList($q, 'restaurant_id', $params->ids('restaurant_id')))
                    // «سماش تشيكرز» finds the dish by its own name or by its place's.
                    ->tap(fn ($q) => ListSearch::apply($q, $params->search(), [
                        'names' => ['name_ar', 'name_en'],
                        'id' => true,
                        'relations' => ['restaurant' => ['names' => ['name_ar', 'name_en']]],
                    ]))
                    ->orderByDesc('is_available')->orderBy('restaurant_id')->orderBy('name_ar'),
                fn (Product $p) => [
                    'id' => $p->id,
                    'name' => $p->name_ar,
                    'name_en' => $p->name_en,
                    'image' => $p->image,
                    'image_thumb' => ThumbUrl::for($p->image),
                    'price' => (float) $p->price,
                    'discount_price' => $p->discount_price === null ? null : (float) $p->discount_price,
                    'category' => $p->menuCategory?->name_ar,
                    'is_available' => (bool) $p->is_available,
                    'restaurant' => $p->restaurant === null ? null : [
                        'id' => $p->restaurant->id,
                        'name' => $p->restaurant->name_ar,
                        'logo_thumb' => ThumbUrl::for($p->restaurant->logo),
                        'is_active' => (bool) $p->restaurant->is_active,
                    ],
                ],
            ],
            'promo_codes' => [
                PromoCode::query()
                    ->select(['id', 'code', 'title_ar', 'title_en', 'type', 'value', 'max_discount', 'min_order_value', 'starts_at', 'ends_at', 'is_active'])
                    ->tap(fn ($q) => ListSearch::apply($q, $params->search(), ['names' => ['title_ar', 'title_en'], 'refs' => ['code']]))
                    ->orderByDesc('is_active')->orderBy('code'),
                fn (PromoCode $c) => [
                    'id' => $c->id,
                    'code' => $c->code,
                    'name' => $c->title_ar,
                    'type' => $c->type instanceof \BackedEnum ? $c->type->value : $c->type,
                    'value' => (float) $c->value,
                    'max_discount' => $c->max_discount === null ? null : (float) $c->max_discount,
                    'min_order_value' => $c->min_order_value === null ? null : (float) $c->min_order_value,
                    'ends_at' => $c->ends_at?->toIso8601String(),
                    'is_active' => (bool) $c->is_active,
                ],
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
            // A dish has no `is_active`; «available» is the same question asked of it.
            $column = $kind === 'products' ? 'is_available' : 'is_active';
            $query->where($query->getModel()->qualifyColumn($column), $active);
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
