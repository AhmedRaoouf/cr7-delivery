<?php

namespace App\Http\Controllers\Api\Customer;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\OrderAmendment;
use App\Models\OrderGroup;
use App\Support\Amendments\AmendmentPayload;
use App\Support\Amendments\OrderAmendments;
use App\Support\ApiResponse;
use App\Support\Locale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * doc 99 §4, D-172 — the customer's half: read the change, accept it at the total shown,
 * or decline it. The push `order.amendment_proposed` opens `cr7://order/{group}/amendment`.
 */
class OrderAmendmentController extends Controller
{
    /** The pending change, or the latest one so a late tap shows what became of it. */
    public function show(Request $request, OrderGroup $orderGroup): JsonResponse
    {
        $this->ensureOwned($request, $orderGroup);

        $amendment = $orderGroup->pendingAmendment()->first()
            ?? $orderGroup->amendments()->latest('id')->first();

        if ($amendment === null) {
            throw new ApiException('amendment_not_found', 'لا يوجد تعديل على هذا الطلب', 404);
        }

        $amendment->load(['order.restaurant', 'orderGroup.user']);

        return ApiResponse::success(AmendmentPayload::forCustomer($amendment, Locale::current($request)));
    }

    public function accept(Request $request, OrderGroup $orderGroup, OrderAmendment $amendment, OrderAmendments $amendments): JsonResponse
    {
        $this->ensureOwned($request, $orderGroup);
        $validated = $request->validate(['expected_total' => ['required', 'numeric', 'min:0']]);

        $amendment = $amendments->accept($request->user(), $orderGroup, $amendment, (float) $validated['expected_total']);
        $amendment->load(['order.restaurant', 'orderGroup.user']);

        return ApiResponse::success(AmendmentPayload::forCustomer($amendment, Locale::current($request)), 'تمت الموافقة على التعديل');
    }

    public function reject(Request $request, OrderGroup $orderGroup, OrderAmendment $amendment, OrderAmendments $amendments): JsonResponse
    {
        $this->ensureOwned($request, $orderGroup);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $amendment = $amendments->reject($request->user(), $orderGroup, $amendment, $validated['reason'] ?? null);
        $amendment->load(['order.restaurant', 'orderGroup.user']);

        return ApiResponse::success(
            AmendmentPayload::forCustomer($amendment, Locale::current($request)),
            'تم رفض التعديل، وسيرد المكان على طلبك كما هو',
        );
    }

    private function ensureOwned(Request $request, OrderGroup $orderGroup): void
    {
        abort_unless($orderGroup->user_id === $request->user()->id, 404);
    }
}
