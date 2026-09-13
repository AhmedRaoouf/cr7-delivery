<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\Setting;
use App\Models\User;
use App\Support\DeliveryCommission;
use App\Support\SettingsCatalog;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ['delivery', 'cart', 'payment', 'ring', 'timeouts', 'codes', 'otp', 'addresses', 'direct', 'support'],
            $groups->keys()->all(),
        );

        $cart = collect($groups['cart']['fields'])->keyBy('key');
        $this->assertSame(2, $cart['cart.max_restaurants']['default']);

        $required = collect($groups['addresses']['fields'])->firstWhere('key', 'addresses.required_fields');
        $this->assertSame(['street'], $required['value']);
        $this->assertSame('الشارع', $required['options'][0]['label_ar']);
    }

    public function test_every_catalogue_key_with_a_default_has_a_row_after_the_migration(): void
    {
        // A key with no default — a wallet number, a support line — is the operator's to
        // write; `PATCH` creates its row on first save.
        $missing = collect(SettingsCatalog::fields())
            ->reject(fn (array $field) => $field['default'] === null)
            ->keys()
            ->reject(fn (string $key) => Setting::query()->where('key', $key)->exists())
            ->values()
            ->all();

        $this->assertSame([], $missing);
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

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['code' => '6000', 'balance' => 12.5])]);

        $this->asAdmin()->getJson('/api/v1/admin/settings/sms-status')
            ->assertOk()
            ->assertJsonPath('data.environment', 'test')
            ->assertJsonPath('data.delivers', false)
            ->assertJsonPath('data.balance', 12.5);
    }
}
