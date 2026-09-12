<?php

namespace Database\Seeders;

use App\Enums\CityPricingType;
use App\Models\City;
use App\Models\ServiceZone;
use Illuminate\Database\Seeder;

/**
 * The four places this platform delivers in, and what each one charges.
 *
 * Operator, 2026-09-05: «النطاق اللي هي بيبقى المنصورة الجديدة، الشيخ زايد، خط جمصة،
 * وخمستاشر مايو برضه — هما منفصلين». Four separate cities, not one radius: 15 May and
 * Sheikh Zayed are Greater Cairo, Gamasa and New Mansoura are the Dakahlia coast, and
 * nobody rides between them. Each is its own city row with its own circle, and the fee
 * is the fee **inside** that city.
 *
 * The ordering is the operator's, given in that message and restated twice:
 *
 *     جمصة  <  15 مايو  <  الشيخ زايد  <  المنصورة الجديدة
 *      15        20           25              30
 *
 * It is not a distance calculation and must not be re-derived as one.
 *
 * ## Coordinates
 *
 * Every centre below except one came back from this deployment's own geocoder
 * (`GET /geo/search`) on 2026-09-05, so they are the same points the app's map resolves:
 *
 * | Place | Geocoder said |
 * |---|---|
 * | جمصة، محافظة الدقهلية | 31.441116, 31.538048 |
 * | مدينة الشيخ زايد، محافظة الجيزة | 30.045607, 30.978735 |
 * | مدينة 15 مايو، محافظة القاهرة | 29.826534, 31.364231 |
 *
 * 🔴 **المنصورة الجديدة was on دمياط الجديدة, and جمصة was in a field.** Corrected
 * 2026-09-12 after the operator photographed the picker drawing its coverage circle over
 * New Damietta while the pin on New Mansoura University answered «خارج نطاق التوصيل».
 *
 * | Place | Was | Is | Source |
 * |---|---|---|---|
 * | جمصة | 31.3833, 31.4833 | 31.4388, 31.5442 | OSM `place=city` «جمصة», Nominatim |
 * | المنصورة الجديدة | 31.4436, 31.6553 | 31.4500, 31.4600 | see below |
 *
 * The old New Mansoura point is **New Damietta**: Nominatim answers 31.4365, 31.6781 for
 * `place=city` «دمياط الجديدة», ‪2.2‬ km from it, and a ‪12‬ km circle around it covered that
 * city and nothing of this one. New Mansoura is on the other side of Gamasa — **west**,
 * not east. OpenStreetMap holds exactly one feature for it, `way/1305151351`
 * «New Mansoura University - جامعة المنصورة الجديدة» at 31.4610, 31.4571; Overpass finds
 * no `place`, `landuse` or boundary for the city itself, and Nominatim reverse on every
 * point in it answers nothing at all. So the centre is set ‪1.3‬ km south-west of the one
 * confirmed feature, which puts it ‪2.5‬ km inland of the coastline
 * (`natural=coastline` crosses lat ‪31.4728‬ at this longitude) and ‪20.7‬ km from New
 * Damietta — outside every circle here, which is the point.
 *
 * Correct it in `ADM-23` if a better source turns up; the centre is editable there even
 * though the polygon is not.
 *
 * ## Circles, not polygons
 *
 * `GeoController::resolve` tries `polygon` first and falls back to `radius_km`, so a
 * circle is a complete zone. Polygons can be drawn later without touching this.
 *
 * Idempotent: keyed on `name_en`, safe to re-run on every deploy.
 */
class ServiceAreaSeeder extends Seeder
{
    public function run(): void
    {
        // جمصة already exists and already carries orders, addresses and restaurants, so
        // its two zones are updated in place rather than replaced. `وسط جمصة` was sitting
        // on a **100.00** delivery fee against the beach zone's 15.00 — a dashboard typo
        // for the seeded 10.00, and the kind of number that only shows up in a customer's
        // basket.
        // `updateOrCreate`, not `firstOrCreate`. The row already exists on every
        // deployment, so `firstOrCreate` meant the centre below was documentation
        // and not data — and the centre it documented was 31.3833, 31.4833, which is
        // ‪8.6‬ km inland of the town in farmland. Everything reads this: the API's
        // own serviceability fallback, `ADM-23`, and the distance a captain is
        // ranked by when the restaurant has no fix.
        $gamasa = City::query()->updateOrCreate(
            ['name_en' => 'Gamasa'],
            [
                'name_ar' => 'جمصة',
                'center_lat' => 31.4388,
                'center_lng' => 31.5442,
                'pricing_type' => CityPricingType::FixedZone,
                'default_min_order' => 50.00,
                'is_active' => true,
                'sort_order' => 1,
            ],
        );

        // Two zones, one price. The town and the resort strip are 8 km apart and the
        // seeded 3 km circles covered neither end of that, so an address on the مصيف
        // resolved to no zone at all.
        //
        // «وسط جمصة» moved with the city, for the same reason: at 31.3833, 31.4833 its
        // ‪5‬ km circle sat in fields and touched no address in the town it is named
        // after. Both circles are ‪6‬ km, which reaches the western expansions and the
        // villages and stops ‪6.7‬ km short of دمياط الجديدة — a city this platform does
        // not serve and which the old New Mansoura circle covered in full.
        $this->zone($gamasa, 'Gamasa Center', 'وسط جمصة', 31.4388, 31.5442, 6, 15.00, 30);
        $this->zone($gamasa, 'Gamasa Beach', 'الشاطئ', 31.4411, 31.5380, 6, 15.00, 30);

        $cities = [
            [
                'name_en' => 'New Mansoura', 'name_ar' => 'المنصورة الجديدة',
                'lat' => 31.4500, 'lng' => 31.4600, 'sort' => 2,
                'radius' => 8, 'fee' => 30.00, 'minutes' => 40,
                'zone_en' => 'New Mansoura City', 'zone_ar' => 'المنصورة الجديدة',
            ],
            [
                'name_en' => 'Sheikh Zayed City', 'name_ar' => 'مدينة الشيخ زايد',
                'lat' => 30.0456, 'lng' => 30.9787, 'sort' => 3,
                'radius' => 12, 'fee' => 25.00, 'minutes' => 40,
                'zone_en' => 'Sheikh Zayed City', 'zone_ar' => 'مدينة الشيخ زايد',
            ],
            [
                'name_en' => '15th of May City', 'name_ar' => 'مدينة 15 مايو',
                'lat' => 29.8265, 'lng' => 31.3642, 'sort' => 4,
                'radius' => 10, 'fee' => 20.00, 'minutes' => 35,
                'zone_en' => '15th of May City', 'zone_ar' => 'مدينة 15 مايو',
            ],
        ];

        foreach ($cities as $row) {
            $city = City::query()->updateOrCreate(
                ['name_en' => $row['name_en']],
                [
                    'name_ar' => $row['name_ar'],
                    'center_lat' => $row['lat'],
                    'center_lng' => $row['lng'],
                    'pricing_type' => CityPricingType::FixedZone,
                    'default_min_order' => 50.00,
                    'is_active' => true,
                    'sort_order' => $row['sort'],
                ],
            );

            $this->zone(
                $city, $row['zone_en'], $row['zone_ar'],
                $row['lat'], $row['lng'], $row['radius'], $row['fee'], $row['minutes'],
            );
        }
    }

    private function zone(
        City $city,
        string $nameEn,
        string $nameAr,
        float $lat,
        float $lng,
        float $radiusKm,
        float $fee,
        int $minutes,
    ): ServiceZone {
        return ServiceZone::query()->updateOrCreate(
            ['city_id' => $city->id, 'name_en' => $nameEn],
            [
                'name_ar' => $nameAr,
                'center_lat' => $lat,
                'center_lng' => $lng,
                'radius_km' => $radiusKm,
                'delivery_fee' => $fee,
                'min_order_value' => 50.00,
                'avg_delivery_minutes' => $minutes,
                'is_active' => true,
            ],
        );
    }
}
