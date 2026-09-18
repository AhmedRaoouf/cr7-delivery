<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Refund;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\RefundPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** doc 08 §7 — GET/POST /admin/refunds, POST /{id}/process. */
class RefundController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $refunds = AdminScope::applyToRefunds(Refund::query(), $request->user())
            ->with(['order.restaurant', 'processedBy'])
            ->when($request->string('filter.status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('filter.status')))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($refunds->items(), meta: [
            'current_page' => $refunds->currentPage(),
            'per_page' => $refunds->perPage(),
            'total' => $refunds->total(),
            'last_page' => $refunds->lastPage(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'exists:orders,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'type' => ['required', 'in:full,partial'],
            'reason_code' => ['nullable', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // Batch 67 — the order must be in the operator's scope (D-52), and a refund can
        // never add up to more than the order cost: `amount` had a floor of 0.01 and no
        // ceiling, so one request could record a refund of any size against any order.
        $order = AdminScope::applyToOrders(Order::query(), $request->user())->findOrFail($validated['order_id']);

        $alreadyRefunded = (float) Refund::query()
            ->where('order_id', $order->id)
            ->whereIn('status', ['pending', 'processed'])
            ->sum('amount');

        if (round($alreadyRefunded + (float) $validated['amount'], 2) > round((float) $order->total, 2)) {
            throw new ApiException(
                'refund_exceeds_order',
                'قيمة الاسترداد تتجاوز قيمة الطلب',
                422,
                meta: ['order_total' => (float) $order->total, 'already_refunded' => $alreadyRefunded],
            );
        }

        $payment = $order->orderGroup->payment;

        if (! $payment) {
            throw new ApiException('validation_failed', 'لا يوجد دفع مرتبط بهذا الطلب', 422);
        }

        $refund = Refund::create([
            ...$validated,
            'payment_id' => $payment->id,
            'status' => 'pending',
        ]);

        AuditLogger::record($request->user(), 'refunds.create', $refund, $validated);

        return ApiResponse::success($refund, 'تم إنشاء طلب الاسترداد', meta: [
            // D-65 — what the policy would have refunded, so a figure that departs
            // from it is a decision somebody made rather than an accident.
            'policy_full_amount' => RefundPolicy::fullRefundAmount($order, $validated['reason_code'] ?? null),
            'platform_fee_refundable' => RefundPolicy::feeIsRefundable($order, $validated['reason_code'] ?? null),
            'platform_fee_share' => (float) $order->platform_fee_share,
        ], status: 201);
    }

    public function process(Request $request, Refund $refund): JsonResponse
    {
        AdminScope::abortUnlessVisible(AdminScope::applyToRefunds(Refund::query(), $request->user()), $refund);

        if ($refund->status->value !== 'pending') {
            throw new ApiException('invalid_transition', 'تمت معالجة هذا الاسترداد بالفعل', 422);
        }

        $validated = $request->validate([
            'method' => ['required', 'in:cash,vodafone_cash,instapay,wallet'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($request, $refund, $validated) {
            $refund->update([
                ...$validated,
                'status' => 'processed',
                'processed_by' => $request->user()->id,
                'processed_at' => now(),
            ]);

            $payment = $refund->payment;
            $newStatus = $refund->type->value === 'full' ? 'refunded' : 'partially_refunded';
            $payment->update(['status' => $newStatus]);

            AuditLogger::record($request->user(), 'refunds.process', $refund, $validated);
        });

        return ApiResponse::success($refund->fresh(), 'تم تنفيذ الاسترداد بنجاح');
    }
}
