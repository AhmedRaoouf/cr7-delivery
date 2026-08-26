<?php

namespace App\Actions\Admin;

use App\Enums\UserType;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** FR-REST-01 — "Creating a restaurant creates a linked `users` row with role `restaurant`." */
class CreateRestaurantAction
{
    /** @return array{restaurant: Restaurant, temp_password: string} */
    public function execute(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $tempPassword = Str::password(12);

            $owner = User::create([
                'name' => $data['name_en'],
                'phone' => $data['owner_phone'],
                'email' => $data['owner_email'] ?? null,
                'password' => Hash::make($tempPassword),
                'type' => UserType::Restaurant,
                'is_active' => true,
                'must_change_password' => true,
            ]);

            $restaurant = Restaurant::create([
                'user_id' => $owner->id,
                'city_id' => $data['city_id'],
                'service_zone_id' => $data['service_zone_id'],
                'name_ar' => $data['name_ar'],
                'name_en' => $data['name_en'],
                'description_ar' => $data['description_ar'] ?? null,
                'description_en' => $data['description_en'] ?? null,
                'slug' => Str::slug($data['name_en']).'-'.Str::random(6),
                'phone' => $data['phone'],
                'whatsapp' => $data['whatsapp'] ?? null,
                'address' => $data['address'] ?? null,
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'commission_rate' => $data['commission_rate'],
                'min_order_value' => $data['min_order_value'] ?? 0,
                'avg_prep_minutes' => $data['avg_prep_minutes'] ?? 20,
                'is_open_manual' => true,
                'is_active' => true,
            ]);

            $restaurant->servedZones()->syncWithoutDetaching([$data['service_zone_id']]);

            return ['restaurant' => $restaurant, 'temp_password' => $tempPassword];
        });
    }
}
