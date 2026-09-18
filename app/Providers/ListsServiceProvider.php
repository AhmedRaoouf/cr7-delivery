<?php

namespace App\Providers;

use App\Http\Middleware\CachePublicResponse;
use App\Http\Middleware\ThrottlePerAccount;
use App\Models\Banner;
use App\Models\City;
use App\Models\Cuisine;
use App\Models\Faq;
use App\Models\HomePopup;
use App\Models\MenuCategory;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Models\Restaurant;
use App\Models\RestaurantOpeningHour;
use App\Models\ServiceZone;
use App\Models\Setting;
use App\Models\StaticPage;
use App\Models\StoreCategory;
use App\Support\Http\PublicCache;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * Batch 68 — «مهما كان عدد اليوزرز ميبقاش فيه ضغط علي السيرفر» (ADMIN-LISTS.md §5–§6).
 *
 * Two things live here rather than in `AppServiceProvider`, so the lists slice owns them
 * whole: the invalidation of the public read cache, and the aliases of its two
 * middlewares — `http.cache` ({@see CachePublicResponse}) and `throttle.account`
 * ({@see ThrottlePerAccount}).
 */
class ListsServiceProvider extends ServiceProvider
{
    /** Every model a public catalogue read is built from. */
    public const CATALOG_MODELS = [
        Restaurant::class, RestaurantOpeningHour::class, MenuCategory::class, Product::class,
        ProductOptionGroup::class, ProductOption::class, Cuisine::class, StoreCategory::class,
        Banner::class, HomePopup::class, City::class, ServiceZone::class, Faq::class, StaticPage::class,
    ];

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('http.cache', CachePublicResponse::class);

        $router->aliasMiddleware('throttle.account', ThrottlePerAccount::class);

        $this->invalidateOnWrite();
    }

    /**
     * A write to anything a stored response was built from retires every stored response
     * of its group ({@see PublicCache::bump()}). `saved` covers create, update and a soft
     * delete's restore; a counter moved with `increment()` (`orders_count`, `sold_count`)
     * fires no `saved` and deliberately bumps nothing — the next TTL picks it up, and an
     * order placed would otherwise retire the catalogue for every phone.
     *
     * A pivot change (a place's cuisines, its served zones) fires no model event either;
     * the TTL (60 s) is its bound.
     */
    private function invalidateOnWrite(): void
    {
        foreach (self::CATALOG_MODELS as $model) {
            $model::saved(static fn () => PublicCache::bump('catalog'));
            $model::deleted(static fn () => PublicCache::bump('catalog'));
        }

        // `/config` is built from settings, and so are the banner and popup audiences.
        Setting::saved(static fn () => PublicCache::bump('config', 'catalog'));
        Setting::deleted(static fn () => PublicCache::bump('config', 'catalog'));
    }
}
