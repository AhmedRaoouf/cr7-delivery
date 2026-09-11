<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\OrderGroup;
use App\Models\Rating;
use Illuminate\Support\Collection;

/**
 * D-80 — what every customer-facing order payload says about rating.
 *
 * The app had `is_rated` in its model and **nothing ever set it** against the live API
 * (only the mock did), so «قيّم الطلب» appeared on every delivered receipt for ever and a
 * second submission silently overwrote the first. Three fields close that:
 *
 * - `is_rated`  — this basket has been rated;
 * - `can_rate`  — it is finished, and it has not been rated yet;
 * - `rating`    — what was said, so the receipt can show the stars back instead of the
 *                 button.
 *
 * One basket, one rating: the customer rates the place(s) and the captain together, which
 * is why this is computed per group and never per sub-order.
 */
class RatingState
{
    /** @return array{is_rated: bool, can_rate: bool, rating: array<string, mixed>|null} */
    public static function for(OrderGroup $group): array
    {
        $orders = $group->relationLoaded('orders')
            ? $group->orders
            : $group->orders()->get(['id', 'status', 'restaurant_id', 'driver_id']);

        $ratings = Rating::query()
            ->where('user_id', $group->user_id)
            ->whereIn('order_id', $orders->pluck('id'))
            ->get();

        $isRated = $ratings->isNotEmpty();

        $delivered = $orders->contains(fn ($order) => self::status($order) === OrderStatus::Delivered);
        $stillLive = $orders->contains(fn ($order) => ! in_array(
            self::status($order),
            [OrderStatus::Delivered, OrderStatus::Cancelled],
            true,
        ));

        return [
            'is_rated' => $isRated,
            // A basket with one kitchen still cooking is not over, and rating it would
            // spend the single rating on half an order.
            'can_rate' => $delivered && ! $stillLive && ! $isRated,
            'rating' => $isRated ? self::payload($ratings) : null,
        ];
    }

    /** @param Collection<int, Rating> $ratings */
    private static function payload($ratings): array
    {
        $driver = $ratings->firstWhere('rateable_type', 'driver');

        return [
            'rated_at' => $ratings->min('created_at'),
            'restaurants' => $ratings
                ->where('rateable_type', 'restaurant')
                ->map(fn (Rating $rating) => [
                    'restaurant_id' => (int) $rating->rateable_id,
                    'stars' => (int) $rating->stars,
                    'tags' => $rating->tags,
                    'comment' => $rating->comment,
                ])
                ->values(),
            'driver' => $driver === null ? null : [
                'driver_id' => (int) $driver->rateable_id,
                'stars' => (int) $driver->stars,
                'tags' => $driver->tags,
                'comment' => $driver->comment,
            ],
        ];
    }

    private static function status($order): ?OrderStatus
    {
        return $order->status instanceof OrderStatus
            ? $order->status
            : OrderStatus::tryFrom((string) $order->status);
    }
}
