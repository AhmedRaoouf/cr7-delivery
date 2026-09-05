<?php

namespace App\Actions\Admin;

use App\Enums\UserType;
use App\Models\Driver;
use App\Models\User;
use App\Support\Settings;
use App\Support\TempPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** FR-DRV-01 / D-11 — captain accounts are created by Admin only, never self-signed-up. */
class CreateDriverAction
{
    /** @return array{driver: Driver, temp_password: string} */
    public function execute(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $tempPassword = TempPassword::issue();

            $user = User::create([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'password' => Hash::make($tempPassword),
                'type' => UserType::Driver,
                'is_active' => true,
                'must_change_password' => true,
            ]);

            $driver = Driver::create([
                'user_id' => $user->id,
                'city_id' => $data['city_id'],
                'national_id' => $data['national_id'] ?? null,
                'vehicle_type' => $data['vehicle_type'] ?? 'motorcycle',
                'vehicle_plate' => $data['vehicle_plate'] ?? null,
                // D-71 — `percent_of_delivery_fee` at 0 is not «no earnings»: it means
                // «the standard split», and {@see \App\Support\DriverEarningCalculator}
                // reads the rate from `delivery.platform_commission_rate`. Keeping the
                // percentage off the driver row is what lets the operator move 15 → 20
                // in one field instead of once per captain.
                'earning_type' => $data['earning_type']
                    ?? Settings::get('driver.default_earning_type', 'percent_of_delivery_fee'),
                'earning_value' => $data['earning_value']
                    ?? Settings::get('driver.default_earning_value', 0),
                'is_online' => false,
                'is_available' => true,
                'is_active' => true,
            ]);

            if (! empty($data['zone_ids'])) {
                $driver->assignedZones()->syncWithoutDetaching($data['zone_ids']);
            }

            return ['driver' => $driver, 'temp_password' => $tempPassword];
        });
    }
}
