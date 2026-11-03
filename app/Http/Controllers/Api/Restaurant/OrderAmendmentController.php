<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Concerns\ValidatesAmendments;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderAmendment;
use App\Support\Amendments\AmendmentPayload;
use App\Support\Amendments\OrderAmendments;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 99 §4, D-172 — «اقتراح تعديل»: the place proposes, and withdraws. */
class OrderAmendmentController extends Controller
{
    use ValidatesAmendments;

    public function store(Request $request, Order $order, OrderAmendments $amendments): JsonResponse
    {
        $this->ensureOwned($request, $order);

        // The switches before the body: a place that may not propose is told so, not
        // that its third line is malformed.
        OrderAmendments::assertAllowed($order, OrderAmendment::PROPOSER_RESTAURANT);

        $amendment = $amendments->propose(
            $request->user(),
            $order,
            $this->validatedAmendment($request, true),
            OrderAmendment::PROPOSER_RESTAURANT,
        );

        return ApiResponse::success(
            AmendmentPayload::forPlace($amendment),
            'أُرسل التعديل إلى العميل، وسيُقبل الطلب تلقائيًا عند موافقته',
            status: 201,
        );
    }

    public function destroy(Request $request, Order $order, OrderAmendment $amendment, OrderAmendments $amendments): JsonResponse
    {
        $this->ensureOwned($request, $order);

        $amendment = $amendments->withdraw($request->user(), $order, $amendment, OrderAmendment::PROPOSER_RESTAURANT);

        return ApiResponse::success(AmendmentPayload::forPlace($amendment), 'تم سحب التعديل، ويمكنك الآن قبول الطلب كما هو أو رفضه');
    }

    private function ensureOwned(Request $request, Order $order): void
    {
        $restaurant = $request->user()->restaurant;
        abort_unless($restaurant && $order->restaurant_id === $restaurant->id, 404);
    }
}
