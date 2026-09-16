<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\CreateDriverAction;
use App\Actions\Admin\ResetUserPasswordAction;
use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDriverRequest;
use App\Http\Requests\Admin\UpdateDriverRequest;
use App\Models\Driver;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\Auth\Sessions;
use App\Support\DriverTrip;
use App\Support\FileUploader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverController extends Controller
{
    use FiltersLists;

    public function index(Request $request): JsonResponse
    {
        $drivers = AdminScope::applyToDrivers(Driver::query(), $request->user())
            ->with(['user', 'city'])
            // doc 63 §3.6 — `ADM-19` prints «في رحلة (‪2‬)» beside `active_order_id`, which is
            // only the trip's focus now.
            ->withCount(['orders as trip_count' => fn ($q) => $q->whereIn('status', DriverTrip::STATES)])
            ->when($this->filterInt($request, 'city_id'), fn ($q, $cityId) => $q->where('city_id', $cityId))
            ->when($this->filterBool($request, 'is_online') !== null, fn ($q) => $q->where('is_online', $this->filterBool($request, 'is_online')))
            // `ADM-19` has had a search box since it was drawn and the endpoint never had
            // the filter, so typing a captain's name returned the whole fleet. The name and
            // the phone are both on `users`, which is why this is a `whereHas`.
            ->when($this->filterString($request, 'search') !== '', function ($q) use ($request) {
                $term = '%'.$this->filterString($request, 'search').'%';
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', $term)->orWhere('phone', 'like', $term));
            })
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

        return ApiResponse::success($driver->load(['user', 'city', 'assignedZones', 'documents'])
            ->loadCount(['orders as trip_count' => fn ($q) => $q->whereIn('status', DriverTrip::STATES)]));
    }

    public function store(StoreDriverRequest $request, CreateDriverAction $action): JsonResponse
    {
        $data = $request->validated();

        // The files are not columns on `drivers`; they become `driver_documents` rows
        // once the account exists, which is the same place an approved application puts
        // them and the same place `ADM-18` reads them from.
        $documents = [];
        foreach (['national_id' => 'national_id_file', 'national_id_back' => 'national_id_back_file'] as $type => $field) {
            unset($data[$field]);

            if ($request->hasFile($field)) {
                $documents[$type] = FileUploader::storePrivate($request->file($field), 'driver-documents');
            }
        }

        $result = $action->execute($data);

        foreach ($documents as $type => $path) {
            $result['driver']->documents()->create([
                'type' => $type,
                'file' => $path,
                // An Admin who uploaded it has seen it. Filing it as `pending` would ask
                // them to review their own upload.
                'status' => 'approved',
            ]);
        }

        return ApiResponse::success([
            'driver' => $result['driver'],
            'temp_password' => $result['temp_password'],
        ], 'تم إنشاء حساب الكابتن بنجاح', status: 201);
    }

    public function update(UpdateDriverRequest $request, Driver $driver): JsonResponse
    {
        $this->ensureInScope($request, $driver);

        $data = $request->validated();

        // `name`, `phone` and `email` are columns on `users`; passing them to
        // `Driver::update()` writes nothing and reports success, which is what the edit
        // form had been doing on every save.
        $userFields = collect($data)->only(['name', 'phone', 'email'])->all();
        if ($userFields !== []) {
            $driver->user->update($userFields);
        }

        $driver->update(collect($data)->except(['zone_ids', 'name', 'phone', 'email'])->all());

        if (array_key_exists('zone_ids', $data)) {
            $driver->assignedZones()->sync($data['zone_ids'] ?? []);
        }

        return ApiResponse::success($driver->fresh(['user', 'assignedZones']), 'تم تحديث بيانات الكابتن بنجاح');
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

        // D-120 — the captain is gone, so the sessions that opened the captain's app go
        // with it. The account row is left exactly as it was; only its sign-ins end, so a
        // phone that kept a token cannot keep calling the API as somebody the office has
        // just removed, and nothing a renewal holds can bring it back.
        if ($driver->user !== null) {
            Sessions::revokeAll($driver->user);
        }

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
