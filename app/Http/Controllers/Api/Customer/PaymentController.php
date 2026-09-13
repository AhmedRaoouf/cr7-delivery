<?php

namespace App\Http\Controllers\Api\Customer;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\SubmitPaymentProofRequest;
use App\Http\Resources\Shared\PaymentProofResource;
use App\Models\OrderGroup;
use App\Models\PaymentProof;
use App\Models\Setting;
use App\Support\ApiResponse;
use App\Support\FileUploader;
use App\Support\Notifications\OperatorAlerts;
use App\Support\RollupStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** doc 08 §4 — payments (customer side). doc 12 §5 — the proof image is "Admin only", stored on the private disk. */
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
            'wallet_number' => Setting::query()->where('key', 'payment.vodafone_cash_number')->value('value'),
            'instapay_handle' => Setting::query()->where('key', 'payment.instapay_handle')->value('value'),
            'amount' => $payment->amount !== null ? (float) $payment->amount : null,
            'opened_at' => $payment->gate_opened_at,
            'deadline_at' => $payment->deadline_at,
            'seconds_remaining' => $payment->deadline_at ? max(0, now()->diffInSeconds($payment->deadline_at, false)) : null,
            // Every receipt sent for this order, newest first, with the reviewer's note
            // on any that were rejected. The customer sending a second receipt without
            // being told what was wrong with the first is how a payment stalls.
            'receipts' => PaymentProofResource::forCustomer(
                $payment->proofs()->latest()->get(),
                $orderGroup->id,
            ),
        ]);
    }

    /**
     * The customer's own receipt image. Same private disk as the admin's gate, a
     * different door: this one checks that the order group belongs to the caller **and**
     * that the receipt belongs to that group's payment — either check alone would let a
     * signed-in customer read someone else's transfer by id.
     */
    public function proofFile(Request $request, OrderGroup $orderGroup, PaymentProof $proof): StreamedResponse
    {
        $this->ensureOwned($request, $orderGroup);

        abort_unless($orderGroup->payment && $proof->payment_id === $orderGroup->payment->id, 404);
        abort_unless(Storage::disk('local')->exists($proof->image), 404);

        return Storage::disk('local')->response($proof->image);
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

        $data = $request->validated();
        $data['image'] = FileUploader::storePrivate($request->file('image'), 'payment-proofs');

        $proof = DB::transaction(function () use ($data, $payment) {
            $proof = $payment->proofs()->create($data);
            $payment->update(['status' => 'under_review']);

            return $proof;
        });

        // doc 11 §4 `alert.payment_review` — a receipt on a fifteen-minute clock waited for
        // somebody to open the payments tab. The operators in scope are told now.
        $first = $orderGroup->orders()->with('restaurant')->orderBy('group_sequence')->first();

        if ($first !== null) {
            OperatorAlerts::paymentProofUploaded($first, (float) $payment->amount, (string) $payment->method->value);
        }

        return ApiResponse::success(
            PaymentProofResource::forCustomer([$proof], $orderGroup->id)[0],
            'تم إرسال إيصال الدفع وجارٍ المراجعة',
            status: 201,
        );
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
