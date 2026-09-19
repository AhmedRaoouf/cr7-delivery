<?php

namespace App\Console\Commands;

use App\Enums\UserType;
use App\Models\City;
use App\Models\Driver;
use App\Models\MenuCategory;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\ServiceZone;
use App\Models\User;
use App\Models\UserAddress;
use App\Support\Auth\Sessions;
use App\Support\ReviewAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * The three accounts a store reviewer signs in with — one customer, one captain, one place.
 *
 * They are made on the phone numbers `OTP_REVIEW_PHONES` lists, in that order, so the
 * allowlist ({@see ReviewAccess}) and the accounts cannot disagree. Run it after setting
 * the `.env` lines and `config:cache`; running it again resets each password and leaves
 * everything else alone.
 *
 * **What keeps them off real people.**
 *
 *   * The place's restaurant is `is_active = false`, so no customer's home, search or
 *     restaurant page can show it, and it can receive no order. Its owner still signs in
 *     and sees the whole dashboard.
 *   * The captain is offline and never offered an order: dispatch leaves review numbers
 *     out ({@see ReviewAccess::isReviewPhone()}).
 *   * The customer holds an address in the launch area and nothing else.
 *
 * The password is an argument and is printed nowhere by this command: the caller chose it,
 * and it goes where the review form asks for it, not into a log.
 */
class ReviewAccountsCommand extends Command
{
    /** What marks an account as one of ours: an existing account without it is somebody's, and is left alone. */
    private const NAME_PREFIX = 'حساب مراجعة المتجر';

    protected $signature = 'cr7:review-accounts
        {--password= : the password all three accounts sign in with (10+ characters, letters and digits)}';

    protected $description = 'Create or reset the store-review customer, captain and place accounts';

    public function handle(): int
    {
        $phones = ReviewAccess::phones();

        if (count($phones) < 3 || ReviewAccess::code() === null) {
            $this->error('Set OTP_REVIEW_PHONES to three phones (customer, captain, place) and OTP_REVIEW_CODE to six digits, run config:cache, then try again.');

            return self::FAILURE;
        }

        $password = (string) $this->option('password');

        if (strlen($password) < 10 || preg_match('/[A-Za-z]/', $password) !== 1 || preg_match('/\d/', $password) !== 1) {
            $this->error('--password must be at least 10 characters with letters and digits.');

            return self::FAILURE;
        }

        $city = City::query()->orderBy('id')->first();
        $zone = $city === null ? null : ServiceZone::query()->where('city_id', $city->id)->orderBy('id')->first();

        if ($city === null || $zone === null) {
            $this->error('No city or service zone exists yet — seed the catalogue first.');

            return self::FAILURE;
        }

        [$customerPhone, $driverPhone, $placePhone] = $phones;

        $customer = $this->account($customerPhone, UserType::Customer, self::NAME_PREFIX.' — عميل', $password);
        $address = UserAddress::query()->firstOrCreate(
            ['user_id' => $customer->id, 'label' => 'home'],
            [
                'city_id' => $city->id,
                'service_zone_id' => $zone->id,
                'title' => 'المنزل',
                'street' => 'جمصة — عنوان تجريبي للمراجعة',
                'contact_phone' => $customerPhone,
                'latitude' => 31.4388,
                'longitude' => 31.5442,
                'is_default' => true,
            ],
        );
        $customer->forceFill(['default_address_id' => $address->id])->save();

        $captain = $this->account($driverPhone, UserType::Driver, self::NAME_PREFIX.' — كابتن', $password);
        Driver::query()->firstOrCreate(
            ['user_id' => $captain->id],
            [
                'city_id' => $city->id,
                'national_id' => '29000000000000',
                'vehicle_type' => 'motorcycle',
                'vehicle_plate' => 'REVIEW',
                'earning_type' => 'percent_of_delivery_fee',
                'earning_value' => 0,
                'is_online' => false,
                'is_available' => false,
                'is_active' => true,
            ],
        );

        $owner = $this->account($placePhone, UserType::Restaurant, self::NAME_PREFIX.' — مطعم', $password);
        $restaurant = Restaurant::query()->updateOrCreate(
            ['slug' => 'store-review-demo-place'],
            [
                'user_id' => $owner->id,
                'city_id' => $city->id,
                'service_zone_id' => $zone->id,
                'name_ar' => 'مطعم المراجعة (تجريبي)',
                'name_en' => 'Review demo place',
                'phone' => $placePhone,
                'address' => 'جمصة — مطعم تجريبي لا يظهر للعملاء',
                'latitude' => 31.4388,
                'longitude' => 31.5442,
                'commission_rate' => 0,
                'min_order_value' => 0,
                'avg_prep_minutes' => 20,
                'is_open_manual' => false,
                // The whole point: no customer can see it, so no order can reach it.
                'is_active' => false,
                'is_verified' => false,
                'is_featured' => false,
                'direct_orders_enabled' => false,
                'sort_order' => 9999,
            ],
        );

        $category = MenuCategory::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'name_ar' => 'أطباق تجريبية'],
            ['name_en' => 'Sample dishes', 'sort_order' => 1, 'is_active' => true],
        );
        Product::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'name_ar' => 'طبق تجريبي'],
            [
                'menu_category_id' => $category->id,
                'name_en' => 'Sample dish',
                'price' => 50,
                'prep_minutes' => 15,
                'is_available' => true,
                'sort_order' => 1,
            ],
        );

        $this->info("customer {$customerPhone} · captain {$driverPhone} · place {$placePhone}");
        $this->line('passwords set to the one given; every earlier session of these accounts is signed out');
        $this->line('the demo place is is_active=false — hidden from every customer; the captain is offline and never offered orders');

        return self::SUCCESS;
    }

    private function account(string $phone, UserType $type, string $name, string $password): User
    {
        $existing = User::query()->where('phone', $phone)->first();

        // A number on the allowlist that already belongs to a real person must never have
        // its password reset by this command — only an account this command made is touched.
        if ($existing !== null && (! str_starts_with((string) $existing->name, self::NAME_PREFIX) || $existing->type !== $type)) {
            throw new \RuntimeException("{$phone} already belongs to an account this command did not create; refusing to touch it.");
        }

        $user = $existing ?? new User(['phone' => $phone]);
        $user->forceFill([
            'name' => $name,
            'password' => Hash::make($password),
            'type' => $type,
            'locale' => 'ar',
            'theme' => 'dark',
            'is_active' => true,
            'must_change_password' => false,
            'phone_verified_at' => now(),
        ])->save();

        Sessions::revokeAll($user);

        return $user;
    }
}
