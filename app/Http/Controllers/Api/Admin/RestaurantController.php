<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\CreateRestaurantAction;
use App\Actions\Admin\ResetUserPasswordAction;
use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\FiltersLists;
use App\Enums\MediaField;
use App\Http\Controllers\Concerns\HandlesUploads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRestaurantRequest;
use App\Http\Requests\Admin\UpdateRestaurantRequest;
use App\Models\Restaurant;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\Auth\Sessions;
use App\Support\Lists\ListSearch;
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

        foreach (['is_active', 'is_verified', 'is_featured', 'direct_orders_enabled'] as $flag) {
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

        return ApiResponse::success($restaurant->load(['city', 'serviceZone', 'cuisines', 'servedZones', 'openingHours', 'owner']));
    }

    public function store(StoreRestaurantRequest $request, CreateRestaurantAction $action): JsonResponse
    {
        // Without this the validated array still holds the UploadedFile objects and the
        // action would try to persist them as column values.
        $data = $request->validated();
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

    public function commission(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->ensureInScope($request, $restaurant);

        $validated = $request->validate(['commission_rate' => ['required', 'numeric', 'min:0', 'max:100']]);
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

    public function destroy(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->ensureInScope($request, $restaurant);

        // D-120 — the place is gone, so the sessions its owner signed in with go with it:
        // the board, the tablet, the phone. Only the sign-ins end; the owner's account row
        // is left exactly as it was.
        if ($restaurant->owner !== null) {
            Sessions::revokeAll($restaurant->owner);
        }

        $restaurant->delete();

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
        if (! array_key_exists('commission_rate', $data)) {
            return $data;
        }

        if (round((float) $data['commission_rate'], 2) === round((float) $restaurant->commission_rate, 2)) {
            unset($data['commission_rate']);

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

    private function ensureInScope(Request $request, Restaurant $restaurant): void
    {
        abort_unless(
            AdminScope::applyToRestaurants(Restaurant::query(), $request->user())->whereKey($restaurant->id)->exists(),
            404,
        );
    }
}
