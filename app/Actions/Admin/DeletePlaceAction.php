<?php

namespace App\Actions\Admin;

use App\Enums\BannerLinkType;
use App\Enums\OrderStatus;
use App\Exceptions\ApiException;
use App\Models\Banner;
use App\Models\CartItem;
use App\Models\Favorite;
use App\Models\HomePopup;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Support\Auth\Sessions;
use Illuminate\Support\Facades\DB;

/**
 * Doc 91 — deleting a place takes everything that still points at it off the app.
 *
 * Before doc 91 the delete was a bare soft delete, and everything around the place stayed:
 * a basket holding one of its dishes answered 500 on every cart call, «طلباتي» answered 500
 * for anyone who had ever ordered from it, its dishes were still reachable from a favourite
 * or a banner, and its owner could still sign in to a board that 404'd on every screen.
 *
 * | What | After the delete |
 * |---|---|
 * | open orders | **refused** — `409 restaurant_has_open_orders`, nothing changes |
 * | past orders | untouched; `Order::restaurant()` reads a deleted place, so the name stays |
 * | dishes, sections | soft-deleted with the place |
 * | basket lines | deleted |
 * | favourites (place + its dishes) | deleted |
 * | banners / popups pointing at it or a dish of it | switched off |
 * | promo codes restricted to it | the id removed; a code left with no place is switched off |
 * | its pin on the home order | cleared |
 * | the owner account | switched off and signed out, unless it still owns a live place |
 *
 * `forget()` is the cleanup without the delete, so a place deleted before doc 91 can be
 * brought to the same state.
 */
class DeletePlaceAction
{
    public function execute(Restaurant $restaurant): void
    {
        DB::transaction(function () use ($restaurant) {
            $open = Order::query()
                ->where('restaurant_id', $restaurant->id)
                ->whereNotIn('status', [OrderStatus::Delivered->value, OrderStatus::Cancelled->value])
                ->lockForUpdate()
                ->count();

            if ($open > 0) {
                throw new ApiException(
                    'restaurant_has_open_orders',
                    "لا يمكن حذف المكان لأن لديه \u{202A}{$open}\u{202C} ".($open === 1 ? 'طلبًا مفتوحًا' : 'طلبات مفتوحة').'. أنهِ الطلبات أو ألغِها أولًا.',
                    409,
                    meta: ['open_orders' => $open],
                );
            }

            self::forget($restaurant);

            $restaurant->delete();
        });
    }

    public static function forget(Restaurant $restaurant): void
    {
        $productIds = Product::withTrashed()->where('restaurant_id', $restaurant->id)->pluck('id');

        CartItem::query()->where('restaurant_id', $restaurant->id)->delete();

        Favorite::query()
            ->where(fn ($q) => $q
                ->where(fn ($p) => $p->where('favoritable_type', 'restaurant')->where('favoritable_id', $restaurant->id))
                ->orWhere(fn ($p) => $p->where('favoritable_type', 'product')->whereIn('favoritable_id', $productIds)))
            ->delete();

        Banner::query()
            ->where(fn ($q) => $q
                ->where(fn ($b) => $b->where('link_type', BannerLinkType::Restaurant->value)->where('link_value', (string) $restaurant->id))
                ->orWhere(fn ($b) => $b->where('link_type', BannerLinkType::Product->value)->whereIn('link_value', $productIds->map(fn ($id) => (string) $id))))
            ->update(['is_active' => false]);

        HomePopup::query()
            ->where(fn ($q) => $q->where('restaurant_id', $restaurant->id)->orWhereIn('product_id', $productIds))
            ->update(['is_active' => false]);

        PromoCode::query()->whereJsonContains('restrict_restaurant_ids', $restaurant->id)
            ->orWhereJsonContains('restrict_restaurant_ids', (string) $restaurant->id)
            ->get()
            ->each(function (PromoCode $code) use ($restaurant) {
                $left = collect($code->restrict_restaurant_ids)
                    ->reject(fn ($id) => (int) $id === $restaurant->id)
                    ->values()
                    ->all();

                // An empty list means «every place». A code that was only ever for this
                // place must not wake up valid everywhere — it is switched off instead.
                $code->update($left === []
                    ? ['restrict_restaurant_ids' => [$restaurant->id], 'is_active' => false]
                    : ['restrict_restaurant_ids' => $left]);
            });

        // Query-level soft deletes: the place is going, so recomputing its price band per
        // dish (ProductObserver) is ninety-seven queries for a number nobody reads.
        Product::query()->where('restaurant_id', $restaurant->id)->update(['deleted_at' => now()]);
        MenuCategory::query()->where('restaurant_id', $restaurant->id)->update(['deleted_at' => now()]);

        $restaurant->forceFill(['pin_position' => null, 'is_active' => false])->saveQuietly();

        $owner = $restaurant->owner;
        $ownsAnotherPlace = $owner !== null && Restaurant::query()
            ->where('user_id', $owner->id)
            ->whereKeyNot($restaurant->id)
            ->exists();

        if ($owner !== null && ! $ownsAnotherPlace && $owner->is_active) {
            $owner->forceFill(['is_active' => false])->save();
            Sessions::revokeAll($owner);
        }
    }
}
