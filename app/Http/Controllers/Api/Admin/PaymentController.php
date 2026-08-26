<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Support\ApiResponse;
use App\Support\RollupStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** doc 08 §7 — GET /admin/payments, POST /{id}/approve|reject. */
class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payments = Payment::query()
            ->with(['orderGroup.user', 'proofs'])
            ->when($request->string('filter.status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('filter.status')))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($payments->items(), meta: [
            'current_page' => $payments->currentPage(),
            'per_page' => $payments->perPage(),
            'total' => $payments->total(),
            'last_page' => $payments->lastPage(),
        ]);
    }

    public function show(Payment $payment): JsonResponse
    {
        return ApiResponse::success($payment->load(['orderGroup.orders', 'proofs.reviewedBy']));
    }

    public function approve(Request $request, Payment $payment): JsonResponse
    {
        if ($payment->status->value !== 'under_review') {
            throw new ApiException('invalid_transition', 'لا يوجد إيصال قيد المراجعة لهذا الدفع', 422);
        }

        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        DB::transaction(function () use ($request, $payment, $validated) {
            $payment->proofs()->where('status', 'pending')->update([
                'status' => 'approved',
                'reviewed_by' => $request->user()->id,
                'review_note' => $validated['note'] ?? null,
                'reviewed_at' => now(),
            ]);

            $payment->update(['status' => 'paid', 'paid_at' => now(), 'verified_by' => $request->user()->id, 'verified_at' => now()]);

            $group = $payment->orderGroup;

            foreach ($group->orders()->where('status', 'awaiting_payment')->get() as $order) {
                $order->update(['status' => 'confirmed', 'confirmed_at' => now()]);
                $order->statusHistory()->create([
                    'from_status' => 'awaiting_payment',
                    'to_status' => 'confirmed',
                    'actor_type' => 'admin',
                    'actor_id' => $request->user()->id,
                    'reason_code' => 'payment_verified',
                ]);
            }

            $group->update([
                'confirmed_at' => $group->confirmed_at ?? now(),
                'confirmed_by' => $group->confirmed_by ?? 'system',
                'rollup_status' => RollupStatus::compute($group->orders()->get()->pluck('status')),
            ]);

            \App\Support\AuditLogger::record($request->user(), 'payments.verify', $payment, ['status' => 'paid']);
        });

        return ApiResponse::success($payment->fresh(), 'تم اعتماد الدفع بنجاح');
    }

    public function reject(Request $request, Payment $payment): JsonResponse
    {
        if ($payment->status->value !== 'under_review') {
            throw new ApiException('invalid_transition', 'لا يوجد إيصال قيد المراجعة لهذا الدفع', 422);
        }

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        DB::transaction(function () use ($request, $payment, $validated) {
            $payment->proofs()->where('status', 'pending')->update([
                'status' => 'rejected',
                'reviewed_by' => $request->user()->id,
                'review_note' => $validated['reason'],
                'reviewed_at' => now(),
            ]);

            $payment->update(['status' => 'awaiting_proof', 'failure_reason' => $validated['reason']]);

            \App\Support\AuditLogger::record($request->user(), 'payments.verify', $payment, ['status' => 'rejected', 'reason' => $validated['reason']]);
        });

        return ApiResponse::success($payment->fresh(), 'تم رفض الإيصال، بانتظار إيصال جديد من العميل');
    }
}
