<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCityRequest;
use App\Http\Requests\Admin\UpdateCityRequest;
use App\Models\City;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CityController extends Controller
{
    public function index(): JsonResponse
    {
        $cities = City::query()->orderBy('sort_order')->paginate(request()->integer('per_page', 20));

        return ApiResponse::success($cities->items(), meta: [
            'current_page' => $cities->currentPage(),
            'per_page' => $cities->perPage(),
            'total' => $cities->total(),
            'last_page' => $cities->lastPage(),
        ]);
    }

    public function show(City $city): JsonResponse
    {
        return ApiResponse::success($city->load(['serviceZones', 'deliveryPricingRules']));
    }

    public function store(StoreCityRequest $request): JsonResponse
    {
        $city = City::create($request->validated());

        return ApiResponse::success($city, 'تم إنشاء المدينة بنجاح', status: 201);
    }

    public function update(UpdateCityRequest $request, City $city): JsonResponse
    {
        $city->update($request->validated());

        return ApiResponse::success($city, 'تم تحديث المدينة بنجاح');
    }

    public function destroy(City $city): JsonResponse
    {
        $city->delete();

        return ApiResponse::success(message: 'تم حذف المدينة بنجاح');
    }
}
