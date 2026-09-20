<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCityRequest;
use App\Http\Requests\Admin\UpdateCityRequest;
use App\Models\City;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\Lists\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CityController extends Controller
{
    use FiltersLists;

    public function index(Request $request): JsonResponse
    {
        $params = $this->listParams($request);
        $sort = $params->sort(['sort_order', 'name', 'id'], 'sort_order');

        $query = AdminScope::applyToCities(City::query(), $request->user());

        if (($active = $params->bool('is_active')) !== null) {
            $query->where('cities.is_active', $active);
        }

        ListSearch::apply($query, $params->search(), ['names' => ['name_ar', 'name_en']]);

        // The mobile place form fills its zone picker from this list, so a city arrives with
        // its zones — without them the picker of a chosen city was empty.
        $query->with(['serviceZones' => fn ($zones) => $zones->orderBy('name_ar')]);

        $cities = $sort->apply($query, ['name' => 'cities.name_ar'])->paginate($params->perPage());

        return $this->paginated($cities, sort: $sort);
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
