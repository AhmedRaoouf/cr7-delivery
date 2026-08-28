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
            AdminUserSeeder::class,
            ContentSeeder::class,
            // doc 17 — the real Gamasa catalogue. Without it every browse screen is
            // empty and nothing downstream of a product can be exercised at all.
            CatalogSeeder::class,
        ]);
    }
}
