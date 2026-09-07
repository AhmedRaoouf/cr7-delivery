<?php

namespace Database\Seeders;

use App\Enums\UserType;
use App\Models\Banner;
use App\Models\City;
use App\Models\Cuisine;
use App\Models\MenuCategory;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Models\ServiceZone;
use App\Models\StoreCategory;
use App\Models\User;
use App\Support\PriceBand;
use App\Support\Settings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * doc 17 — the real Gamasa catalogue: 8 brands, 69 categories, 562 dishes, from the
 * menu photos and logo files the operator supplied.
 *
 * The data lives in `database/data/catalog.json`, generated from the same source the
 * Figma boards were built from, so a price on a screen and a price in the database
 * cannot disagree. The imagery ships in `public/catalog/` — committed, web-served,
 * and needing no `storage:link`.
 *
 * Idempotent: every write is a firstOrCreate/updateOrCreate keyed on something stable,
 * so re-running it on a live database refreshes the menu instead of duplicating it.
 *
 * `name_en` is the brand's own Latin name where one is printed, and the Arabic string
 * verbatim for a dish with no published English name — an invented transliteration on
 * 400 dishes would be worse than showing the name the restaurant actually uses.
 */
class CatalogSeeder extends Seeder
{
    /**
     * Slides this seeder shipped and has since replaced.
     *
     * Named rather than inferred: «anything not in the current list» would sweep away
     * the operator's own banners on the next `db:seed`.
     */
    private const RETIRED_BANNERS = ['توصيل مجاني من مطاعم جمصة'];

    public function run(): void
    {
        $path = database_path('data/catalog.json');

        if (! is_file($path)) {
            $this->command?->error("catalog.json not found at {$path}");

            return;
        }

        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $city = City::query()->where('name_en', 'Gamasa')->first();

        if (! $city) {
            $this->command?->error('LocationSeeder has not run — no Gamasa city row.');

            return;
        }

        $zones = ServiceZone::query()->where('city_id', $city->id)->get()->keyBy('name_en');
        $cuisines = $this->seedCuisines($data['cuisines']);
        $storeCategories = $this->seedStoreCategories($data['store_categories'] ?? []);

        foreach ($data['restaurants'] as $row) {
            $this->seedRestaurant($row, $city, $zones, $cuisines, $storeCategories);
        }

        $this->seedBanners($data['banners'], $city);
        $this->seedPromoCodes($city);

        // D-60 — the band is the menu's own median, so it is computed after the menu
        // exists rather than declared in the seed file.
        Restaurant::query()->each(fn (Restaurant $restaurant) => PriceBand::recompute($restaurant));

        $this->command?->info(sprintf(
            'Seeded %d restaurants, %d categories, %d products.',
            Restaurant::query()->count(),
            MenuCategory::query()->count(),
            Product::query()->count(),
        ));
    }

    /** @return array<string, int> name_ar => cuisines.id */
    private function seedCuisines(array $rows): array
    {
        $map = [];

        foreach ($rows as $row) {
            $cuisine = Cuisine::query()->updateOrCreate(
                ['name_ar' => $row['name_ar']],
                [
                    'name_en' => $row['name_en'],
                    'icon' => $row['icon'],
                    'sort_order' => $row['sort_order'],
                    'is_active' => true,
                ],
            );

            $map[$row['name_ar']] = $cuisine->id;
        }

        return $map;
    }

    /**
     * The home screen's top strip — «مطاعم / صيدليات / كافيهات / سوبرماركت / خضروات».
     *
     * `updateOrCreate` on the slug, and the artwork is refreshed on every run: the
     * operator can rename a category or swap its picture from the dashboard, and this
     * must not undo that — but a fresh database has to come up with the cards drawn.
     *
     * @return array<string, int> slug => store_categories.id
     */
    private function seedStoreCategories(array $rows): array
    {
        $map = [];

        foreach ($rows as $row) {
            $category = StoreCategory::query()->updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'name_ar' => $row['name_ar'],
                    'name_en' => $row['name_en'],
                    'image' => $this->url($row['image'] ?? null),
                    'icon' => $this->url($row['icon'] ?? null),
                    'color' => $row['color'] ?? null,
                    'sort_order' => $row['sort_order'] ?? 0,
                    'is_active' => true,
                ],
            );

            $map[$row['slug']] = $category->id;
        }

        return $map;
    }

    private function seedRestaurant(array $row, City $city, $zones, array $cuisines, array $storeCategories = []): void
    {
        $zone = $zones[$row['zone_en']] ?? $zones->first();

        // The dashboard login. The temporary password is the same for every seeded
        // owner and `must_change_password` is on, so nobody can leave it in place.
        $owner = User::query()->firstOrCreate(
            ['phone' => $row['owner_phone']],
            [
                'name' => $row['name_ar'],
                'password' => Hash::make(env('SEED_RESTAURANT_PASSWORD', 'Cr7Delivery#2026')),
                'type' => UserType::Restaurant,
                'locale' => 'ar',
                'theme' => 'dark',
                'is_active' => true,
                'must_change_password' => true,
                'phone_verified_at' => now(),
            ],
        );

        $restaurant = Restaurant::query()->updateOrCreate(
            ['slug' => $row['slug']],
            [
                'user_id' => $owner->id,
                'city_id' => $city->id,
                'service_zone_id' => $zone->id,
                'store_category_id' => $storeCategories[$row['store_category'] ?? ''] ?? null,
                'name_ar' => $row['name_ar'],
                'name_en' => $row['name_en'],
                'tagline_ar' => $row['tagline_ar'],
                'tagline_en' => $row['tagline_en'],
                'description_ar' => $row['description_ar'],
                'description_en' => $row['description_en'],
                'logo' => $this->url($row['logo']),
                'cover' => $this->url($row['cover']),
                'menu_sheets' => array_map(fn ($sheet) => $this->url($sheet), $row['menu_sheets']),
                'brand_color' => $row['brand_color'],
                'phone' => $row['phone'],
                'whatsapp' => $row['whatsapp'],
                'address' => $row['address'],
                'latitude' => $row['latitude'],
                'longitude' => $row['longitude'],
                'commission_rate' => (float) Settings::get('commission.default_rate', 10.00),
                'min_order_value' => $row['min_order_value'],
                'avg_prep_minutes' => $row['avg_prep_minutes'],
                'rating_avg' => $row['rating_avg'],
                'rating_count' => $row['rating_count'],
                'is_open_manual' => true,
                'is_active' => true,
                'is_verified' => true,
                'is_featured' => $row['is_featured'],
                'direct_orders_enabled' => false,
                'menu_layout' => $row['menu_layout'],
                'sort_order' => $row['sort_order'],
            ],
        );

        $restaurant->cuisines()->sync(array_values(array_filter(
            array_map(fn ($name) => $cuisines[$name] ?? null, $row['cuisines']),
        )));

        $restaurant->servedZones()->syncWithoutDetaching($zones->pluck('id')->all());

        // 10:00 to 02:00 the next morning, every day — the window a Gamasa kitchen
        // actually works. `isOpenNow()` reads a shift that closes before it opens as
        // one window that crosses midnight.
        foreach (range(0, 6) as $weekday) {
            $restaurant->openingHours()->updateOrCreate(
                ['weekday' => $weekday],
                ['opens_at' => '10:00:00', 'closes_at' => '02:00:00', 'is_closed' => false],
            );
        }

        foreach ($row['categories'] as $categoryRow) {
            $category = $restaurant->menuCategories()->updateOrCreate(
                ['name_ar' => $categoryRow['name_ar']],
                [
                    'name_en' => $categoryRow['name_en'],
                    'sort_order' => $categoryRow['sort_order'],
                    'is_active' => true,
                ],
            );

            foreach ($categoryRow['products'] as $productRow) {
                $this->seedProduct($restaurant, $category, $productRow);
            }
        }
    }

    private function seedProduct(Restaurant $restaurant, MenuCategory $category, array $row): void
    {
        $product = Product::query()->updateOrCreate(
            [
                'restaurant_id' => $restaurant->id,
                'menu_category_id' => $category->id,
                'name_ar' => $row['name_ar'],
            ],
            [
                'name_en' => $row['name_en'],
                'description_ar' => $row['description_ar'],
                'description_en' => $row['description_en'],
                'image' => $this->url($row['image']),
                'price' => $row['price'],
                'prep_minutes' => 10,
                'tags' => $row['tags'],
                'is_available' => $row['is_available'],
                'is_featured' => $row['is_featured'],
                'sort_order' => $row['sort_order'],
            ],
        );

        foreach ($row['option_groups'] as $groupRow) {
            $group = $product->optionGroups()->updateOrCreate(
                ['name_ar' => $groupRow['name_ar']],
                [
                    'name_en' => $groupRow['name_en'],
                    'selection_type' => $groupRow['selection_type'],
                    'is_required' => $groupRow['is_required'],
                    'min_select' => $groupRow['min_select'],
                    'max_select' => $groupRow['max_select'],
                    'sort_order' => $groupRow['sort_order'],
                ],
            );

            foreach ($groupRow['options'] as $optionRow) {
                $group->options()->updateOrCreate(
                    ['name_ar' => $optionRow['name_ar']],
                    [
                        'name_en' => $optionRow['name_en'],
                        'price_delta' => $optionRow['price_delta'],
                        'is_default' => $optionRow['is_default'],
                        'is_available' => $optionRow['is_available'],
                        'sort_order' => $optionRow['sort_order'],
                    ],
                );
            }
        }
    }

    private function seedBanners(array $rows, City $city): void
    {
        // A banner the seed no longer lists is retired rather than left running. Keyed on
        // the title, so re-seeding after an offer is renamed does not leave the old slide
        // in the carousel next to its replacement — which is what happened to «توصيل
        // مجاني من مطاعم جمصة».
        //
        // Deactivated, not deleted: an operator may have added slides of their own from
        // `ADM-32`, and a seeder must never remove those.
        Banner::query()
            ->whereIn('title_ar', array_column($rows, 'title_ar'))
            ->orWhereIn('title_ar', self::RETIRED_BANNERS)
            ->update(['is_active' => true]);

        Banner::query()
            ->whereIn('title_ar', self::RETIRED_BANNERS)
            ->update(['is_active' => false]);

        foreach ($rows as $row) {
            Banner::query()->updateOrCreate(
                ['title_ar' => $row['title_ar']],
                [
                    'title_en' => $row['title_en'],
                    'image_ar' => $this->url($row['image']),
                    'image_en' => $this->url($row['image']),
                    'link_type' => $row['link_type'],
                    'link_value' => $this->resolveBannerLink($row),
                    'city_ids' => [$city->id],
                    'sort_order' => $row['sort_order'],
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * A banner pointing at a row has to carry that row's real id, not doc 17's code.
     *
     * `product` is `<restaurant code>:<dish name>` in the seed file, because the dish id
     * is assigned at insert and the seed cannot know it. Resolved to the id here, so a
     * banner that says «خصم على البرجر» opens the burger.
     */
    private function resolveBannerLink(array $row): ?string
    {
        if ($row['link_type'] === 'product') {
            [$code, $name] = array_pad(explode(':', (string) $row['link_value'], 2), 2, null);
            $restaurantId = $this->restaurantIdFor($code);

            if ($restaurantId === null || $name === null) {
                return null;
            }

            return (string) Product::query()
                ->where('restaurant_id', $restaurantId)
                ->where('name_ar', $name)
                ->value('id');
        }

        if ($row['link_type'] !== 'restaurant') {
            return $row['link_value'];
        }

        return $this->restaurantIdFor($row['link_value']);
    }

    private function restaurantIdFor(?string $code): ?string
    {
        $slug = match ($code) {
            'r-01' => 'sandwich-omar', 'r-02' => 'alrokn-elaraby', 'r-03' => 'ebn-elbalad',
            'r-04' => 'saad-eldomiaty', 'r-05' => 'king-broast', 'r-06' => 'boxs',
            'r-07' => 'nasser-alqias', 'r-08' => 'sawany-eldomiaty',
            'r-16' => 'tag-hyper', 'r-17' => 'haytham-market', 'r-18' => 'amr-pharmacy',
            default => null,
        };

        $id = $slug === null ? null : Restaurant::query()->where('slug', $slug)->value('id');

        return $id === null ? null : (string) $id;
    }

    /** doc 22 §6 — the promo of record, and the only one any screen quotes. */
    private function seedPromoCodes(City $city): void
    {
        PromoCode::query()->updateOrCreate(
            ['code' => 'WELCOME20'],
            [
                'title_ar' => 'خصم 20% على أول طلب',
                'title_en' => '20% off your first order',
                'type' => 'percent',
                'value' => 20,
                'max_discount' => 15,
                'min_order_value' => 50,
                'usage_limit_per_user' => 1,
                'first_order_only' => true,
                'restrict_city_ids' => [$city->id],
                'cost_bearer' => 'platform',
                'is_active' => true,
            ],
        );

        PromoCode::query()->updateOrCreate(
            ['code' => 'FREEDEL'],
            [
                'title_ar' => 'توصيل مجاني',
                'title_en' => 'Free delivery',
                'type' => 'free_delivery',
                'value' => 0,
                'min_order_value' => 100,
                'usage_limit_per_user' => 3,
                'restrict_city_ids' => [$city->id],
                'cost_bearer' => 'platform',
                'is_active' => true,
            ],
        );
    }

    /**
     * `public/catalog/...` served straight off the web root, so the images work the
     * moment the repo is deployed — no `storage:link`, no upload step. The absolute
     * URL is built from APP_URL at seed time, exactly like FileUploader does.
     */
    private function url(?string $path): ?string
    {
        return $path === null ? null : asset($path);
    }
}
