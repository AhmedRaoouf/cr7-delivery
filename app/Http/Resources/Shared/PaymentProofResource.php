<?php

namespace App\Http\Resources\Shared;

use App\Models\PaymentProof;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A transfer receipt — «الإيصال» — as every screen that shows one reads it.
 *
 * Operator, 2026-09-05: «في الأوردرات يبقى فيه حاجة اسمها الإيصالات … إيصال التحويل وكده،
 * وكذلك في الأدمين تبقى ظهراله كل حاجة».
 *
 * `image` is a path on the **private** disk (doc 12 §5) and never leaves this class.
 * What does leave is `file_url`, an API route that checks who is asking before it
 * streams the bytes. Putting the raw path in a payload would make every receipt
 * readable by anyone who guessed a filename, which is the whole reason the disk is
 * private in the first place.
 *
 * The route differs by audience, so the caller supplies it:
 * admin `admin/payment-proofs/{id}/file`, customer `orders/{group}/payment/proofs/{id}/file`.
 */
class PaymentProofResource extends JsonResource
{
    public function __construct($resource, private readonly string $fileUrl)
    {
        parent::__construct($resource);
    }

    /** @param  iterable<int, PaymentProof>  $proofs */
    public static function forAdmin(iterable $proofs): array
    {
        return collect($proofs)
            ->map(fn ($proof) => (new self($proof, "admin/payment-proofs/{$proof->id}/file"))->resolve())
            ->values()
            ->all();
    }

    /** @param  iterable<int, PaymentProof>  $proofs */
    public static function forCustomer(iterable $proofs, int $orderGroupId): array
    {
        return collect($proofs)
            ->map(fn ($proof) => (new self($proof, "orders/{$orderGroupId}/payment/proofs/{$proof->id}/file"))->resolve())
            ->values()
            ->all();
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_id' => $this->payment_id,
            'file_url' => $this->fileUrl,
            'reference' => $this->reference,
            'amount_claimed' => $this->amount_claimed !== null ? (float) $this->amount_claimed : null,
            'sender_number' => $this->sender_number,
            'status' => $this->status,
            // Why it was rejected. The customer needs this more than the admin does —
            // «إيصال غير واضح» is the difference between re-sending and giving up.
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at,
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy?->name),
            'created_at' => $this->created_at,
        ];
    }
}
