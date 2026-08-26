<?php

namespace App\Actions\Customer;

use App\Enums\OrderStatus;
use App\Exceptions\ApiException;
use App\Models\OrderGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** doc 08 §4 — POST /orders/{id}/ratings: one rating per restaurant in the group, any entry may be null. */
class RateOrderGroupAction
{
    public function execute(User $user, OrderGroup $group, array $data): void
    {
        abort_unless($group->user_id === $user->id, 404);

        DB::transaction(function () use ($user, $group, $data) {
            foreach ($data['restaurants'] ?? [] as $entry) {
                $order = $group->orders()->where('restaurant_id', $entry['restaurant_id'])->first();

                if (! $order || $order->status !== OrderStatus::Delivered) {
                    throw new ApiException('validation_failed', 'لا يمكن تقييم طلب لم يكتمل تسليمه بعد', 422);
                }

                $user->ratings()->updateOrCreate(
                    ['order_id' => $order->id, 'rateable_type' => 'restaurant', 'rateable_id' => $entry['restaurant_id']],
                    ['stars' => $entry['stars'], 'tags' => $entry['tags'] ?? null, 'comment' => $entry['comment'] ?? null],
                );

                $this->recomputeRatingAverage($order->restaurant, 'restaurant');
            }

            if (! empty($data['driver']) && ! empty($data['driver']['driver_id'])) {
                $order = $group->orders()->where('driver_id', $data['driver']['driver_id'])->first();

                if (! $order || $order->status !== OrderStatus::Delivered) {
                    throw new ApiException('validation_failed', 'لا يمكن تقييم كابتن لم يكمل التوصيل بعد', 422);
                }

                $user->ratings()->updateOrCreate(
                    ['order_id' => $order->id, 'rateable_type' => 'driver', 'rateable_id' => $data['driver']['driver_id']],
                    ['stars' => $data['driver']['stars'], 'tags' => $data['driver']['tags'] ?? null, 'comment' => $data['driver']['comment'] ?? null],
                );

                $this->recomputeRatingAverage($order->driver, 'driver');
            }
        });
    }

    private function recomputeRatingAverage($model, string $morphType): void
    {
        $stats = $model->ratings()->selectRaw('avg(stars) as avg, count(*) as cnt')->first();
        $model->update(['rating_avg' => round($stats->avg ?? 0, 2), 'rating_count' => $stats->cnt ?? 0]);
    }
}
