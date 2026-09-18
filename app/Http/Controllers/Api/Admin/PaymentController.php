<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Http\Resources\Shared\PaymentProofResource;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\Lists\ListSearch;
use App\Support\RollupStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** doc 08 §7 — GET /admin/payments, POST /{id}/approve|reject. */
class PaymentController extends Controller
{
    use FiltersLists;

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
        $params = $this->listParams($request);
        $sort = $params->sort(['created_at', 'amount', 'deadline_at'], '-created_at');

        $query = AdminScope::applyToPayments(Payment::query(), $request->user())
            ->with(['orderGroup.user', 'proofs']);

        $this->whereInList($query, 'payments.status', $params->enum('status', array_column(PaymentStatus::cases(), 'value')));
        $this->whereInList($query, 'payments.method', $params->enum('method', ['cash', 'vodafone_cash', 'instapay']));

        $cities = $params->ids('city_id');
        $zones = $params->ids('zone_id');

        if ($cities !== [] || $zones !== []) {
            $query->whereHas('orderGroup', function ($group) use ($cities, $zones) {
                $this->whereInList($group, 'order_groups.city_id', $cities);
                $this->whereInList($group, 'order_groups.service_zone_id', $zones);
            });
        }

        if (($overdue = $params->bool('overdue')) !== null) {
            $late = fn ($q) => $q->whereIn('payments.status', ['pending', 'awaiting_proof'])
                ->whereNotNull('payments.deadline_at')
                ->where('payments.deadline_at', '<', now());
            $overdue ? $query->where($late) : $query->whereNot($late);
        }

        $this->whereBetweenDates($query, 'payments.created_at', $params->dates());

        ListSearch::apply($query, $params->search(), [
            'refs' => ['reference'],
            'relations' => [
                'orderGroup' => ['refs' => ['group_number']],
                'user' => ['names' => ['name'], 'phones' => ['phone']],
            ],
        ]);

        $payments = $sort->apply($query, [])->paginate($params->perPage());

        // `proofs` carries a private-disk path; the resource swaps it for a gated URL.
        $rows = collect($payments->items())->map(function (Payment $payment) {
            $row = $payment->toArray();
            $row['receipts'] = PaymentProofResource::forAdmin($payment->proofs);
            unset($row['proofs']);

            return $row;
        });

        return $this->paginated($payments, $rows, sort: $sort);
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
