<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\CreateDriverAction;
use App\Actions\Admin\ResetUserPasswordAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDriverRequest;
use App\Http\Requests\Admin\UpdateDriverRequest;
use App\Models\Driver;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $drivers = Driver::query()
            ->with(['user', 'city'])
            ->when($request->integer('city_id'), fn ($q, $cityId) => $q->where('city_id', $cityId))
            ->when($request->has('is_online'), fn ($q) => $q->where('is_online', $request->boolean('is_online')))
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($drivers->items(), meta: [
            'current_page' => $drivers->currentPage(),
            'per_page' => $drivers->perPage(),
            'total' => $drivers->total(),
            'last_page' => $drivers->lastPage(),
        ]);
    }

    public function show(Driver $driver): JsonResponse
    {
        return ApiResponse::success($driver->load(['user', 'city', 'assignedZones', 'documents']));
    }

    public function store(StoreDriverRequest $request, CreateDriverAction $action): JsonResponse
    {
        $result = $action->execute($request->validated());

        return ApiResponse::success([
            'driver' => $result['driver'],
            'temp_password' => $result['temp_password'],
        ], 'تم إنشاء حساب الكابتن بنجاح', status: 201);
    }

    public function update(UpdateDriverRequest $request, Driver $driver): JsonResponse
    {
        $data = $request->validated();
        $driver->update(collect($data)->except('zone_ids')->all());

        if (array_key_exists('zone_ids', $data)) {
            $driver->assignedZones()->sync($data['zone_ids'] ?? []);
        }

        return ApiResponse::success($driver->fresh(['assignedZones']), 'تم تحديث بيانات الكابتن بنجاح');
    }

    public function toggleStatus(Driver $driver): JsonResponse
    {
        $driver->update(['is_active' => ! $driver->is_active]);

        return ApiResponse::success($driver, 'تم تحديث حالة الكابتن بنجاح');
    }

    public function setEarningRule(Request $request, Driver $driver): JsonResponse
    {
        $validated = $request->validate([
            'earning_type' => ['required', 'in:fixed,per_km,percent_of_delivery_fee'],
            'earning_value' => ['required', 'numeric', 'min:0'],
        ]);
        $driver->update($validated);

        return ApiResponse::success($driver, 'تم تحديث قاعدة الأرباح بنجاح');
    }

    public function adjustBalance(Request $request, Driver $driver): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $driver->increment('wallet_balance', $validated['amount']);

        $driver->transactions()->create([
            'type' => $validated['amount'] >= 0 ? 'bonus' : 'deduction',
            'amount' => $validated['amount'],
            'balance_after' => $driver->fresh()->wallet_balance,
            'note' => $validated['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return ApiResponse::success($driver->fresh(), 'تم تعديل الرصيد بنجاح');
    }

    public function resetPassword(Driver $driver, ResetUserPasswordAction $action): JsonResponse
    {
        $tempPassword = $action->execute($driver->user);

        return ApiResponse::success(['temp_password' => $tempPassword], 'تم إعادة تعيين كلمة المرور بنجاح');
    }
}
