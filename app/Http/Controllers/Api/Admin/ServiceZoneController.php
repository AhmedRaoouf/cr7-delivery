<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreServiceZoneRequest;
use App\Http\Requests\Admin\UpdateServiceZoneRequest;
use App\Models\ServiceZone;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\Lists\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceZoneController extends Controller
{
    use FiltersLists;

    public function index(Request $request): JsonResponse
    {
        $params = $this->listParams($request);
        $sort = $params->sort(['id', 'sort_order', 'name', 'delivery_fee'], 'id');

        $query = AdminScope::applyToZones(ServiceZone::query(), $request->user());

        $this->whereInList($query, 'service_zones.city_id', $params->ids('city_id'));

        foreach (['is_active', 'is_selectable'] as $flag) {
            if (($value = $params->bool($flag)) !== null) {
                $query->where('service_zones.'.$flag, $value);
            }
        }

        ListSearch::apply($query, $params->search(), ['names' => ['name_ar', 'name_en']]);

        $zones = $sort->apply($query, ['name' => 'service_zones.name_ar'])->paginate($params->perPage());

        return $this->paginated($zones, sort: $sort);
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
