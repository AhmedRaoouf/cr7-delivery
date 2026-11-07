<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\CreateRestaurantAction;
use App\Actions\Admin\DeletePlaceAction;
use App\Actions\Admin\ResetUserPasswordAction;
use App\Enums\MediaField;
use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Concerns\HandlesUploads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRestaurantRequest;
use App\Http\Requests\Admin\UpdateRestaurantRequest;
use App\Models\Restaurant;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\Lists\ListSearch;
use App\Support\SettingsCatalog;
use App\Support\SettlementCycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RestaurantController extends Controller
{
    use FiltersLists;
    use HandlesUploads;

    public function index(Request $request): JsonResponse
    {
        $params = $this->listParams($request);
        $sort = $params->sort(['id', 'name', 'created_at', 'rating_avg', 'orders_count', 'sort_order'], 'id');

        $query = AdminScope::applyToRestaurants(Restaurant::query(), $request->user())
            ->with(['city', 'serviceZone']);

        $this->whereInList($query, 'restaurants.city_id', $params->ids('city_id'));
        $this->whereInList($query, 'restaurants.store_category_id', $params->ids('store_category_id'));
        $this->whereInList($query, 'restaurants.price_band', $params->enum('price_band', ['budget', 'mid', 'premium']));

        if (($zones = $params->ids('zone_id')) !== []) {
            // Its home zone, or any zone it delivers to — the same reach the scope reads.
            $query->where(fn ($q) => $q
                ->whereIn('restaurants.service_zone_id', $zones)
                ->orWhereHas('servedZones', fn ($z) => $z->whereIn('service_zones.id', $zones)));
        }

        if (($cuisines = $params->ids('cuisine_id')) !== []) {
            $query->whereHas('cuisines', fn ($c) => $c->whereIn('cuisines.id', $cuisines));
        }

        foreach (['is_active', 'is_verified', 'is_featured', 'direct_orders_enabled', 'amendments_enabled'] as $flag) {
            if (($value = $params->bool($flag)) !== null) {
                $query->where('restaurants.'.$flag, $value);
            }
        }

        if (($open = $params->bool('open_now')) !== null) {
            $query->openNow($open);
        }

        if (($pending = $params->bool('has_pending')) !== null) {
            $waiting = fn ($o) => $o->whereIn('status', ['pending', 'restaurant_timeout']);
            $pending ? $query->whereHas('orders', $waiting) : $query->whereDoesntHave('orders', $waiting);
        }

        // Folded, not `whereFullText`. A full-text index answers on whole words, so a
        // dashboard search box — which fires on every keystroke — matched nothing until
        // the operator finished a word, and never matched a partial one at all.
        ListSearch::apply($query, $params->search(), [
            'names' => ['name_ar', 'name_en'],
            'phones' => ['phone'],
            'refs' => ['slug'],
        ]);

        $restaurants = $sort->apply($query, ['name' => 'restaurants.name_ar'])->paginate($params->perPage());

        return $this->paginated($restaurants, sort: $sort);
    }

    public function show(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->ensureInScope($request, $restaurant);

        $restaurant->load(['city', 'serviceZone', 'cuisines', 'servedZones', 'openingHours', 'owner']);

        // doc 104 — the cycle the statements follow, worked out (default length, next date).
        return ApiResponse::success($restaurant->toArray() + [
            'statement_cycle' => SettlementCycle::of($restaurant)?->toArray(),
            'default_statement_days' => SettingsCatalog::int('settlements.cycle_days'),
        ]);
    }

    public function store(StoreRestaurantRequest $request, CreateRestaurantAction $action): JsonResponse
    {
        // Without this the validated array still holds the UploadedFile objects and the
        // action would try to persist them as column values.
        $data = $request->validated();

        // doc 103 — a new place starts on a rate only an admin who may set one can type.
        // doc 104 — and so is its statement cycle.
        $contract = (float) ($data['commission_rate'] ?? 0) > 0
            || isset($data['settlement_starts_on'])
            || isset($data['settlement_every_days']);
        if ($contract && ! $request->user()->can('restaurants.set_commission')) {
            throw new ApiException('commission_not_permitted', 'لا تملك صلاحية تعديل نسبة العمولة', 403);
        }

        $data = $this->withPublicUpload($data, $request, 'logo', 'restaurants', media: MediaField::RestaurantLogo);
        $data = $this->withPublicUpload($data, $request, 'cover', 'restaurants', media: MediaField::RestaurantCover);
        $data = $this->withPublicSheets($data, $request, 'menu_sheets', 'menus');

        $result = $action->execute($data);

        return ApiResponse::success([
            'restaurant' => $result['restaurant'],
            'owner_temp_password' => $result['temp_password'],
        ], 'تم إنشاء المطعم بنجاح', status: 201);
    }

    public function update(UpdateRestaurantRequest $request, Restaurant $restaurant): JsonResponse
    {
        $this->ensureInScope($request, $restaurant);

        $data = $this->guardCommission($request, $restaurant, $request->validated());
        $data = $this->guardAmendments($request, $restaurant, $data);
        $data = $this->withPublicUpload($data, $request, 'logo', 'restaurants', $restaurant, MediaField::RestaurantLogo);
        $data = $this->withPublicUpload($data, $request, 'cover', 'restaurants', $restaurant, MediaField::RestaurantCover);
        $data = $this->withPublicSheets($data, $request, 'menu_sheets', 'menus', $restaurant);
        $restaurant->update(collect($data)->except('cuisine_ids')->all());

        if (array_key_exists('cuisine_ids', $data)) {
            $restaurant->cuisines()->sync($data['cuisine_ids'] ?? []);
        }

        return ApiResponse::success($restaurant->fresh(['cuisines']), 'تم تحديث بيانات المطعم بنجاح');
    }

    public function status(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->ensureInScope($request, $restaurant);

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $restaurant->update($validated);

        return ApiResponse::success($restaurant, 'تم تحديث حالة المطعم بنجاح');
    }

    public function directOrders(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->ensureInScope($request, $restaurant);

        // Only the switch. The 20/day cap on `ADM-48` is D-49's platform-wide setting,
        // not a per-restaurant column — there is no `direct_orders_daily_cap` to write,
        // so one that arrives is ignored rather than refused.
        //
        // doc 63 §6 — either name. The mobile console posted `{direct_orders_enabled: …}`
        // — the column's name — to an endpoint that validated `enabled`, so every press on
        // the place screen answered «حقل التفعيل مطلوب.» and changed nothing. The installed
        // 1.0.3 keeps sending that name, and the web dashboard sends `enabled`.
        $validated = $request->validate([
            'enabled' => ['required_without:direct_orders_enabled', 'boolean'],
            'direct_orders_enabled' => ['required_without:enabled', 'boolean'],
        ]);

        $enabled = array_key_exists('enabled', $validated) && $validated['enabled'] !== null
            ? (bool) $validated['enabled']
            : (bool) $validated['direct_orders_enabled'];

        $restaurant->update(['direct_orders_enabled' => $enabled]);

        return ApiResponse::success($restaurant, 'تم تحديث إعداد الطلبات المباشرة بنجاح');
    }

    /**
     * doc 99 §4, D-172 — «صلاحية … مش لكل اليوزرات»: whether this place may propose a change
     * to a new order. Off by default; `restaurants.toggle_amendments`. Either name, the
     * same way the direct-orders switch takes either.
     */
    public function amendments(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->ensureInScope($request, $restaurant);

        $validated = $request->validate([
            'enabled' => ['required_without:amendments_enabled', 'boolean'],
            'amendments_enabled' => ['required_without:enabled', 'boolean'],
        ]);

        $enabled = array_key_exists('enabled', $validated) && $validated['enabled'] !== null
            ? (bool) $validated['enabled']
            : (bool) $validated['amendments_enabled'];

        $before = (bool) $restaurant->amendments_enabled;
        $restaurant->update(['amendments_enabled' => $enabled]);

        if ($before !== $enabled) {
            AuditLogger::record($request->user(), 'restaurants.toggle_amendments', $restaurant, ['amendments_enabled' => $enabled], ['amendments_enabled' => $before]);
        }

        return ApiResponse::success($restaurant->fresh(), $enabled ? 'تم السماح للمكان باقتراح تعديل على الطلبات' : 'تم إيقاف اقتراح التعديل لهذا المكان');
    }

    public function commission(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->ensureInScope($request, $restaurant);

        $validated = $request->validate([
            'commission_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            // doc 104 — optional: a rate set alone starts the cycle today.
            'settlement_starts_on' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:2026-01-01', 'before_or_equal:2030-12-31'],
            'settlement_every_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);
        $restaurant->update($validated);

        return ApiResponse::success($restaurant, 'تم تحديث نسبة العمولة بنجاح');
    }

    public function zones(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->ensureInScope($request, $restaurant);

        $validated = $request->validate([
            'zone_ids' => ['required', 'array'],
            'zone_ids.*' => ['exists:service_zones,id'],
        ]);

        $before = $restaurant->servedZones()->pluck('service_zones.id')->all();
        $restaurant->servedZones()->sync($validated['zone_ids']);

        // A `sync()` writes the pivot, not `restaurants`, so no model event fires and
        // AuditableObserver never sees it. Which zones a restaurant serves decides which
        // customers can order from it at all — recorded by hand.
        AuditLogger::record(
            $request->user(),
            'restaurants.zones',
            $restaurant,
            ['zone_ids' => $validated['zone_ids']],
            ['zone_ids' => $before],
        );

        return ApiResponse::success($restaurant->fresh(['servedZones']), 'تم تحديث مناطق التغطية بنجاح');
    }

    public function resetPassword(Request $request, Restaurant $restaurant, ResetUserPasswordAction $action): JsonResponse
    {
        $this->ensureInScope($request, $restaurant);

        $tempPassword = $action->execute($restaurant->owner);

        // The write lands on `users`, which is not observed here — `UserController` audits
        // that table itself and a second entry would double every row it writes. Never the
        // password: the audit trail records that it changed, not to what.
        AuditLogger::record($request->user(), 'restaurants.reset_password', $restaurant, ['owner_user_id' => $restaurant->owner->id]);

        return ApiResponse::success(['temp_password' => $tempPassword], 'تم إعادة تعيين كلمة المرور بنجاح');
    }

    public function destroy(Request $request, Restaurant $restaurant, DeletePlaceAction $action): JsonResponse
    {
        $this->ensureInScope($request, $restaurant);

        // Doc 91 — refused while an order is open; otherwise the baskets, favourites,
        // banners and dishes that point at the place go with it, and so does its owner's
        // sign-in (D-120).
        $action->execute($restaurant);

        return ApiResponse::success(message: 'تم حذف المطعم بنجاح');
    }

    /**
     * The commission travels on the restaurant form; the authority to change it does not.
     *
     * `restaurants.set_commission` is a separate grant from `restaurants.update` (D-51), and
     * D-52 forbids widening one by way of the other. But the form posts the field on every
     * save, so refusing its mere presence would lock an admin who holds only `restaurants.update`
     * out of editing a phone number. The line is the *value*: a save that leaves the number
     * where it is passes untouched, and a save that moves it is refused **by name** rather than
     * dropped in silence — which is what `validated()` did before the rule existed, answering
     * 200 to an edit it had thrown away.
     */
    private function guardCommission(Request $request, Restaurant $restaurant, array $data): array
    {
        // doc 104 — the statement cycle is part of the same contract, under the same grant.
        $unchanged = [
            'commission_rate' => fn ($v) => round((float) $v, 2) === round((float) $restaurant->commission_rate, 2),
            'settlement_starts_on' => fn ($v) => (string) $v === (string) $restaurant->settlement_starts_on?->toDateString(),
            'settlement_every_days' => fn ($v) => ($v === null ? null : (int) $v) === $restaurant->settlement_every_days,
        ];

        $moved = false;
        foreach ($unchanged as $key => $same) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            if ($same($data[$key])) {
                unset($data[$key]);
            } else {
                $moved = true;
            }
        }

        if (! $moved) {
            return $data;
        }

        // `ApiException`, not `abort(403)`. The dashboard reads the doc 08 §1 envelope
        // and `abort()` answers with Laravel's HTML error page, so the operator would
        // have been shown «حدث خطأ غير متوقع» for a refusal that has a reason.
        if (! $request->user()->can('restaurants.set_commission')) {
            throw new ApiException(
                'commission_not_permitted',
                'لا تملك صلاحية تعديل نسبة العمولة',
                403,
            );
        }

        return $data;
    }

    /**
     * doc 99 D-172 — `amendments_enabled` rides on `ADM-12`'s form, but it is its own grant:
     * an admin who may edit the place's details and not this switch is refused by name, and
     * a value that does not move the switch is dropped rather than refused.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function guardAmendments(Request $request, Restaurant $restaurant, array $data): array
    {
        if (! array_key_exists('amendments_enabled', $data)) {
            return $data;
        }

        if ($data['amendments_enabled'] === null || (bool) $data['amendments_enabled'] === (bool) $restaurant->amendments_enabled) {
            unset($data['amendments_enabled']);

            return $data;
        }

        if (! $request->user()->can('restaurants.toggle_amendments')) {
            throw new ApiException('amendments_toggle_not_permitted', 'لا تملك صلاحية تفعيل اقتراح التعديل لهذا المكان', 403);
        }

        $data['amendments_enabled'] = (bool) $data['amendments_enabled'];

        return $data;
    }

    private function ensureInScope(Request $request, Restaurant $restaurant): void
    {
        abort_unless(
            AdminScope::applyToRestaurants(Restaurant::query(), $request->user())->whereKey($restaurant->id)->exists(),
            404,
        );
    }
}
