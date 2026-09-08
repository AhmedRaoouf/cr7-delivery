<?php

namespace App\Actions\Admin;

use App\Enums\UserType;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\TempPassword;
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
            $tempPassword = TempPassword::issue();

            $owner = User::create([
                'name' => $data['name_en'],
                'phone' => $data['owner_phone'],
                'email' => $data['owner_email'] ?? null,
                'password' => Hash::make($tempPassword),
                'type' => UserType::Restaurant,
                'is_active' => true,
                'must_change_password' => true,
            ]);

            // 🔴 Built from the **validated payload**, not from a hand-written
            // list of columns. The hand-written one silently dropped five things
            // `StoreRestaurantRequest` had already accepted — `store_category_id`,
            // both `eta_*` minutes, `is_24_7`, and the `logo`/`cover` paths the
            // controller had just uploaded — so a place created through `ADM-12`
            // came back with no category, no delivery window and no picture, and
            // nothing failed. Adding a field to the request now carries it here
            // by construction.
            //
            // The keys below are the ones the *action* owns rather than the
            // operator: an owner's phone belongs to the `users` row, and
            // `name_en` is consumed by the slug.
            $columns = collect($data)
                ->except(['owner_phone', 'owner_email', 'remove_logo', 'remove_cover'])
                ->filter(fn ($value) => $value !== null)
                ->all();

            $restaurant = Restaurant::create([
                ...$columns,
                'user_id' => $owner->id,
                'slug' => Str::slug($data['name_en']).'-'.Str::random(6),
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
