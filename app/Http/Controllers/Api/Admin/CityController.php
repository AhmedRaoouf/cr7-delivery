<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCityRequest;
use App\Http\Requests\Admin\UpdateCityRequest;
use App\Models\City;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $cities = AdminScope::applyToCities(City::query(), $request->user())
            ->orderBy('sort_order')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($cities->items(), meta: [
            'current_page' => $cities->currentPage(),
            'per_page' => $cities->perPage(),
            'total' => $cities->total(),
            'last_page' => $cities->lastPage(),
        ]);
    }

    public function show(Request $request, City $city): JsonResponse
    {
        $this->ensureInScope($request, $city);

        return ApiResponse::success($city->load(['serviceZones', 'deliveryPricingRules']));
    }

    public function store(StoreCityRequest $request): JsonResponse
    {
        $city = City::create($request->validated());

        return ApiResponse::success($city, 'تم إنشاء المدينة بنجاح', status: 201);
    }

    public function update(UpdateCityRequest $request, City $city): JsonResponse
    {
        $this->ensureInScope($request, $city);

        $city->update($request->validated());

        return ApiResponse::success($city, 'تم تحديث المدينة بنجاح');
    }

    public function pricingType(Request $request, City $city): JsonResponse
    {
        $this->ensureInScope($request, $city);

        $validated = $request->validate(['pricing_type' => ['required', 'in:fixed_zone,distance_based']]);
        $city->update($validated);

        return ApiResponse::success($city, 'تم تحديث نظام التسعير بنجاح');
    }

    public function destroy(Request $request, City $city): JsonResponse
    {
        $this->ensureInScope($request, $city);

        $city->delete();

        return ApiResponse::success(message: 'تم حذف المدينة بنجاح');
    }

    private function ensureInScope(Request $request, City $city): void
    {
        abort_unless(
            AdminScope::applyToCities(City::query(), $request->user())->whereKey($city->id)->exists(),
            404,
        );
    }
}
