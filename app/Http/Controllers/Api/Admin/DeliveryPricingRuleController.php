<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDeliveryPricingRuleRequest;
use App\Http\Requests\Admin\UpdateDeliveryPricingRuleRequest;
use App\Models\DeliveryPricingRule;
use App\Models\Restaurant;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\Geo;
use App\Support\ZoneLocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliveryPricingRuleController extends Controller
{
    use FiltersLists;

    public function index(Request $request): JsonResponse
    {
        $rules = AdminScope::applyToPricingRules(DeliveryPricingRule::query(), $request->user())
            ->when($this->filterInt($request, 'city_id'), fn ($q, $cityId) => $q->where('city_id', $cityId))
            ->when($this->filterInt($request, 'service_zone_id'), fn ($q, $zoneId) => $q->where('service_zone_id', $zoneId))
            ->orderBy('sort_order')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($rules->items(), meta: [
            'current_page' => $rules->currentPage(),
            'per_page' => $rules->perPage(),
            'total' => $rules->total(),
            'last_page' => $rules->lastPage(),
        ]);
    }

    public function show(Request $request, DeliveryPricingRule $pricingRule): JsonResponse
    {
        $this->ensureInScope($request, $pricingRule);

        return ApiResponse::success($pricingRule);
    }

    public function store(StoreDeliveryPricingRuleRequest $request): JsonResponse
    {
        $rule = DeliveryPricingRule::create($request->validated());

        return ApiResponse::success($rule, 'تم إنشاء قاعدة التسعير بنجاح', status: 201);
    }

    public function update(UpdateDeliveryPricingRuleRequest $request, DeliveryPricingRule $pricingRule): JsonResponse
    {
        $this->ensureInScope($request, $pricingRule);

        $pricingRule->update($request->validated());

        return ApiResponse::success($pricingRule, 'تم تحديث قاعدة التسعير بنجاح');
    }

    public function destroy(Request $request, DeliveryPricingRule $pricingRule): JsonResponse
    {
        $this->ensureInScope($request, $pricingRule);

        $pricingRule->delete();

        return ApiResponse::success(message: 'تم حذف قاعدة التسعير بنجاح');
    }

    /**
     * doc 08 §7 — test tool: resolve the fee a given point would be charged for a restaurant.
     *
     * Three things were wrong with this at once and each of them alone made `ADM-25`
     * unusable:
     *
     * 1. It validated `lat`/`lng` while the dashboard has always sent `latitude`/`longitude`,
     *    so the page opened on «حقل lat مطلوب» and could never be made to answer. Both
     *    spellings are accepted now — the client is the one that has shipped.
     * 2. It answered `{pricing_rule: {...}}` while the client parsed `rule_id` and
     *    `service_zone_name`, so even a valid answer rendered blank.
     * 3. It resolved the rule against the **restaurant's** zone and ignored the city's
     *    `pricing_type`, while a real quote resolves against the **delivery address's**
     *    zone and falls back to that zone's flat fee. A preview that disagrees with the
     *    checkout is worse than no preview: it is a wrong number an operator trusts.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'restaurant_id' => ['required', 'exists:restaurants,id'],
            'lat' => ['required_without:latitude', 'numeric', 'between:-90,90'],
            'lng' => ['required_without:longitude', 'numeric', 'between:-180,180'],
            'latitude' => ['required_without:lat', 'numeric', 'between:-90,90'],
            'longitude' => ['required_without:lng', 'numeric', 'between:-180,180'],
        ]);

        $lat = (float) ($validated['lat'] ?? $validated['latitude']);
        $lng = (float) ($validated['lng'] ?? $validated['longitude']);

        $restaurant = Restaurant::query()->with('city')->findOrFail($validated['restaurant_id']);

        $distanceKm = Geo::haversineKm(
            (float) $restaurant->latitude,
            (float) $restaurant->longitude,
            $lat,
            $lng,
        );

        // The zone the pin falls in — the same question `POST /geo/resolve` answers for a
        // customer, and the one whose fee a real order is charged.
        $zone = ZoneLocator::forPoint($lat, $lng);
        $city = $zone?->city ?? $restaurant->city;

        $rule = null;
        $fee = null;

        if ($city !== null && $city->pricing_type->value === 'distance_based') {
            $rule = DeliveryPricingRule::query()
                ->where('city_id', $city->id)
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('service_zone_id')->orWhere('service_zone_id', $zone?->id))
                ->where('min_distance_km', '<=', $distanceKm)
                ->where('max_distance_km', '>=', $distanceKm)
                ->orderByDesc('service_zone_id')
                ->first();

            $fee = $rule?->fee ?? $zone?->delivery_fee;
        } else {
            $fee = $zone?->delivery_fee;
        }

        return ApiResponse::success([
            'distance_km' => round($distanceKm, 3),
            'fee' => $fee === null ? null : round((float) $fee, 2),
            'rule_id' => $rule?->id,
            'pricing_rule' => $rule,
            'service_zone_id' => $zone?->id,
            'service_zone_name' => $zone?->name_ar,
            // `distance_based` or `zone_flat` — an operator staring at a fee that ignores
            // their tiers deserves to be told the city is not on tiers at all.
            'pricing_type' => $city?->pricing_type->value,
            'is_serviceable' => $zone !== null,
        ]);
    }

    private function ensureInScope(Request $request, DeliveryPricingRule $pricingRule): void
    {
        abort_unless(
            AdminScope::applyToPricingRules(DeliveryPricingRule::query(), $request->user())->whereKey($pricingRule->id)->exists(),
            404,
        );
    }
}
