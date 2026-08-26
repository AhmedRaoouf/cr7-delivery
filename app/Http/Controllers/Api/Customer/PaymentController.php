<?php

namespace App\Http\Controllers\Api\Customer;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\SubmitPaymentProofRequest;
use App\Models\OrderGroup;
use App\Support\ApiResponse;
use App\Support\RollupStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** doc 08 §4 — payments (customer side). */
class PaymentController extends Controller
{
    public function show(Request $request, OrderGroup $orderGroup): JsonResponse
    {
        $this->ensureOwned($request, $orderGroup);

        $payment = $orderGroup->payment;

        if (! $payment || ! $payment->gate_opened_at) {
            return ApiResponse::success(['gate_open' => false, 'rollup_status' => $orderGroup->rollup_status]);
        }

        return ApiResponse::success([
            'gate_open' => true,
            'method' => $payment->method,
            'status' => $payment->status,
            'wallet_number' => \App\Models\Setting::query()->where('key', 'payment.vodafone_cash_number')->value('value'),
            'instapay_handle' => \App\Models\Setting::query()->where('key', 'payment.instapay_handle')->value('value'),
            'amount' => $payment->amount !== null ? (float) $payment->amount : null,
            'opened_at' => $payment->gate_opened_at,
            'deadline_at' => $payment->deadline_at,
            'seconds_remaining' => $payment->deadline_at ? max(0, now()->diffInSeconds($payment->deadline_at, false)) : null,
        ]);
    }

    public function submitProof(SubmitPaymentProofRequest $request, OrderGroup $orderGroup): JsonResponse
    {
        $this->ensureOwned($request, $orderGroup);

        $payment = $orderGroup->payment;

        if (! $payment || ! $payment->gate_opened_at) {
            throw new ApiException('payment_gate_closed', 'باب الدفع غير مفتوح بعد', 422);
        }

        if ($payment->deadline_at && $payment->deadline_at->isPast()) {
            throw new ApiException('payment_deadline_passed', 'انتهت مهلة الدفع', 422);
        }

        $proof = DB::transaction(function () use ($request, $payment) {
            $proof = $payment->proofs()->create($request->validated());
            $payment->update(['status' => 'under_review']);

            return $proof;
        });

        return ApiResponse::success($proof, 'تم إرسال إيصال الدفع وجارٍ المراجعة', status: 201);
    }

    public function switchToCash(Request $request, OrderGroup $orderGroup): JsonResponse
    {
        $this->ensureOwned($request, $orderGroup);

        $payment = $orderGroup->payment;

        if (! $payment || $payment->status->value !== 'awaiting_proof') {
            throw new ApiException('payment_gate_closed', 'لا يمكن تغيير طريقة الدفع الآن', 422);
        }

        DB::transaction(function () use ($orderGroup, $payment) {
            $payment->update(['method' => 'cash', 'status' => 'pending', 'gate_opened_at' => null, 'deadline_at' => null, 'amount' => null]);
            $orderGroup->update(['payment_method' => 'cash']);

            foreach ($orderGroup->orders()->where('status', 'awaiting_payment')->get() as $order) {
                $order->update(['status' => 'confirmed', 'payment_method' => 'cash', 'confirmed_at' => now()]);
                $order->statusHistory()->create([
                    'from_status' => 'awaiting_payment',
                    'to_status' => 'confirmed',
                    'actor_type' => 'customer',
                    'actor_id' => $orderGroup->user_id,
                    'reason_code' => 'switched_to_cash',
                ]);
            }

            $orderGroup->update([
                'confirmed_at' => now(),
                'confirmed_by' => 'customer',
                'rollup_status' => RollupStatus::compute($orderGroup->orders()->get()->pluck('status')),
            ]);
        });

        return ApiResponse::success($orderGroup->fresh(['orders', 'payment']), 'تم التحويل للدفع نقدًا بنجاح');
    }

    private function ensureOwned(Request $request, OrderGroup $orderGroup): void
    {
        abort_unless($orderGroup->user_id === $request->user()->id, 404);
    }
}
