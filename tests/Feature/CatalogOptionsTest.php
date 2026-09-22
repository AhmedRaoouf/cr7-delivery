<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\OrderGroup;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Catalog\OptionSelection;
use App\Support\PriceBand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsListFixtures;
use Tests\TestCase;

/**
 * doc 76 — options for every place (D-164…D-168).
 *
 * A pharmacy's strengths and a grill's weights are the same thing to the platform: a
 * product, a group, priced options. These tests drive both staff surfaces, the basket,
 * checkout, the order snapshot and reorder through one rule set.
 */
class CatalogOptionsTest extends TestCase
{
    use BuildsListFixtures;
    use RefreshDatabase;

    private Restaurant $pharmacy;

    private Product $medicine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpListWorld();

        $this->pharmacy = $this->makeRestaurant('صيدلية الشفاء');
        $this->medicine = $this->makeProduct($this->pharmacy, 'باراسيتامول', 20.0);
    }

    // ── staff: the place ────────────────────────────────────────────────────────

    public function test_a_place_creates_a_required_single_group_with_its_priced_options_in_one_request(): void
    {
        $response = $this->actingAsPlace($this->pharmacy)
            ->postJson("/api/v1/restaurant/products/{$this->medicine->id}/option-groups", [
                'name_ar' => 'التركيز',
                'name_en' => 'Strength',
                'selection_type' => 'single',
                'is_required' => true,
                'max_select' => 3,
                'options' => [
                    ['name_ar' => '250 مجم', 'name_en' => '250mg', 'price_delta' => 0, 'is_default' => true],
                    ['name_ar' => '500 مجم', 'name_en' => '500mg', 'price_delta' => 15, 'is_default' => true],
                    ['name_ar' => '1000 مجم', 'name_en' => '1000mg', 'price_delta' => 35],
                ],
            ])
            ->assertCreated();

        // A single group takes one, however many the client asked for.
        $response->assertJsonPath('data.min_select', 1)
            ->assertJsonPath('data.max_select', 1)
            ->assertJsonPath('data.is_draft', false)
            ->assertJsonCount(3, 'data.options')
            ->assertJsonPath('data.options.0.name_en', '250mg')
            ->assertJsonPath('data.options.2.sort_order', 2);

        // One default in a single group: the last one set wins, the rest are cleared.
        $group = ProductOptionGroup::query()->firstOrFail();
        $this->assertSame(1, $group->options()->where('is_default', true)->count());
    }

    public function test_a_multiple_group_refuses_a_minimum_above_its_maximum_and_a_negative_price(): void
    {
        $url = "/api/v1/restaurant/products/{$this->medicine->id}/option-groups";

        $this->actingAsPlace($this->pharmacy)->postJson($url, [
            'name_ar' => 'الإضافات', 'name_en' => 'Extras', 'selection_type' => 'multiple',
            'is_required' => true, 'min_select' => 4, 'max_select' => 2,
        ])->assertStatus(422)->assertJsonValidationErrors('min_select');

        $this->actingAsPlace($this->pharmacy)->postJson($url, [
            'name_ar' => 'الإضافات', 'name_en' => 'Extras', 'selection_type' => 'multiple',
            'options' => [['name_ar' => 'بدون', 'name_en' => 'None', 'price_delta' => -5]],
        ])->assertStatus(422)->assertJsonValidationErrors('options.0.price_delta');

        // Optional multiple: the minimum is zero whatever was sent; the maximum defaults
        // to the number of options.
        $this->actingAsPlace($this->pharmacy)->postJson($url, [
            'name_ar' => 'الإضافات', 'name_en' => 'Extras', 'selection_type' => 'multiple', 'min_select' => 2,
            'options' => [
                ['name_ar' => 'جبنة', 'name_en' => 'Cheese', 'price_delta' => 5],
                ['name_ar' => 'بيض', 'name_en' => 'Egg', 'price_delta' => 7],
            ],
        ])->assertCreated()->assertJsonPath('data.min_select', 0)->assertJsonPath('data.max_select', 2);
    }

    public function test_an_empty_group_is_a_draft_the_customer_never_sees_and_the_basket_never_enforces(): void
    {
        $this->actingAsPlace($this->pharmacy)->postJson("/api/v1/restaurant/products/{$this->medicine->id}/option-groups", [
            'name_ar' => 'التركيز', 'name_en' => 'Strength', 'selection_type' => 'single', 'is_required' => true,
        ])->assertCreated()->assertJsonPath('data.is_draft', true);

        $customer = $this->makeCustomer();
        $this->actingAsCustomer($customer)->getJson("/api/v1/products/{$this->medicine->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.option_groups')
            ->assertJsonPath('data.requires_configuration', false);

        $this->actingAsCustomer($customer)->postJson('/api/v1/cart/items', ['product_id' => $this->medicine->id])->assertCreated();
    }

    public function test_groups_and_options_are_reordered_by_sending_every_id(): void
    {
        [$strength, $options] = $this->strengthGroup();
        $extras = $this->medicine->optionGroups()->create(['name_ar' => 'التغليف', 'name_en' => 'Pack', 'selection_type' => 'single', 'sort_order' => 1]);

        $this->actingAsPlace($this->pharmacy)
            ->putJson("/api/v1/restaurant/products/{$this->medicine->id}/option-groups/order", ['ids' => [$extras->id, $strength->id]])
            ->assertOk()
            ->assertJsonPath('data.0.id', $extras->id);

        $this->actingAsPlace($this->pharmacy)
            ->putJson("/api/v1/restaurant/option-groups/{$strength->id}/options/order", ['ids' => [$options[2]->id, $options[0]->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');

        $this->actingAsPlace($this->pharmacy)
            ->putJson("/api/v1/restaurant/option-groups/{$strength->id}/options/order", ['ids' => [$options[2]->id, $options[0]->id, $options[1]->id]])
            ->assertOk()
            ->assertJsonPath('data.0.id', $options[2]->id);
    }

    public function test_a_place_cannot_touch_another_places_options(): void
    {
        [$strength, $options] = $this->strengthGroup();
        $other = $this->makeRestaurant('كنج بروست');

        $this->actingAsPlace($other)->getJson("/api/v1/restaurant/products/{$this->medicine->id}/option-groups")->assertNotFound();
        $this->actingAsPlace($other)->patchJson("/api/v1/restaurant/option-groups/{$strength->id}/options/{$options[0]->id}", ['price_delta' => 1])->assertNotFound();
    }

    // ── staff: the admin ────────────────────────────────────────────────────────

    public function test_an_admin_manages_a_scoped_places_options_through_the_same_rules(): void
    {
        $admin = $this->cityAdmin($this->city);

        $group = $this->actingAsAdmin($admin)->postJson("/api/v1/admin/products/{$this->medicine->id}/option-groups", [
            'name_ar' => 'التركيز', 'name_en' => 'Strength', 'selection_type' => 'single', 'is_required' => true,
            'options' => [['name_ar' => '500 مجم', 'name_en' => '500mg', 'price_delta' => 10]],
        ])->assertCreated()->assertJsonPath('data.max_select', 1)->json('data');

        $this->actingAsAdmin($admin)->postJson("/api/v1/admin/option-groups/{$group['id']}/options", [
            'name_ar' => '1000 مجم', 'name_en' => '1000mg', 'price_delta' => 30, 'is_default' => true,
        ])->assertCreated();

        $this->actingAsAdmin($admin)->getJson("/api/v1/admin/option-groups/{$group['id']}/options")
            ->assertOk()->assertJsonCount(2, 'data');

        $this->actingAsAdmin($admin)->putJson("/api/v1/admin/products/{$this->medicine->id}/option-groups/order", ['ids' => [$group['id']]])
            ->assertOk();
    }

    public function test_out_of_scope_is_404_and_a_viewer_reads_but_cannot_write(): void
    {
        [$strength] = $this->strengthGroup();

        $elsewhere = $this->cityAdmin($this->otherCity, '01000000077');
        $this->actingAsAdmin($elsewhere)->getJson("/api/v1/admin/products/{$this->medicine->id}/option-groups")->assertNotFound();
        $this->actingAsAdmin($elsewhere)->postJson("/api/v1/admin/option-groups/{$strength->id}/options", ['name_ar' => 'س', 'name_en' => 's'])->assertNotFound();

        $viewer = $this->adminWith(['products.view']);
        $this->actingAsAdmin($viewer)->getJson("/api/v1/admin/products/{$this->medicine->id}/option-groups")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAsAdmin($viewer)->getJson("/api/v1/admin/option-groups/{$strength->id}/options")->assertOk();
        $this->actingAsAdmin($viewer)->postJson("/api/v1/admin/option-groups/{$strength->id}/options", ['name_ar' => 'س', 'name_en' => 's'])->assertForbidden();
        $this->actingAsAdmin($viewer)->patchJson("/api/v1/admin/products/{$this->medicine->id}/option-groups/{$strength->id}", ['is_required' => false])->assertForbidden();
    }

    public function test_the_admin_availability_switch_obeys_the_value_it_is_sent(): void
    {
        $admin = $this->cityAdmin($this->city);
        $url = "/api/v1/admin/restaurants/{$this->pharmacy->id}/products/{$this->medicine->id}/availability";

        $this->actingAsAdmin($admin)->postJson($url, ['is_available' => true])->assertOk();
        $this->assertTrue($this->medicine->fresh()->is_available);

        $this->actingAsAdmin($admin)->postJson($url, ['is_available' => false])->assertOk();
        $this->actingAsAdmin($admin)->postJson($url, ['is_available' => false])->assertOk();
        $this->assertFalse($this->medicine->fresh()->is_available);

        // No body: the old toggle, for shipped clients.
        $this->actingAsAdmin($admin)->postJson($url)->assertOk();
        $this->assertTrue($this->medicine->fresh()->is_available);
    }

    // ── the customer ────────────────────────────────────────────────────────────

    public function test_the_catalogue_says_what_a_configured_dish_costs_at_least(): void
    {
        $kofta = $this->makeProduct($this->pharmacy, 'كفتة', 0);
        $weight = $kofta->optionGroups()->create(['name_ar' => 'الوزن', 'name_en' => 'Weight', 'selection_type' => 'single', 'is_required' => true, 'min_select' => 1, 'max_select' => 1]);
        $weight->options()->createMany([
            ['name_ar' => 'ربع', 'name_en' => 'Quarter', 'price_delta' => 90, 'is_available' => false],
            ['name_ar' => 'نص', 'name_en' => 'Half', 'price_delta' => 170],
            ['name_ar' => 'كيلو', 'name_en' => 'Kilo', 'price_delta' => 330],
        ]);

        $row = collect($this->actingAsCustomer($this->makeCustomer())
            ->getJson("/api/v1/restaurants/{$this->pharmacy->id}/products")->assertOk()->json('data'))
            ->firstWhere('id', $kofta->id);

        $this->assertTrue($row['requires_configuration']);
        $this->assertTrue($row['has_options']);
        // The cheapest *available* weight — «ربع» is switched off.
        $this->assertEquals(170, $row['from_price']);

        $plain = collect($this->getJson("/api/v1/restaurants/{$this->pharmacy->id}/products")->json('data'))->firstWhere('id', $this->medicine->id);
        $this->assertFalse($plain['has_options']);
        $this->assertEquals(20, $plain['from_price']);
    }

    public function test_the_basket_holds_every_rule_on_add_and_on_edit(): void
    {
        [$strength, $options] = $this->strengthGroup();
        $extras = $this->medicine->optionGroups()->create(['name_ar' => 'إضافات', 'name_en' => 'Extras', 'selection_type' => 'multiple', 'max_select' => 1, 'sort_order' => 1]);
        $box = $extras->options()->create(['name_ar' => 'علبة', 'name_en' => 'Box', 'price_delta' => 3]);
        $bag = $extras->options()->create(['name_ar' => 'كيس', 'name_en' => 'Bag', 'price_delta' => 1]);
        $customer = $this->makeCustomer();
        $add = fn (array $ids) => $this->actingAsCustomer($customer)->postJson('/api/v1/cart/items', ['product_id' => $this->medicine->id, 'option_ids' => $ids, 'quantity' => 2]);

        $add([])->assertStatus(422)->assertJsonPath('error', 'validation_failed')->assertJsonValidationErrors('option_group_id');
        $add([$options[0]->id, $options[1]->id])->assertStatus(422)->assertJsonValidationErrors('option_group_id');
        $add([$options[1]->id, $box->id, $bag->id])->assertStatus(422)->assertJsonValidationErrors('option_group_id');

        $options[2]->update(['is_available' => false]);
        $add([$options[2]->id])->assertStatus(422)->assertJsonPath('error', 'option_unavailable');

        // 20 + 15 (500 mg) + 3 (box) = 38 × 2.
        $line = $add([$options[1]->id, $box->id])->assertCreated()->json('data.groups.0.items.0');
        $this->assertEquals(76, $line['line_total']);
        $this->assertTrue($line['options_valid']);
        $this->assertEqualsCanonicalizing([$options[1]->id, $box->id], $line['option_ids']);
        $this->assertSame('Strength', collect($line['options'])->firstWhere('id', $options[1]->id)['group_name']);

        // The edit is held to the same rules — it used to accept an empty size.
        $this->actingAsCustomer($customer)->patchJson("/api/v1/cart/items/{$line['id']}", ['option_ids' => []])
            ->assertStatus(422)->assertJsonValidationErrors('option_group_id');
        $this->actingAsCustomer($customer)->patchJson("/api/v1/cart/items/{$line['id']}", ['option_ids' => [$options[0]->id], 'quantity' => 1, 'note' => 'بسرعة'])
            ->assertOk()
            ->assertJsonPath('data.groups.0.items.0.line_total', 20)
            ->assertJsonPath('data.groups.0.items.0.note', 'بسرعة');
    }

    public function test_checkout_refuses_a_line_the_menu_moved_under_and_names_it(): void
    {
        [, $options] = $this->strengthGroup();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $line = $this->actingAsCustomer($customer)->postJson('/api/v1/cart/items', ['product_id' => $this->medicine->id, 'option_ids' => [$options[1]->id]])
            ->assertCreated()->json('data.groups.0.items.0');

        $options[1]->update(['is_available' => false]);

        $this->actingAsCustomer($customer)->getJson('/api/v1/cart')
            ->assertJsonPath('data.groups.0.items.0.options_valid', false)
            ->assertJsonPath('data.groups.0.items.0.options_issue.code', 'option_unavailable')
            ->assertJsonPath('data.groups.0.issues.0.code', 'options_invalid')
            ->assertJsonPath('data.groups.0.issues.0.cart_item_id', $line['id']);

        $this->actingAsCustomer($customer)
            ->postJson('/api/v1/orders', ['address_id' => $address->id, 'payment_method' => 'cash'], ['Idempotency-Key' => 'options-moved'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'options_invalid')
            ->assertJsonPath('meta.blockers.0.cart_item_id', $line['id']);

        $options[1]->update(['is_available' => true]);
        $this->medicine->update(['is_available' => false]);

        $this->actingAsCustomer($customer)
            ->postJson('/api/v1/orders', ['address_id' => $address->id, 'payment_method' => 'cash'], ['Idempotency-Key' => 'product-off'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'product_unavailable');
    }

    public function test_the_order_keeps_what_was_chosen_after_the_menu_changes_and_reorder_asks_again(): void
    {
        [$strength, $options] = $this->strengthGroup();
        $extras = $this->medicine->optionGroups()->create(['name_ar' => 'إضافات', 'name_en' => 'Extras', 'selection_type' => 'multiple', 'max_select' => 2, 'sort_order' => 1]);
        $box = $extras->options()->create(['name_ar' => 'علبة', 'name_en' => 'Box', 'price_delta' => 3]);

        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $this->actingAsCustomer($customer)->postJson('/api/v1/cart/items', ['product_id' => $this->medicine->id, 'option_ids' => [$options[1]->id, $box->id]])->assertCreated();
        $this->actingAsCustomer($customer)->postJson('/api/v1/orders', ['address_id' => $address->id, 'payment_method' => 'cash'], ['Idempotency-Key' => 'snap'])->assertCreated();
        $group = OrderGroup::query()->latest('id')->firstOrFail();

        // The place renames the strength, reprices it and deletes the box.
        $strength->update(['name_ar' => 'الجرعة', 'name_en' => 'Dose']);
        $options[1]->update(['name_ar' => 'نصف جرام', 'price_delta' => 99]);
        $box->delete();

        $items = $this->withHeader('Accept-Language', 'en')->actingAsCustomer($customer)->getJson("/api/v1/orders/{$group->id}")->assertOk()->json('data.sub_orders.0.items.0');
        $this->assertEquals(38, $items['line_total']);
        $this->assertSame(['Strength', 'Extras'], collect($items['options'])->pluck('group_name')->all());
        $this->assertSame(['500mg', 'Box'], collect($items['options'])->pluck('name')->all());
        $this->assertEquals([15, 3], collect($items['options'])->pluck('price_delta')->all());

        // Reorder: the box is gone (optional — dropped and said), the strength remains.
        $reorder = $this->withHeader('Accept-Language', 'ar')->actingAsCustomer($customer)->postJson("/api/v1/orders/{$group->id}/reorder")->assertOk()->json('data');
        $this->assertSame([$this->medicine->id], $reorder['added']);
        $this->assertSame(['علبة'], $reorder['adjusted_items'][0]['dropped_options']);
        $line = $customer->cart->items()->with('options')->sole();
        $this->assertSame([$options[1]->id], $line->options->pluck('product_option_id')->all());

        // Twice is still one line.
        $this->actingAsCustomer($customer)->postJson("/api/v1/orders/{$group->id}/reorder")->assertOk();
        $this->assertSame(2, (int) $customer->cart->items()->sole()->quantity);

        // A required size switched off: the line cannot be rebuilt and is skipped by name.
        $customer->cart->items()->delete();
        $options[1]->update(['is_available' => false]);
        $reorder = $this->actingAsCustomer($customer)->postJson("/api/v1/orders/{$group->id}/reorder")->assertOk()->json('data');
        $this->assertSame([], $reorder['added']);
        $this->assertSame('options_unavailable', $reorder['skipped_items'][0]['reason']);
        $this->assertSame(0, $customer->cart->items()->count());
    }

    public function test_the_price_band_counts_a_dish_priced_by_its_sizes_at_its_smallest_size(): void
    {
        $this->medicine->delete();
        foreach (['كفتة' => [140, 260], 'طرب' => [150, 280], 'كباب' => [160, 300]] as $name => [$half, $kilo]) {
            $product = $this->makeProduct($this->pharmacy, $name, 0);
            $weight = $product->optionGroups()->create(['name_ar' => 'الوزن', 'name_en' => 'Weight', 'selection_type' => 'single', 'is_required' => true, 'min_select' => 1, 'max_select' => 1]);
            $weight->options()->createMany([
                ['name_ar' => 'نص', 'name_en' => 'Half', 'price_delta' => $half],
                ['name_ar' => 'كيلو', 'name_en' => 'Kilo', 'price_delta' => $kilo],
            ]);
        }

        $this->assertSame('premium', PriceBand::recompute($this->pharmacy->refresh()));
        $this->assertSame('premium', $this->pharmacy->refresh()->price_band);
    }

    public function test_the_validator_takes_one_from_a_single_group_even_if_its_row_says_more(): void
    {
        [$strength, $options] = $this->strengthGroup();
        $strength->forceFill(['max_select' => 5])->save();

        $problem = OptionSelection::problem($this->medicine->fresh(), [$options[0]->id, $options[1]->id]);

        $this->assertSame('validation_failed', $problem['code']);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /** @return array{0: ProductOptionGroup, 1: list<ProductOption>} */
    private function strengthGroup(): array
    {
        $group = $this->medicine->optionGroups()->create([
            'name_ar' => 'التركيز', 'name_en' => 'Strength', 'selection_type' => 'single',
            'is_required' => true, 'min_select' => 1, 'max_select' => 1, 'sort_order' => 0,
        ]);

        $options = [
            $group->options()->create(['name_ar' => '250 مجم', 'name_en' => '250mg', 'price_delta' => 0, 'sort_order' => 0]),
            $group->options()->create(['name_ar' => '500 مجم', 'name_en' => '500mg', 'price_delta' => 15, 'sort_order' => 1]),
            $group->options()->create(['name_ar' => '1000 مجم', 'name_en' => '1000mg', 'price_delta' => 35, 'sort_order' => 2]),
        ];

        return [$group, $options];
    }

    /** @param  list<string>  $permissions */
    private function adminWith(array $permissions): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'menu_viewer', 'guard_name' => 'web'], ['label_ar' => 'مشاهد', 'label_en' => 'Viewer']);
        $role->syncPermissions(Permission::query()->whereIn('name', $permissions)->pluck('name')->all());

        $admin = User::query()->create(['name' => 'مشاهد', 'phone' => '01000000066', 'type' => 'admin', 'is_active' => true]);
        $admin->assignRole('menu_viewer');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        return $admin;
    }
}
