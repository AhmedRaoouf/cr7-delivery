<?php

namespace App\Actions\Admin;

use App\Enums\UserType;
use App\Models\Driver;
use App\Models\User;
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
                'vehicle_type' => $data['vehicle_type'],
                'vehicle_plate' => $data['vehicle_plate'] ?? null,
                'earning_type' => $data['earning_type'],
                'earning_value' => $data['earning_value'],
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
