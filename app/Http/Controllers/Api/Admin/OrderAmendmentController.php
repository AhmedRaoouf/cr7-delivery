<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\ValidatesAmendments;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderAmendment;
use App\Support\AdminScope;
use App\Support\Amendments\AmendmentPayload;
use App\Support\Amendments\OrderAmendments;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * doc 99 §4, D-172 — the office proposes a change on the place's behalf (`orders.amend`),
 * e.g. when the place telephoned instead of pressing the button. The place's own switch is
 * not required; the platform switch is.
 */
class OrderAmendmentController extends Controller
{
    use ValidatesAmendments;

    public function index(Request $request, Order $order): JsonResponse
    {
        $this->ensureInScope($request, $order);

        return ApiResponse::success(
            $order->amendments()->with('proposer:id,name')->orderByDesc('id')->get()
                ->map(fn (OrderAmendment $a) => AmendmentPayload::forAdmin($a))->values(),
        );
    }

    public function store(Request $request, Order $order, OrderAmendments $amendments): JsonResponse
    {
        $this->ensureInScope($request, $order);
        OrderAmendments::assertAllowed($order, OrderAmendment::PROPOSER_ADMIN);

        $amendment = $amendments->propose(
            $request->user(),
            $order,
            $this->validatedAmendment($request, false),
            OrderAmendment::PROPOSER_ADMIN,
        );

        return ApiResponse::success(AmendmentPayload::forAdmin($amendment), 'أُرسل التعديل إلى العميل', status: 201);
    }

    public function destroy(Request $request, Order $order, OrderAmendment $amendment, OrderAmendments $amendments): JsonResponse
    {
        $this->ensureInScope($request, $order);

        $amendment = $amendments->withdraw($request->user(), $order, $amendment, OrderAmendment::PROPOSER_ADMIN);

        return ApiResponse::success(AmendmentPayload::forAdmin($amendment), 'تم سحب التعديل');
    }

    private function ensureInScope(Request $request, Order $order): void
    {
        abort_unless(
            AdminScope::applyToOrders(Order::query(), $request->user())->whereKey($order->id)->exists(),
            404,
        );
    }
}
