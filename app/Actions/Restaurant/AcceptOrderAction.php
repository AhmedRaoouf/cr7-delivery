<?php

namespace App\Actions\Restaurant;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\ConfirmationGateService;
use Illuminate\Support\Facades\DB;

/**
 * doc 08 §6 — POST /restaurant/orders/{id}/accept. doc 04 §8-9 — the restaurant commits
 * to prep_minutes and (optionally now, required by start-preparing) its own POS ref.
 */
class AcceptOrderAction
{
    public function __construct(private readonly ConfirmationGateService $gate) {}

    public function execute(User $user, Order $order, int $prepMinutes, ?string $restaurantOrderRef): Order
    {
        if ($order->status !== OrderStatus::Pending && $order->status !== OrderStatus::RestaurantTimeout) {
            throw new ApiException('invalid_transition', 'لا يمكن قبول الطلب في حالته الحالية', 422);
        }

        // doc 99 D-172 — the place asked the customer about a change; it answers the order
        // once the customer has, or after withdrawing the change.
        if ($order->hasPendingAmendment()) {
            throw self::amendmentPending();
        }

        self::assertPrepMinutes($prepMinutes);

        return DB::transaction(fn () => $this->commit($user, $order, $prepMinutes, $restaurantOrderRef));
    }

    /**
     * The acceptance itself — the status, who, the prep minutes, the place's own number,
     * the history row, then the confirmation gate. Called inside a transaction, by
     * {@see execute()} and by an amendment the customer approved (doc 99 D-172), which
     * accepts the order «exactly as» the place's own button does.
     *
     * @param  array<string, mixed>  $history  extra columns for the history row (reason, meta)
     */
    public function commit(
        User $user,
        Order $order,
        int $prepMinutes,
        ?string $restaurantOrderRef,
        OrderStatusActorType $actorType = OrderStatusActorType::Restaurant,
        array $history = [],
        bool $customerApproved = false,
    ): Order {
        $from = $order->status;

        $order->update([
            'status' => OrderStatus::Accepted,
            'accepted_at' => now(),
            'accepted_by' => $user->id,
            'prep_minutes' => $prepMinutes,
            'restaurant_order_ref' => $restaurantOrderRef ?: $order->restaurant_order_ref,
        ]);

        $order->statusHistory()->create([
            'from_status' => $from->value,
            'to_status' => OrderStatus::Accepted->value,
            'actor_type' => $actorType,
            'actor_id' => $user->id,
            ...$history,
        ]);

        $this->gate->maybeOpen($order->orderGroup, $user, $customerApproved);

        return $order->fresh();
    }

    /** `restaurant.prep_minutes_min` … `max`, or `422 prep_minutes_out_of_range`. */
    public static function assertPrepMinutes(int $prepMinutes): void
    {
        $min = (int) (Setting::query()->where('key', 'restaurant.prep_minutes_min')->value('value') ?? 5);
        $max = (int) (Setting::query()->where('key', 'restaurant.prep_minutes_max')->value('value') ?? 120);

        if ($prepMinutes < $min || $prepMinutes > $max) {
            throw new ApiException('prep_minutes_out_of_range', "مدة التحضير يجب أن تكون بين {$min} و {$max} دقيقة", 422);
        }
    }

    public static function amendmentPending(): ApiException
    {
        return new ApiException(
            'amendment_pending',
            'التعديل المقترح بانتظار رد العميل — اسحبه أولًا لتقبل الطلب أو ترفضه كما هو',
            409,
        );
    }
}
