<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\CreateDriverAction;
use App\Actions\Admin\ResetUserPasswordAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDriverRequest;
use App\Http\Requests\Admin\UpdateDriverRequest;
use App\Models\Driver;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $drivers = AdminScope::applyToDrivers(Driver::query(), $request->user())
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

    public function show(Request $request, Driver $driver): JsonResponse
    {
        $this->ensureInScope($request, $driver);

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
        $this->ensureInScope($request, $driver);

        $data = $request->validated();
        $driver->update(collect($data)->except('zone_ids')->all());

        if (array_key_exists('zone_ids', $data)) {
            $driver->assignedZones()->sync($data['zone_ids'] ?? []);
        }

        return ApiResponse::success($driver->fresh(['assignedZones']), 'تم تحديث بيانات الكابتن بنجاح');
    }

    public function status(Request $request, Driver $driver): JsonResponse
    {
        $this->ensureInScope($request, $driver);

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $driver->update($validated);

        return ApiResponse::success($driver, 'تم تحديث حالة الكابتن بنجاح');
    }

    public function earningRule(Request $request, Driver $driver): JsonResponse
    {
        $this->ensureInScope($request, $driver);

        $validated = $request->validate([
            'earning_type' => ['required', 'in:fixed,per_km,percent_of_delivery_fee'],
            'earning_value' => ['required', 'numeric', 'min:0'],
        ]);
        $driver->update($validated);

        return ApiResponse::success($driver, 'تم تحديث قاعدة الأرباح بنجاح');
    }

    public function transactions(Request $request, Driver $driver): JsonResponse
    {
        $this->ensureInScope($request, $driver);

        $validated = $request->validate([
            'type' => ['required', 'in:bonus,deduction'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $signedAmount = $validated['type'] === 'deduction' ? -$validated['amount'] : $validated['amount'];
        $driver->increment('wallet_balance', $signedAmount);

        $transaction = $driver->transactions()->create([
            'type' => $validated['type'],
            'amount' => $signedAmount,
            'balance_after' => $driver->fresh()->wallet_balance,
            'note' => $validated['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return ApiResponse::success($transaction, 'تم تسجيل الحركة المالية بنجاح', status: 201);
    }

    public function resetPassword(Request $request, Driver $driver, ResetUserPasswordAction $action): JsonResponse
    {
        $this->ensureInScope($request, $driver);

        $tempPassword = $action->execute($driver->user);

        // Same reasoning as the restaurant's: the write is on `users`, and the new password
        // is not what the log is for.
        AuditLogger::record($request->user(), 'drivers.reset_password', $driver, ['user_id' => $driver->user->id]);

        return ApiResponse::success(['temp_password' => $tempPassword], 'تم إعادة تعيين كلمة المرور بنجاح');
    }

    public function destroy(Request $request, Driver $driver): JsonResponse
    {
        $this->ensureInScope($request, $driver);

        $driver->delete();

        return ApiResponse::success(message: 'تم حذف الكابتن بنجاح');
    }

    private function ensureInScope(Request $request, Driver $driver): void
    {
        abort_unless(
            AdminScope::applyToDrivers(Driver::query(), $request->user())->whereKey($driver->id)->exists(),
            404,
        );
    }
}
