<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreServiceZoneRequest;
use App\Http\Requests\Admin\UpdateServiceZoneRequest;
use App\Models\ServiceZone;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceZoneController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $zones = AdminScope::applyToZones(ServiceZone::query(), $request->user())
            ->when($request->integer('city_id'), fn ($q, $cityId) => $q->where('city_id', $cityId))
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($zones->items(), meta: [
            'current_page' => $zones->currentPage(),
            'per_page' => $zones->perPage(),
            'total' => $zones->total(),
            'last_page' => $zones->lastPage(),
        ]);
    }

    public function show(Request $request, ServiceZone $zone): JsonResponse
    {
        $this->ensureInScope($request, $zone);

        return ApiResponse::success($zone->load(['city', 'deliveryPricingRules']));
    }

    public function store(StoreServiceZoneRequest $request): JsonResponse
    {
        $zone = ServiceZone::create($request->validated());

        return ApiResponse::success($zone, 'تم إنشاء المنطقة بنجاح', status: 201);
    }

    public function update(UpdateServiceZoneRequest $request, ServiceZone $zone): JsonResponse
    {
        $this->ensureInScope($request, $zone);

        $zone->update($request->validated());

        return ApiResponse::success($zone, 'تم تحديث المنطقة بنجاح');
    }

    public function destroy(Request $request, ServiceZone $zone): JsonResponse
    {
        $this->ensureInScope($request, $zone);

        $zone->delete();

        return ApiResponse::success(message: 'تم حذف المنطقة بنجاح');
    }

    private function ensureInScope(Request $request, ServiceZone $zone): void
    {
        abort_unless(
            AdminScope::applyToZones(ServiceZone::query(), $request->user())->whereKey($zone->id)->exists(),
            404,
        );
    }
}
