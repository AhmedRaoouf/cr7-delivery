<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDeliveryPricingRuleRequest;
use App\Http\Requests\Admin\UpdateDeliveryPricingRuleRequest;
use App\Models\DeliveryPricingRule;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliveryPricingRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rules = DeliveryPricingRule::query()
            ->when($request->integer('city_id'), fn ($q, $cityId) => $q->where('city_id', $cityId))
            ->when($request->integer('service_zone_id'), fn ($q, $zoneId) => $q->where('service_zone_id', $zoneId))
            ->orderBy('sort_order')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($rules->items(), meta: [
            'current_page' => $rules->currentPage(),
            'per_page' => $rules->perPage(),
            'total' => $rules->total(),
            'last_page' => $rules->lastPage(),
        ]);
    }

    public function store(StoreDeliveryPricingRuleRequest $request): JsonResponse
    {
        $rule = DeliveryPricingRule::create($request->validated());

        return ApiResponse::success($rule, 'تم إنشاء قاعدة التسعير بنجاح', status: 201);
    }

    public function update(UpdateDeliveryPricingRuleRequest $request, DeliveryPricingRule $rule): JsonResponse
    {
        $rule->update($request->validated());

        return ApiResponse::success($rule, 'تم تحديث قاعدة التسعير بنجاح');
    }

    public function destroy(DeliveryPricingRule $rule): JsonResponse
    {
        $rule->delete();

        return ApiResponse::success(message: 'تم حذف قاعدة التسعير بنجاح');
    }
}
