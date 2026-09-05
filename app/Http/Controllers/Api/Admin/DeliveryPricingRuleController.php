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

    /** doc 08 §7 — test tool: resolve the fee a given point would be charged for a restaurant. */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'restaurant_id' => ['required', 'exists:restaurants,id'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $restaurant = Restaurant::query()->findOrFail($validated['restaurant_id']);

        $distanceKm = Geo::haversineKm(
            (float) $restaurant->latitude,
            (float) $restaurant->longitude,
            (float) $validated['lat'],
            (float) $validated['lng'],
        );

        $rule = DeliveryPricingRule::query()
            ->where('city_id', $restaurant->city_id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('service_zone_id')->orWhere('service_zone_id', $restaurant->service_zone_id))
            ->where('min_distance_km', '<=', $distanceKm)
            ->where('max_distance_km', '>=', $distanceKm)
            ->orderByDesc('service_zone_id')
            ->first();

        return ApiResponse::success([
            'distance_km' => round($distanceKm, 3),
            'pricing_rule' => $rule,
            'fee' => $rule?->fee,
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
