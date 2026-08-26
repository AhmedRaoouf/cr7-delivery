<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\CreateRestaurantAction;
use App\Actions\Admin\ResetUserPasswordAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRestaurantRequest;
use App\Http\Requests\Admin\UpdateRestaurantRequest;
use App\Models\Restaurant;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RestaurantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $restaurants = AdminScope::applyToRestaurants(Restaurant::query(), $request->user())
            ->with(['city', 'serviceZone'])
            ->when($request->integer('city_id'), fn ($q, $cityId) => $q->where('city_id', $cityId))
            ->when($request->string('search')->isNotEmpty(), fn ($q) => $q->whereFullText(['name_ar', 'name_en'], $request->string('search')))
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($restaurants->items(), meta: [
            'current_page' => $restaurants->currentPage(),
            'per_page' => $restaurants->perPage(),
            'total' => $restaurants->total(),
            'last_page' => $restaurants->lastPage(),
        ]);
    }

    public function show(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->ensureInScope($request, $restaurant);

        return ApiResponse::success($restaurant->load(['city', 'serviceZone', 'cuisines', 'servedZones', 'openingHours', 'owner']));
    }

    public function store(StoreRestaurantRequest $request, CreateRestaurantAction $action): JsonResponse
    {
        $result = $action->execute($request->validated());

        return ApiResponse::success([
            'restaurant' => $result['restaurant'],
            'owner_temp_password' => $result['temp_password'],
        ], 'تم إنشاء المطعم بنجاح', status: 201);
    }

    public function update(UpdateRestaurantRequest $request, Restaurant $restaurant): JsonResponse
    {
        $this->ensureInScope($request, $restaurant);

        $data = $request->validated();
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

        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $restaurant->update(['direct_orders_enabled' => $validated['enabled']]);

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

        $restaurant->servedZones()->sync($validated['zone_ids']);

        return ApiResponse::success($restaurant->fresh(['servedZones']), 'تم تحديث مناطق التغطية بنجاح');
    }

    public function resetPassword(Request $request, Restaurant $restaurant, ResetUserPasswordAction $action): JsonResponse
    {
        $this->ensureInScope($request, $restaurant);

        $tempPassword = $action->execute($restaurant->owner);

        return ApiResponse::success(['temp_password' => $tempPassword], 'تم إعادة تعيين كلمة المرور بنجاح');
    }

    public function destroy(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->ensureInScope($request, $restaurant);

        $restaurant->delete();

        return ApiResponse::success(message: 'تم حذف المطعم بنجاح');
    }

    private function ensureInScope(Request $request, Restaurant $restaurant): void
    {
        abort_unless(
            AdminScope::applyToRestaurants(Restaurant::query(), $request->user())->whereKey($restaurant->id)->exists(),
            404,
        );
    }
}
