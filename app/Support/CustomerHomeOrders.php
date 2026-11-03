<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\User;
use App\Support\Catalog\RatingBadge;
use App\Support\Media\MediaLibrary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * doc 99 §6–§7 — the two cards the new customer home (`CUS-10`) draws from past orders:
 * the rating that is due, and «اطلب مرة أخرى».
 */
final class CustomerHomeOrders
{
    /** How far back a delivered basket is still asked about. */
    public const RATING_DUE_DAYS = 7;

    /** How many places the reorder rail shows. */
    public const REORDER_LIMIT = 6;

    /** How many recent baskets are read to find them (a customer who always orders at one place still fills the rail with what there is). */
    private const REORDER_SCAN = 60;

    /**
     * The newest basket delivered in the last 7 days that may still be rated and whose
     * prompt was not put off. A basket with a kitchen still cooking is not due (one
     * rating per basket, D-80), a cancelled one has nothing to rate.
     */
    public static function ratingDue(User $customer): ?OrderGroup
    {
        $since = now()->subDays(self::RATING_DUE_DAYS);

        $candidates = OrderGroup::query()
            ->where('order_groups.user_id', $customer->id)
            ->whereNull('order_groups.rating_prompt_dismissed_at')
            ->whereHas('orders', fn (Builder $q) => $q
                ->where('status', 'delivered')
                ->whereRaw('coalesce(orders.delivered_at, orders.updated_at) >= ?', [$since]))
            ->whereDoesntHave('orders', fn (Builder $q) => $q->whereNotIn('status', ['delivered', 'cancelled']))
            ->whereNotExists(fn (QueryBuilder $q) => $q
                ->selectRaw('1')
                ->from('ratings')
                ->join('orders as rated', 'rated.id', '=', 'ratings.order_id')
                ->whereColumn('rated.order_group_id', 'order_groups.id')
                ->whereColumn('ratings.user_id', 'order_groups.user_id'))
            ->select('order_groups.*')
            ->addSelect(['last_delivered_at' => self::lastDelivered()])
            ->orderByDesc('last_delivered_at')
            ->orderByDesc('order_groups.id')
            ->with(['orders.restaurant', 'orders.driver.user'])
            ->limit(5)
            ->get();

        // The query and `RatingState` are the same rule; the second is the receipt's own
        // answer, so the card can never offer what the receipt would refuse.
        return $candidates->first(fn (OrderGroup $group) => RatingState::for($group)['can_rate']);
    }

    /** @return array<string, mixed> */
    public static function ratingDuePayload(OrderGroup $group, string $locale): array
    {
        $delivered = $group->orders->filter(fn (Order $order) => $order->status->value === 'delivered');

        return [
            'id' => $group->id,
            'group_number' => $group->group_number,
            'delivered_at' => $group->last_delivered_at === null ? null : Carbon::parse($group->last_delivered_at),
            'total' => (float) $group->total,
            'places' => $delivered->map(fn (Order $order) => [
                'order_id' => $order->id,
                'id' => $order->restaurant?->id,
                'name' => $order->restaurant?->{"name_{$locale}"} ?: $order->restaurant?->name_ar,
                'logo' => $order->restaurant?->logo,
                'rating_badge' => RatingBadge::for($order->restaurant),
            ])->values(),
            // Every captain who carried a part of it — `drivers[]` on the rating write.
            'captains' => $delivered
                ->filter(fn (Order $order) => $order->driver !== null)
                ->unique('driver_id')
                ->map(fn (Order $order) => [
                    'driver_id' => $order->driver->id,
                    'name' => $order->driver->user?->name,
                    'avatar' => $order->driver->user?->avatar,
                ])->values(),
            ...RatingState::for($group),
        ];
    }

    /**
     * «اطلب مرة أخرى»: the customer's last baskets that reached them, one per place (the
     * basket's first place), newest first, at most six — and only places that are still on
     * the app. Reordering itself stays `POST /orders/{group}/reorder`.
     *
     * @return list<array<string, mixed>>
     */
    public static function reorderSuggestions(User $customer, string $locale): array
    {
        $groups = OrderGroup::query()
            ->where('order_groups.user_id', $customer->id)
            ->whereHas('orders', fn (Builder $q) => $q->where('status', 'delivered'))
            ->whereDoesntHave('orders', fn (Builder $q) => $q->whereNotIn('status', ['delivered', 'cancelled']))
            ->select('order_groups.*')
            ->addSelect(['last_delivered_at' => self::lastDelivered()])
            ->orderByDesc('last_delivered_at')
            ->orderByDesc('order_groups.id')
            ->with([
                'orders' => fn ($q) => $q->where('status', 'delivered')->orderBy('group_sequence')->orderBy('id'),
                'orders.restaurant',
                'orders.items',
            ])
            ->limit(self::REORDER_SCAN)
            ->get();

        $seen = [];
        $rows = [];

        foreach ($groups as $group) {
            /** @var Order|null $first */
            $first = $group->orders->first();
            $place = $first?->restaurant;

            if ($first === null || $place === null || $place->trashed() || ! $place->is_active || isset($seen[$place->id])) {
                continue;
            }

            $seen[$place->id] = true;
            $headline = $first->items->sortByDesc(fn ($item) => (float) $item->line_total)->first();

            $rows[] = [
                'group_id' => $group->id,
                'delivered_at' => $group->last_delivered_at === null ? null : Carbon::parse($group->last_delivered_at),
                'total' => (float) $group->total,
                'items_count' => (int) $group->orders->sum(fn (Order $order) => $order->items->sum('quantity')),
                'restaurant' => [
                    'id' => $place->id,
                    'name' => $place->{"name_{$locale}"} ?: $place->name_ar,
                    'logo' => $place->logo,
                    'rating_badge' => RatingBadge::for($place),
                ],
                'headline_item' => $headline === null ? null : [
                    'product_id' => $headline->product_id,
                    'name' => $locale === 'en'
                        ? ($headline->product_name_en_snapshot ?: $headline->product_name_ar_snapshot)
                        : $headline->product_name_ar_snapshot,
                    'image' => MediaLibrary::orPlaceholder($headline->product_image_snapshot),
                ],
            ];

            if (count($rows) === self::REORDER_LIMIT) {
                break;
            }
        }

        return $rows;
    }

    /** When the basket's last delivered part reached the customer. */
    private static function lastDelivered(): Builder
    {
        return Order::query()
            ->selectRaw('max(coalesce(orders.delivered_at, orders.updated_at))')
            ->whereColumn('orders.order_group_id', 'order_groups.id')
            ->where('orders.status', 'delivered');
    }
}
