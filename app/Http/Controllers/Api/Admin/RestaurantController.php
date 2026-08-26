<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\CreateRestaurantAction;
use App\Actions\Admin\ResetUserPasswordAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRestaurantRequest;
use App\Http\Requests\Admin\UpdateRestaurantRequest;
use App\Models\Restaurant;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RestaurantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $restaurants = Restaurant::query()
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

    public function show(Restaurant $restaurant): JsonResponse
    {
        return ApiResponse::success($restaurant->load(['city', 'serviceZone', 'cuisines', 'openingHours', 'owner']));
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
        $data = $request->validated();
        $restaurant->update(collect($data)->except('cuisine_ids')->all());

        if (array_key_exists('cuisine_ids', $data)) {
            $restaurant->cuisines()->sync($data['cuisine_ids'] ?? []);
        }

        return ApiResponse::success($restaurant->fresh(['cuisines']), 'تم تحديث بيانات المطعم بنجاح');
    }

    public function toggleStatus(Request $request): JsonResponse
    {
        $restaurant = $request->route('restaurant');
        $restaurant->update(['is_active' => ! $restaurant->is_active]);

        return ApiResponse::success($restaurant, 'تم تحديث حالة المطعم بنجاح');
    }

    public function toggleDirectOrders(Request $request): JsonResponse
    {
        $restaurant = $request->route('restaurant');
        $restaurant->update(['direct_orders_enabled' => ! $restaurant->direct_orders_enabled]);

        return ApiResponse::success($restaurant, 'تم تحديث إعداد الطلبات المباشرة بنجاح');
    }

    public function setCommission(Request $request, Restaurant $restaurant): JsonResponse
    {
        $validated = $request->validate(['commission_rate' => ['required', 'numeric', 'min:0', 'max:100']]);
        $restaurant->update($validated);

        return ApiResponse::success($restaurant, 'تم تحديث نسبة العمولة بنجاح');
    }

    public function resetPassword(Restaurant $restaurant, ResetUserPasswordAction $action): JsonResponse
    {
        $tempPassword = $action->execute($restaurant->owner);

        return ApiResponse::success(['temp_password' => $tempPassword], 'تم إعادة تعيين كلمة المرور بنجاح');
    }
}
