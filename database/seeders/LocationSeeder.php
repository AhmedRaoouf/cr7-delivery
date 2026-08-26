<?php

namespace Database\Seeders;

use App\Enums\CityPricingType;
use App\Models\City;
use Illuminate\Database\Seeder;

/** doc 01 — Gamasa launches first; every later city is a row, never a code change. */
class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $gamasa = City::query()->firstOrCreate(
            ['name_en' => 'Gamasa'],
            [
                'name_ar' => 'جمصة',
                'center_lat' => 31.3833,
                'center_lng' => 31.4833,
                'pricing_type' => CityPricingType::FixedZone,
                'default_min_order' => 50.00,
                'is_active' => true,
                'sort_order' => 1,
            ],
        );

        $zones = [
            ['name_ar' => 'وسط جمصة', 'name_en' => 'Gamasa Center', 'lat' => 31.3833, 'lng' => 31.4833, 'fee' => 10.00],
            ['name_ar' => 'الشاطئ', 'name_en' => 'Gamasa Beach', 'lat' => 31.4000, 'lng' => 31.4700, 'fee' => 15.00],
        ];

        foreach ($zones as $zone) {
            $gamasa->serviceZones()->firstOrCreate(
                ['name_en' => $zone['name_en']],
                [
                    'name_ar' => $zone['name_ar'],
                    'center_lat' => $zone['lat'],
                    'center_lng' => $zone['lng'],
                    'radius_km' => 3,
                    'delivery_fee' => $zone['fee'],
                    'min_order_value' => 50.00,
                    'avg_delivery_minutes' => 30,
                    'is_active' => true,
                ],
            );
        }
    }
}
