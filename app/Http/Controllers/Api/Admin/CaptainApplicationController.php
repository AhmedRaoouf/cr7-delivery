<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\CreateDriverAction;
use App\Enums\CaptainApplicationStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewCaptainApplicationRequest;
use App\Models\CaptainApplication;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ADM-30..ADM-32 — the captain application queue. Approving one is what creates the
 * account: `APP-03` never does (D-45).
 *
 * Scope is the applicant's city (D-50), so a regional admin sees only their own queue
 * and an out-of-scope row resolves as 404, never 403.
 */
class CaptainApplicationController extends Controller
{
    public function __construct(private readonly CreateDriverAction $createDriver) {}

    public function index(Request $request): JsonResponse
    {
        $applications = $this->scoped($request)
            ->with('city:id,name_ar,name_en')
            ->when($request->filled('filter.status'), fn ($q) => $q->where('status', $request->input('filter.status')))
            ->when($request->filled('filter.city_id'), fn ($q) => $q->where('city_id', $request->integer('filter.city_id')))
            ->when($request->filled('filter.search'), function ($q) use ($request) {
                $term = '%'.$request->input('filter.search').'%';
                $q->where(fn ($w) => $w->where('full_name', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('reference', 'like', $term));
            })
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($applications->items(), meta: [
            'current_page' => $applications->currentPage(),
            'per_page' => $applications->perPage(),
            'total' => $applications->total(),
            'last_page' => $applications->lastPage(),
        ]);
    }

    public function show(Request $request, int $application): JsonResponse
    {
        $row = $this->scoped($request)->with(['city', 'reviewer:id,name', 'createdDriver'])->findOrFail($application);

        return ApiResponse::success($row);
    }

    public function review(ReviewCaptainApplicationRequest $request, int $application): JsonResponse
    {
        $row = $this->scoped($request)->findOrFail($application);
        $admin = $request->user();
        $data = $request->validated();

        if (in_array($row->status, [CaptainApplicationStatus::Approved, CaptainApplicationStatus::Rejected], true)) {
            throw new ApiException('application_already_reviewed', 'تمت مراجعة هذا الطلب بالفعل', 422);
        }

        return DB::transaction(function () use ($row, $admin, $data) {
            $before = $row->only(['status', 'rejection_reason']);
            $tempPassword = null;

            if ($data['status'] === 'approved') {
                $created = $this->createDriver->execute([
                    'name' => $row->full_name,
                    'phone' => $row->phone,
                    'email' => $row->email,
                    'city_id' => $data['city_id'] ?? $row->city_id,
                    'national_id' => $row->national_id,
                    'vehicle_type' => $row->vehicle_type->value,
                    'vehicle_plate' => $row->vehicle_plate,
                    'earning_type' => $data['earning_type'] ?? Settings::get('driver.default_earning_type', 'fixed'),
                    'earning_value' => $data['earning_value'] ?? Settings::get('driver.default_earning_value', 15.00),
                    'zone_ids' => $data['zone_ids'] ?? [],
                ]);

                $row->created_driver_id = $created['driver']->id;
                $tempPassword = $created['temp_password'];
            }

            $row->fill([
                'status' => $data['status'],
                'rejection_reason' => $data['status'] === 'rejected' ? $data['rejection_reason'] : null,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            AuditLogger::record($admin, 'driver_applications.review', $row, $row->only(['status', 'rejection_reason']), $before);

            return ApiResponse::success([
                'application' => $row->fresh(['createdDriver']),
                // Shown once, on the admin's screen, so the captain can be given their
                // first sign-in. It is never stored and never re-readable.
                'temp_password' => $tempPassword,
            ], message: 'تم تحديث حالة الطلب');
        });
    }

    private function scoped(Request $request)
    {
        $query = CaptainApplication::query();
        $admin = $request->user();

        if (AdminScope::isGlobal($admin)) {
            return $query;
        }

        // An application with no resolved city is global-only: nobody regional owns it.
        return $query->whereIn('city_id', AdminScope::scopedCityIds($admin));
    }
}
