<?php

namespace App\Actions\Customer;

use App\Exceptions\ApiException;
use App\Models\Cart;
use App\Models\ScheduledOrder;
use App\Models\User;
use App\Support\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The minute a scheduled order becomes an order.
 *
 * Called by `cr7:tick`. Everything it does goes through the real `CreateOrderAction`:
 * same pricing, same snapshots, same nineteen states, same dispatch. That is the whole
 * reason a scheduled order was never stored as an `orders` row in a twentieth state —
 * there is no second code path to keep in step with the first.
 *
 * The basket is rebuilt into a **scratch cart** (`carts.is_scratch`), not the customer's
 * live one. A release at 18:00 must not consume whatever they happen to be shopping for
 * at 18:00.
 *
 * A failure is a state, not an exception that stops the sweep: a restaurant that closed,
 * a product that was deleted, a promo that expired. The row goes to `failed` with the
 * reason on it, the customer is told, and the next row is tried.
 */
class ReleaseScheduledOrdersAction
{
    public function __construct(
        private readonly CreateOrderAction $createOrder,
        private readonly NotificationDispatcher $notifications,
    ) {}

    /** @return array{released: int, failed: int} */
    public function releaseDue(int $limit = 50): array
    {
        $due = ScheduledOrder::query()
            ->where('status', ScheduledOrder::STATUS_SCHEDULED)
            ->where('scheduled_for', '<=', now())
            // A row whose moment passed hours ago while the scheduler was down is not
            // released into a kitchen that has since closed — it is failed, and the
            // customer is told, which is the honest outcome.
            ->orderBy('scheduled_for')
            ->limit($limit)
            ->get();

        $released = 0;
        $failed = 0;

        foreach ($due as $row) {
            if ($row->scheduled_for->lt(now()->subHours(2))) {
                $this->fail($row, 'schedule_stale', 'مرّ موعد الطلب المجدول دون تنفيذه');
                $failed++;

                continue;
            }

            try {
                $this->release($row);
                $released++;
            } catch (ApiException $e) {
                $this->fail($row, $e->errorCode, $e->getMessage());
                $failed++;
            } catch (Throwable $e) {
                Log::error('scheduled order release failed', [
                    'scheduled_order_id' => $row->id,
                    'message' => $e->getMessage(),
                ]);
                $this->fail($row, 'release_failed', 'تعذّر تنفيذ الطلب المجدول');
                $failed++;
            }
        }

        return ['released' => $released, 'failed' => $failed];
    }

    public function release(ScheduledOrder $row): ScheduledOrder
    {
        $user = $row->customer;

        if ($user === null || ! $user->is_active) {
            throw new ApiException('account_suspended', 'الحساب غير نشط', 422);
        }

        $cart = $this->buildScratchCart($user, $row);

        try {
            $group = $this->createOrder->execute(
                $user,
                $row->payload,
                // Deterministic, so a sweep that runs twice on a row whose first attempt
                // committed but whose status write did not cannot place the order twice.
                'sch-'.$row->id,
                $cart,
            );
        } finally {
            // Force, not soft: a soft-deleted scratch cart still owns its items and
            // still carries the customer's id, and there is nothing to recover in it.
            $cart->items()->delete();
            $cart->forceDelete();
        }

        $row->update([
            'status' => ScheduledOrder::STATUS_RELEASED,
            'order_group_id' => $group->id,
            'released_at' => now(),
            'failure_code' => null,
            'failure_message' => null,
        ]);

        $this->notifications->send($user, 'order.scheduled_released', [
            'order_number' => $group->group_number,
        ], deepLink: 'cr7://orders/'.$group->id);

        return $row->fresh();
    }

    private function buildScratchCart(User $user, ScheduledOrder $row): Cart
    {
        return DB::transaction(function () use ($user, $row): Cart {
            $cart = Cart::create([
                'user_id' => $user->id,
                'is_scratch' => true,
                'address_id' => $row->address_id,
                'promo_code' => $row->payload['promo_code'] ?? null,
            ]);

            foreach ($row->payload['items'] ?? [] as $line) {
                $item = $cart->items()->create([
                    'restaurant_id' => $line['restaurant_id'],
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                    'note' => $line['note'] ?? null,
                ]);

                foreach ($line['option_ids'] ?? [] as $optionId) {
                    $item->options()->create(['product_option_id' => $optionId]);
                }
            }

            return $cart;
        });
    }

    private function fail(ScheduledOrder $row, string $code, string $message): void
    {
        $row->update([
            'status' => ScheduledOrder::STATUS_FAILED,
            'failure_code' => $code,
            'failure_message' => $message,
        ]);

        if ($row->customer !== null) {
            $this->notifications->send($row->customer, 'order.scheduled_failed', [
                'reference' => $row->reference,
                'reason' => $message,
            ]);
        }
    }
}
