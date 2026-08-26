<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreServiceZoneRequest;
use App\Http\Requests\Admin\UpdateServiceZoneRequest;
use App\Models\ServiceZone;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceZoneController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $zones = ServiceZone::query()
            ->when($request->integer('city_id'), fn ($q, $cityId) => $q->where('city_id', $cityId))
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($zones->items(), meta: [
            'current_page' => $zones->currentPage(),
            'per_page' => $zones->perPage(),
            'total' => $zones->total(),
            'last_page' => $zones->lastPage(),
        ]);
    }

    public function show(ServiceZone $zone): JsonResponse
    {
        return ApiResponse::success($zone->load(['city', 'deliveryPricingRules']));
    }

    public function store(StoreServiceZoneRequest $request): JsonResponse
    {
        $zone = ServiceZone::create($request->validated());

        return ApiResponse::success($zone, 'تم إنشاء المنطقة بنجاح', status: 201);
    }

    public function update(UpdateServiceZoneRequest $request, ServiceZone $zone): JsonResponse
    {
        $zone->update($request->validated());

        return ApiResponse::success($zone, 'تم تحديث المنطقة بنجاح');
    }

    public function destroy(ServiceZone $zone): JsonResponse
    {
        $zone->delete();

        return ApiResponse::success(message: 'تم حذف المنطقة بنجاح');
    }
}
