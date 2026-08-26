<?php

namespace Database\Seeders;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * doc 01 §2: Admin is "seeded / created by super admin" — every other admin
 * account goes through the invite flow (FR-ADM-14); this is the one exception,
 * the break-glass account that can invite the first real super_admin.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('SEED_ADMIN_PASSWORD', 'Cr7Delivery#2026');

        $admin = User::query()->firstOrCreate(
            ['phone' => '01000000099'],
            [
                'name' => 'Super Admin',
                'email' => 'admin@cr7delivery.com',
                'password' => Hash::make($password),
                'type' => UserType::Admin,
                'locale' => 'ar',
                'theme' => 'dark',
                'is_active' => true,
                'must_change_password' => true,
                'phone_verified_at' => now(),
            ],
        );

        $admin->assignRole('super_admin');

        $admin->adminProfile()->firstOrCreate([], [
            'accepted_at' => now(),
            'must_change_password' => true,
        ]);

        $this->command?->info("Seeded super_admin login -> phone: {$admin->phone} / password: {$password}");
    }
}
