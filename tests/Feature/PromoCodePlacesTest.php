<?php

namespace Tests\Feature;

use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Support\CheckoutPricer;
use App\Support\FirstOrderPerk;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsListFixtures;
use Tests\TestCase;

/**
 * doc 96 — a code for one place, or a few.
 *
 * Before: `restrict_restaurant_ids` existed and no form could set it, and a code that
 * did carry it took its percentage from the whole basket and split it across every
 * place in it — a place that never agreed to the code paid a share of it.
 */
class PromoCodePlacesTest extends TestCase
{
    use BuildsListFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpListWorld();
        Settings::set(FirstOrderPerk::SETTING, 0, 'delivery');
    }

    private function place(string $ar, string $en): Restaurant
    {
        $place = $this->makeRestaurant($ar);
        $place->forceFill(['name_en' => $en])->save();

        return $place;
    }

    private function code(array $attributes): PromoCode
    {
        return PromoCode::query()->create($attributes + [
            'title_ar' => 'خصم', 'title_en' => 'Discount', 'is_active' => true,
        ]);
    }

    public function test_a_place_code_discounts_only_that_places_dishes_and_only_its_share_carries_it(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $takka = $this->place('تكة', 'Takka');
        $maarouf = $this->place('معروف', 'Maarouf');
        $cart = $this->makeCart($customer, [
            [$takka, $this->makeProduct($takka, 'كباب', 100), 1],
            [$maarouf, $this->makeProduct($maarouf, 'فول', 300), 1],
        ]);
        $this->code(['code' => 'TAKKA20', 'type' => 'percent', 'value' => 20, 'restrict_restaurant_ids' => [$takka->id], 'cost_bearer' => 'restaurant']);

        $quote = app(CheckoutPricer::class)->price($customer, $cart, $address, 'TAKKA20', 'cash');

        // 20% of تكة's 100, not of the 400 basket.
        $this->assertSame(20.0, $quote['discount_amount']);
        $shares = collect($quote['groups'])->pluck('discount_share', 'restaurant_id');
        $this->assertSame(20.0, $shares[$takka->id]);
        $this->assertSame(0.0, $shares[$maarouf->id]);
    }

    public function test_a_code_for_every_place_still_discounts_the_whole_basket(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $a = $this->place('أ', 'A');
        $b = $this->place('ب', 'B');
        $cart = $this->makeCart($customer, [
            [$a, $this->makeProduct($a, 'صنف', 100), 1],
            [$b, $this->makeProduct($b, 'صنف', 300), 1],
        ]);
        $this->code(['code' => 'ALL10', 'type' => 'percent', 'value' => 10]);

        $quote = app(CheckoutPricer::class)->price($customer, $cart, $address, 'ALL10', 'cash');

        $this->assertSame(40.0, $quote['discount_amount']);
        $this->assertSame(40.0, round(collect($quote['groups'])->sum('discount_share'), 2));
    }

    public function test_the_minimum_is_judged_on_the_places_own_dishes(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $takka = $this->place('تكة', 'Takka');
        $other = $this->place('معروف', 'Maarouf');
        $cart = $this->makeCart($customer, [
            [$takka, $this->makeProduct($takka, 'كباب', 50), 1],
            [$other, $this->makeProduct($other, 'فول', 300), 1],
        ]);
        $this->code(['code' => 'TAKKA20', 'type' => 'percent', 'value' => 20, 'min_order_value' => 100, 'restrict_restaurant_ids' => [$takka->id]]);

        $quote = app(CheckoutPricer::class)->price($customer, $cart, $address, 'TAKKA20', 'cash');

        $this->assertFalse($quote['promo']['valid']);
        $this->assertSame('below_min_order', $quote['promo']['reason']);
    }

    public function test_the_customer_is_told_which_place_a_code_belongs_to(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $takka = $this->place('تكة', 'Takka');
        $other = $this->place('معروف', 'Maarouf');
        $this->makeCart($customer, [[$other, $this->makeProduct($other, 'فول', 300), 1]]);
        $this->code(['code' => 'TAKKA20', 'type' => 'percent', 'value' => 20, 'restrict_restaurant_ids' => [(string) $takka->id]]);

        $this->actingAsCustomer($customer)
            ->postJson('/api/v1/promo/validate', ['code' => 'takka20', 'address_id' => $address->id])
            ->assertStatus(422)
            ->assertJsonPath('error', 'promo_no_longer_applicable')
            ->assertJsonPath('message', 'هذا الكود خاص بـ تكة فقط');
    }

    public function test_validating_in_a_mixed_basket_hands_the_app_the_amount_it_will_be_charged(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $takka = $this->place('تكة', 'Takka');
        $other = $this->place('معروف', 'Maarouf');
        $this->makeCart($customer, [
            [$takka, $this->makeProduct($takka, 'كباب', 100), 1],
            [$other, $this->makeProduct($other, 'فول', 300), 1],
        ]);
        $this->code(['code' => 'TAKKA20', 'type' => 'percent', 'value' => 20, 'restrict_restaurant_ids' => [$takka->id]]);

        // Every app build applies a percentage to the whole basket it shows; 20% of 400
        // would read 80. Handed as a fixed 20 it reads what checkout charges.
        $this->actingAsCustomer($customer)
            ->postJson('/api/v1/promo/validate', ['code' => 'TAKKA20', 'address_id' => $address->id])
            ->assertOk()
            ->assertJsonPath('data.type', 'fixed')
            ->assertJsonPath('data.value', 20)
            ->assertJsonPath('data.discount', 20)
            ->assertJsonPath('data.restaurant_ids', [$takka->id]);
    }

    public function test_an_admin_picks_places_and_leaves_the_code_and_title_to_be_made(): void
    {
        $takka = $this->place('تكة', 'Takka Grill');
        $maarouf = $this->place('معروف', 'Maarouf');

        $response = $this->actingAsAdmin($this->globalAdmin())
            ->postJson('/api/v1/admin/promo-codes', [
                'type' => 'percent',
                'value' => 20,
                'restrict_restaurant_ids' => [(string) $takka->id, $maarouf->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'TAKKAGRILL20')
            ->assertJsonPath('data.title_ar', 'خصم 20% على تكة ومعروف')
            ->assertJsonPath('data.title_en', '20% off at Takka Grill & Maarouf')
            ->assertJsonPath('data.restaurants.0.name', 'تكة')
            ->assertJsonPath('data.restaurants.1.name', 'معروف');

        $this->assertSame([$takka->id, $maarouf->id], PromoCode::query()->find($response->json('data.id'))->restaurantIds());

        // The same rule again does not collide.
        $this->postJson('/api/v1/admin/promo-codes', ['type' => 'percent', 'value' => 20, 'restrict_restaurant_ids' => [$takka->id]])
            ->assertCreated()
            ->assertJsonPath('data.code', 'TAKKAGRILL202');
    }

    public function test_a_typed_code_wins_is_stored_in_capitals_and_a_missing_place_is_refused(): void
    {
        $takka = $this->place('تكة', 'Takka');
        $admin = $this->actingAsAdmin($this->globalAdmin());

        $admin->postJson('/api/v1/admin/promo-codes', [
            'code' => ' eid50 ', 'type' => 'fixed', 'value' => 50, 'restrict_restaurant_ids' => [$takka->id],
        ])->assertCreated()->assertJsonPath('data.code', 'EID50')->assertJsonPath('data.title_ar', 'خصم 50 ج.م على تكة');

        $admin->postJson('/api/v1/admin/promo-codes', [
            'type' => 'percent', 'value' => 10, 'restrict_restaurant_ids' => [999999],
        ])->assertStatus(422);

        $admin->postJson('/api/v1/admin/promo-codes', [
            'code' => 'خصم', 'type' => 'percent', 'value' => 10,
        ])->assertStatus(422);
    }

    public function test_clearing_the_places_makes_the_code_cover_every_place(): void
    {
        $takka = $this->place('تكة', 'Takka');
        $promo = $this->code(['code' => 'TAKKA20', 'type' => 'percent', 'value' => 20, 'restrict_restaurant_ids' => [$takka->id]]);

        $this->actingAsAdmin($this->globalAdmin())
            ->patchJson("/api/v1/admin/promo-codes/{$promo->id}", ['restrict_restaurant_ids' => []])
            ->assertOk()
            ->assertJsonPath('data.restaurants', []);

        $this->assertNull($promo->fresh()->restrict_restaurant_ids);
    }

    public function test_the_list_filters_by_place_and_suggest_previews_the_code(): void
    {
        $takka = $this->place('تكة', 'Takka');
        $other = $this->place('معروف', 'Maarouf');
        $mine = $this->code(['code' => 'TAKKA20', 'type' => 'percent', 'value' => 20, 'restrict_restaurant_ids' => [$takka->id]]);
        $everyone = $this->code(['code' => 'ALL10', 'type' => 'percent', 'value' => 10]);
        $this->code(['code' => 'MAAROUF5', 'type' => 'fixed', 'value' => 5, 'restrict_restaurant_ids' => [$other->id]]);

        $this->actingAsAdmin($this->globalAdmin());

        $this->assertSame([$mine->id], $this->idsOf('/api/v1/admin/promo-codes', ['filter' => ['restaurant_id' => $takka->id]]));
        $this->assertEqualsCanonicalizing(
            [$mine->id, $everyone->id],
            $this->idsOf('/api/v1/admin/promo-codes', ['filter' => ['restaurant_id' => $takka->id, 'include_all_places' => '1']]),
        );

        $this->getJson('/api/v1/admin/promo-codes/suggest?'.http_build_query(['restaurant_ids' => [$takka->id], 'type' => 'free_delivery']))
            ->assertOk()
            ->assertJsonPath('data.code', 'TAKKAFREE')
            ->assertJsonPath('data.title_ar', 'توصيل مجاني من تكة');

        $this->getJson("/api/v1/admin/promo-codes/suggest?restaurant_ids={$takka->id},{$other->id}&type=fixed&value=30")
            ->assertOk()
            ->assertJsonPath('data.code', 'TAKKA30')
            ->assertJsonPath('data.title_ar', 'خصم 30 ج.م على تكة ومعروف');
    }
}
