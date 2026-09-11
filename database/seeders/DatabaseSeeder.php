<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            PermissionsSeeder::class,
            RolesSeeder::class,
            SettingsSeeder::class,
            LocationSeeder::class,
            // The launch city's own address book — ‪6 128‬ doors, ‪224‬ streets,
            // ‪32‬ areas from OpenStreetMap. Read before any map provider is, so
            // a pin is named «‪145‬ شارع إسماعيل فريد، منطقة الكرنك» rather than
            // after whichever delivery zone happened to contain it.
            GazetteerSeeder::class,
            // The four cities the platform actually delivers in…
            ServiceAreaSeeder::class,
            // …and, over them, the operator's printed price list: 31 named areas with
            // their own fees, plus the city coverage circles those areas rely on (D-70).
            OperatorZonePricingSeeder::class,
            // Real contact and wallet numbers over the launch placeholders, and the
            // 15 % delivery split every captain is on (D-70, D-71).
            OperatorSettingsSeeder::class,
            AdminUserSeeder::class,
            ContentSeeder::class,
            // doc 17 — the real Gamasa catalogue. Without it every browse screen is
            // empty and nothing downstream of a product can be exercised at all.
            CatalogSeeder::class,
        ]);
    }
}
