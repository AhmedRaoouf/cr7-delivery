<?php

namespace Tests\Feature;

use App\Actions\Errand\QuoteErrandAction;
use App\Enums\AdminScopeType;
use App\Models\Setting;
use App\Models\User;
use App\Support\DeliveryCommission;
use App\Support\Settings;
use App\Support\SettingsCatalog;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * D-113 — the panel both clients draw, and the save that used to write nothing.
 *
 * The web form posted `{settings: {…}}`; the controller iterated the top level, found a
 * key called `settings`, matched no row and answered «تم تحديث الإعدادات بنجاح».
 */
class SettingsPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);
    }

    private function asAdmin(): static
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($admin);

        return $this->withHeaders(['X-App' => 'admin']);
    }

    public function test_the_web_panels_envelope_is_written(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/admin/settings', ['settings' => ['delivery.platform_commission_rate' => 20]])
            ->assertOk();

        $this->assertSame(20, Setting::query()->where('key', 'delivery.platform_commission_rate')->value('value'));
        // And it is read, not merely stored — the cache is busted.
        $this->assertSame(200.0, DeliveryCommission::platformShare(1000));
    }

    public function test_the_phone_consoles_flat_map_is_written(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/admin/settings', ['cart.max_restaurants' => 3])
            ->assertOk();

        $this->assertSame(3, SettingsCatalog::int('cart.max_restaurants'));
    }

    public function test_a_key_the_catalogue_does_not_know_is_refused_by_name(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/admin/settings', ['settings' => [
                'order.restaurant_response_minutes' => 5,
                'delivery.extra_stop_fee' => 15,
            ]])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonStructure(['errors' => ['order.restaurant_response_minutes']]);

        // Nothing of a refused edit is written — not even the valid half.
        $this->assertSame(10.0, SettingsCatalog::float('delivery.extra_stop_fee'));
    }

    public function test_a_value_out_of_range_is_refused_with_the_range(): void
    {
        $response = $this->asAdmin()
            ->patchJson('/api/v1/admin/settings', ['settings' => ['ring.duration_seconds' => 90]])
            ->assertStatus(422);

        $this->assertSame('أكبر قيمة مسموحة ‪60‬', $response->json('errors')['ring.duration_seconds'][0]);
    }

    public function test_the_last_payment_method_cannot_be_switched_off(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/admin/settings', ['settings' => [
                'payment.cash_enabled' => false,
                'payment.vodafone_cash_enabled' => false,
                'payment.instapay_enabled' => false,
            ]])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['payment.cash_enabled']]);
    }

    public function test_a_wallet_switched_on_needs_its_number(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/admin/settings', ['settings' => [
                'payment.vodafone_cash_enabled' => true,
                'payment.vodafone_cash_number' => '',
            ]])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['payment.vodafone_cash_number']]);
    }

    public function test_the_schema_names_every_group_and_carries_the_current_value(): void
    {
        $response = $this->asAdmin()->getJson('/api/v1/admin/settings/schema')->assertOk();

        $groups = collect($response->json('data'))->keyBy('key');

        $this->assertSame(
            ['delivery', 'cart', 'payment', 'ring', 'timeouts', 'dispatch', 'kitchen', 'errands', 'codes', 'otp', 'session', 'addresses', 'direct', 'catalog', 'support'],
            $groups->keys()->all(),
        );

        $cart = collect($groups['cart']['fields'])->keyBy('key');
        $this->assertSame(2, $cart['cart.max_restaurants']['default']);

        $required = collect($groups['addresses']['fields'])->firstWhere('key', 'addresses.required_fields');
        $this->assertSame(['street'], $required['value']);
        $this->assertSame('الشارع', $required['options'][0]['label_ar']);
    }

    /**
     * «كل النسب والأرقام يتحكم فيها من الادمن» — the dispatch, preparation and errand
     * numbers were read by the code and reachable from no screen.
     */
    public function test_every_number_the_code_reads_is_on_the_panel_with_the_seeded_value(): void
    {
        $fields = collect($this->asAdmin()->getJson('/api/v1/admin/settings/schema')->assertOk()->json('data'))
            ->flatMap(fn (array $group) => $group['fields'])
            ->keyBy('key');

        foreach ([
            'dispatch.radius_km' => 5, 'dispatch.batch_size' => 3, 'dispatch.max_rounds' => 3,
            'dispatch.offer_to_all_online' => true, 'dispatch.allow_neighbour_zones' => true,
            'dispatch.location_stale_seconds' => 90,
            // doc 63 — declared, not seeded: with no row each reads its default.
            'dispatch.one_captain_per_basket' => true, 'dispatch.batching_enabled' => true,
            'dispatch.max_orders_per_trip' => 3, 'dispatch.batch_radius_km' => 1.5,
            'restaurant.prep_minutes_min' => 5, 'restaurant.prep_minutes_max' => 120,
            'restaurant.prep_extension_max_minutes' => 15,
            'errands.base_fee' => 20, 'errands.per_km_fee' => 5, 'errands.min_fee' => 20,
            'platform.order_fee_refundable_on_platform_failure' => true,
        ] as $key => $seeded) {
            $this->assertTrue($fields->has($key), "{$key} is not on the panel");
            $this->assertEquals($seeded, $fields[$key]['value'], "{$key} reads a different value than the one seeded");
        }
    }

    public function test_an_edited_errand_fee_is_the_one_the_quote_charges(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/admin/settings', ['settings' => ['errands.base_fee' => 35, 'errands.min_fee' => 25]])
            ->assertOk();

        $this->seedLocation();

        // ‪1‬ km across the centre: ‪35 + 5‬ = ‪40‬ — the edited base, not the seeded ‪20‬.
        $quote = app(QuoteErrandAction::class)->execute(31.3833, 31.4833, 31.3923, 31.4833);

        $this->assertEquals(40, $quote['suggested_fee']);
        $this->assertEquals(25, $quote['min_fee']);
    }

    public function test_the_shortest_preparation_time_must_be_shorter_than_the_longest(): void
    {
        $response = $this->asAdmin()
            ->patchJson('/api/v1/admin/settings', ['restaurant.prep_minutes_min' => 50, 'restaurant.prep_minutes_max' => 40])
            ->assertStatus(422);

        // Each value is in its own range; it is the pair that is refused.
        $this->assertSame('يجب أن يكون أقل وقت تحضير أقصر من أطوله', $response->json('errors')['restaurant.prep_minutes_min'][0]);
    }

    public function test_a_key_with_no_row_reads_its_default_and_is_created_on_first_save(): void
    {
        Setting::query()->where('key', 'ring.gap_seconds')->delete();
        Settings::forget('ring.gap_seconds');

        $this->assertSame(60, SettingsCatalog::int('ring.gap_seconds'));

        $this->asAdmin()
            ->patchJson('/api/v1/admin/settings', ['settings' => ['ring.gap_seconds' => 90]])
            ->assertOk();

        $this->assertSame(90, Setting::query()->where('key', 'ring.gap_seconds')->value('value'));
    }

    public function test_a_multi_choice_is_stored_in_the_catalogues_order(): void
    {
        $this->asAdmin()
            ->patchJson('/api/v1/admin/settings', ['settings' => ['addresses.required_fields' => ['landmark', 'street']]])
            ->assertOk();

        $this->assertSame(['street', 'landmark'], SettingsCatalog::get('addresses.required_fields'));
    }

    public function test_the_gateway_panel_says_whether_a_message_would_arrive(): void
    {
        config([
            'services.otp_channel' => 'sms_misr',
            'services.sms_misr.username' => 'u',
            'services.sms_misr.password' => 'p',
            'services.sms_misr.sender' => 's',
            'services.sms_misr.environment' => '2',
        ]);

        Http::fake(['*' => Http::response(['code' => '6000', 'balance' => 12.5])]);

        $this->asAdmin()->getJson('/api/v1/admin/settings/sms-status')
            ->assertOk()
            ->assertJsonPath('data.environment', 'test')
            ->assertJsonPath('data.delivers', false)
            ->assertJsonPath('data.balance', 12.5);
    }
}
