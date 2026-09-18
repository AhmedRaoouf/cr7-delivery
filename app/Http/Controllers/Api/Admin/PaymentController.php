<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Shared\PaymentProofResource;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\RollupStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** doc 08 §7 — GET /admin/payments, POST /{id}/approve|reject. */
class PaymentController extends Controller
{
    /** doc 12 §5 — "Payment proof image — Admin only": never a public URL, served through this gate. */
    public function proofFile(Request $request, PaymentProof $proof): StreamedResponse
    {
        // Batch 67 — the proof follows its payment's scope (doc 12 §10.1).
        abort_unless($proof->payment !== null, 404);
        $this->ensureInScope($request, $proof->payment);

        abort_unless(Storage::disk('local')->exists($proof->image), 404);

        return Storage::disk('local')->response($proof->image);
    }

    public function index(Request $request): JsonResponse
    {
        $payments = AdminScope::applyToPayments(Payment::query(), $request->user())
            ->with(['orderGroup.user', 'proofs'])
            ->when($request->string('filter.status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('filter.status')))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        // `proofs` carries a private-disk path; the resource swaps it for a gated URL.
        $rows = collect($payments->items())->map(function (Payment $payment) {
            $row = $payment->toArray();
            $row['receipts'] = PaymentProofResource::forAdmin($payment->proofs);
            unset($row['proofs']);

            return $row;
        });

        return ApiResponse::success($rows, meta: [
            'current_page' => $payments->currentPage(),
            'per_page' => $payments->perPage(),
            'total' => $payments->total(),
            'last_page' => $payments->lastPage(),
        ]);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        $this->ensureInScope($request, $payment);

        $payment->load(['orderGroup.orders', 'proofs.reviewedBy']);

        $row = $payment->toArray();
        $row['order_group'] = $payment->orderGroup;
        $row['receipts'] = PaymentProofResource::forAdmin($payment->proofs);
        unset($row['proofs']);

        return ApiResponse::success($row);
    }

    public function approve(Request $request, Payment $payment): JsonResponse
    {
        $this->ensureInScope($request, $payment);

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

            AuditLogger::record($request->user(), 'payments.verify', $payment, ['status' => 'paid']);
        });

        return ApiResponse::success($payment->fresh(), 'تم اعتماد الدفع بنجاح');
    }

    public function reject(Request $request, Payment $payment): JsonResponse
    {
        $this->ensureInScope($request, $payment);

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

            AuditLogger::record($request->user(), 'payments.verify', $payment, ['status' => 'rejected', 'reason' => $validated['reason']]);
        });

        return ApiResponse::success($payment->fresh(), 'تم رفض الإيصال، بانتظار إيصال جديد من العميل');
    }

    /** Batch 67 — D-52: out of scope is a 404, the same answer a missing row gets. */
    private function ensureInScope(Request $request, Payment $payment): void
    {
        AdminScope::abortUnlessVisible(AdminScope::applyToPayments(Payment::query(), $request->user()), $payment);
    }
}
