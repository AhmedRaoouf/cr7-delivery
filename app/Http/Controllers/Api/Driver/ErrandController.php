<?php

namespace App\Http\Controllers\Api\Driver;

use App\Actions\Errand\ErrandLifecycleAction;
use App\Enums\ErrandStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Shared\ErrandRequestResource;
use App\Models\Driver;
use App\Models\ErrandRequest;
use App\Support\ApiResponse;
use App\Support\Geo;
use App\Support\UploadedImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * doc 40 — «اطلب كابتن»: the captain's half.
 *
 * `board` is the open list every captain sees. It is **not** filtered by distance: the
 * fee is the customer's offer, and whether a 9 km ride is worth 60 is a judgement the
 * captain makes, not one a radius makes for them. It is *sorted* by distance, so the
 * near ones are still at the top.
 */
class ErrandController extends Controller
{
    public function board(Request $request): JsonResponse
    {
        $driver = $this->driver($request);

        $errands = ErrandRequest::query()
            ->where('status', ErrandStatus::Open->value)
            ->whereNull('driver_id')
            ->with('driver.user')
            ->latest()
            ->limit(50)
            ->get();

        // Sorted in PHP rather than SQL: haversine in a MySQL `ORDER BY` cannot use an
        // index anyway at this size, and the captain's own position is often null.
        if ($driver->current_lat !== null && $driver->current_lng !== null) {
            $errands = $errands->sortBy(fn (ErrandRequest $errand) => Geo::haversineKm(
                (float) $driver->current_lat,
                (float) $driver->current_lng,
                (float) $errand->pickup_lat,
                (float) $errand->pickup_lng,
            ))->values();
        }

        return ApiResponse::success(ErrandRequestResource::collection($errands));
    }

    public function mine(Request $request): JsonResponse
    {
        $driver = $this->driver($request);

        $errands = ErrandRequest::query()
            ->where('driver_id', $driver->id)
            ->with('driver.user')
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success(
            ErrandRequestResource::collection($errands->items()),
            meta: [
                'current_page' => $errands->currentPage(),
                'per_page' => $errands->perPage(),
                'total' => $errands->total(),
                'last_page' => $errands->lastPage(),
            ],
        );
    }

    public function show(Request $request, ErrandRequest $errand): JsonResponse
    {
        $driver = $this->driver($request);

        // An open errand is readable by anyone on shift; a taken one only by its captain.
        abort_unless(
            $errand->driver_id === $driver->id || $errand->status === ErrandStatus::Open,
            404,
        );

        return ApiResponse::success(new ErrandRequestResource($errand->load(['driver.user', 'events'])));
    }

    public function accept(Request $request, ErrandRequest $errand, ErrandLifecycleAction $action): JsonResponse
    {
        return ApiResponse::success(
            new ErrandRequestResource($action->accept($this->driver($request), $errand)),
            'أصبح المشوار لك',
        );
    }

    public function pickedUp(Request $request, ErrandRequest $errand, ErrandLifecycleAction $action): JsonResponse
    {
        return ApiResponse::success(
            new ErrandRequestResource($action->markPickedUp($this->driver($request), $errand)),
            'تم تأكيد الاستلام',
        );
    }

    public function deliver(Request $request, ErrandRequest $errand, ErrandLifecycleAction $action): JsonResponse
    {
        return ApiResponse::success(
            new ErrandRequestResource($action->markDelivered($this->driver($request), $errand)),
            'تم تسليم المشوار',
        );
    }

    /**
     * What the captain paid, and the photo of the till receipt.
     *
     * Multipart, because the receipt is the point. Operator, 2026-09-07: «أن يستطيع
     * الكابتن رفع صورة الفاتورة أو الأغراض لتأكيد المبلغ والمستندات للعميل وللإدارة» —
     * so the photos go on the public disk and travel to the customer's screen, not into
     * an admin-only vault.
     */
    public function purchases(Request $request, ErrandRequest $errand, ErrandLifecycleAction $action): JsonResponse
    {
        $validated = $request->validate([
            'purchases_amount' => ['required', 'numeric', 'min:0', 'max:100000'],
            'purchases_note' => ['nullable', 'string', 'max:500'],
            'receipts' => ['nullable', 'array', 'max:3'],
            'receipts.*' => ['image', 'max:5120'],
        ]);

        return ApiResponse::success(
            new ErrandRequestResource($action->recordPurchases(
                $this->driver($request),
                $errand,
                (float) $validated['purchases_amount'],
                $validated['purchases_note'] ?? null,
                UploadedImages::store($request, 'receipts', 'errands'),
            )),
            'تم تسجيل قيمة المشتريات',
        );
    }

    public function cancel(Request $request, ErrandRequest $errand, ErrandLifecycleAction $action): JsonResponse
    {
        $driver = $this->driver($request);
        abort_unless($errand->driver_id === $driver->id, 404);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return ApiResponse::success(
            new ErrandRequestResource($action->cancel($errand, $request->user(), 'driver', $validated['reason'])),
            'تم إلغاء المشوار',
        );
    }

    private function driver(Request $request): Driver
    {
        return $request->user()->driver()->firstOrFail();
    }
}
