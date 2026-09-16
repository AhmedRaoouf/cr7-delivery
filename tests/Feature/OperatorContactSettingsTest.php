<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\Setting;
use App\Models\User;
use App\Support\DeliveryCommission;
use Database\Seeders\OperatorSettingsSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The operator's real numbers, and the cache that used to swallow every later edit.
 *
 * Two separate failures live here. The first is a seeding rule: a correction must reach a
 * row that already holds a placeholder, and must **not** reach one an operator has since
 * typed over in `ADM-46`. The second is worse and was silent — `Settings::get` caches with
 * `rememberForever`, so a `PATCH /admin/settings` wrote the row, returned the new value,
 * and left every reader on the old one until somebody ran `cache:clear`. With the delivery
 * split now living in a setting, that meant an operator could move 15 → 20, watch the
 * screen agree, and keep paying the whole fleet at 15.
 */
class OperatorContactSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const PRIMARY = '01099450110';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);
    }

    /** `X-App: admin` — doc 12 §1.1 gates every admin route on it, header and all. */
    private function asAdmin(): TestResponse|\Illuminate\Foundation\Testing\TestCase
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($admin);

        return $this->withHeaders(['X-App' => 'admin']);
    }

    private function value(string $key): ?string
    {
        $stored = Setting::query()->where('key', $key)->value('value');

        return $stored === null ? null : (string) $stored;
    }

    // ------------------------------------------------------------- the numbers

    public function test_both_payment_destinations_carry_the_operators_number(): void
    {
        // `SettingsSeeder` ships `cr7delivery@instapay`, which is not an address:
        // nothing would arrive there. An Egyptian mobile number is a real InstaPay
        // destination, and the operator asked for one of his own until a dedicated
        // handle exists.
        $this->assertSame('cr7delivery@instapay', $this->value('payment.instapay_handle'));

        $this->seed(OperatorSettingsSeeder::class);

        $this->assertSame(self::PRIMARY, $this->value('payment.vodafone_cash_number'));
        $this->assertSame(self::PRIMARY, $this->value('payment.instapay_handle'));
    }

    public function test_a_number_the_operator_typed_himself_is_never_overwritten(): void
    {
        // `settings.value` is a JSON column with a cast on the model, so this goes
        // through the model — a query-builder update writes bare digits and MySQL
        // rejects them as invalid JSON.
        Setting::query()->where('key', 'payment.instapay_handle')->first()->update(['value' => '01234567890']);
        Setting::query()->where('key', 'support.phone')->first()->update(['value' => '01555000111']);

        $this->seed(OperatorSettingsSeeder::class);

        $this->assertSame('01234567890', $this->value('payment.instapay_handle'));
        $this->assertSame('01555000111', $this->value('support.phone'));
    }

    /**
     * 🔴 A field the operator **cleared** stays cleared through the next `db:seed`.
     *
     * `->value('value')` answers `null` both for a row that does not exist and for a row
     * holding null, and `replacePlaceholder` treated the two the same — so clearing
     * «رقم الدعم» on `ADM-46` lasted exactly until the next deploy, which runs a seed.
     * All four keys this seeder corrects are nullable, so all four could be reverted.
     */
    public function test_a_field_the_operator_cleared_stays_cleared_through_a_reseed(): void
    {
        foreach (['support.phone', 'support.whatsapp', 'payment.vodafone_cash_number', 'payment.instapay_handle'] as $key) {
            Setting::query()->where('key', $key)->first()->update(['value' => null]);
        }

        $this->seed(OperatorSettingsSeeder::class);

        foreach (['support.phone', 'support.whatsapp', 'payment.vodafone_cash_number', 'payment.instapay_handle'] as $key) {
            $this->assertNull($this->value($key), "{$key} was refilled over a deliberate blank");
        }
    }

    /**
     * 🔴 A deliberate `0` is a value, not a placeholder.
     *
     * `'0'` and `''` sat in `PLACEHOLDERS`, which is compared with `(string) $current` —
     * so a zero an operator typed matched «nobody has set this yet» and was overwritten.
     */
    public function test_a_zero_the_operator_typed_is_not_treated_as_unset(): void
    {
        Setting::query()->where('key', 'payment.instapay_handle')->first()->update(['value' => '0']);

        $this->seed(OperatorSettingsSeeder::class);

        $this->assertSame('0', $this->value('payment.instapay_handle'));
    }

    /**
     * 🔴 The captain-earning defaults were `force()`d on every run.
     *
     * They are a migration off the pre-D-70 values, not a standing instruction: an
     * operator who moved the default onto a fixed amount had D-70's numbers put back by
     * the next deploy, silently, while the panel still showed what they had chosen until
     * the page was reloaded.
     */
    public function test_an_earning_default_the_operator_changed_survives_a_reseed(): void
    {
        $this->seed(OperatorSettingsSeeder::class);

        foreach (['driver.default_earning_type' => 'fixed', 'driver.default_earning_value' => 25] as $key => $chosen) {
            Setting::query()->where('key', $key)->first()->update(['value' => $chosen]);
        }

        $this->seed(OperatorSettingsSeeder::class);

        $this->assertSame('fixed', $this->value('driver.default_earning_type'));
        $this->assertSame('25', $this->value('driver.default_earning_value'));
    }

    /** …and the pre-D-70 pair is still migrated, which is what the forces were for. */
    public function test_the_pre_d70_earning_default_is_still_migrated(): void
    {
        Setting::query()->updateOrCreate(['key' => 'driver.default_earning_type'], ['value' => 'fixed', 'group' => 'driver']);
        Setting::query()->updateOrCreate(['key' => 'driver.default_earning_value'], ['value' => 15, 'group' => 'driver']);

        $this->seed(OperatorSettingsSeeder::class);

        $this->assertSame('percent_of_delivery_fee', $this->value('driver.default_earning_type'));
        $this->assertSame('0', $this->value('driver.default_earning_value'));
    }

    public function test_config_publishes_the_second_number_and_the_hours_line(): void
    {
        $this->seed(OperatorSettingsSeeder::class);

        $response = $this->getJson('/api/v1/config')->assertOk();

        $this->assertSame(self::PRIMARY, $response->json('data.support.phone'));
        $this->assertSame('01095958686', $response->json('data.support.phone_alt'));
        $this->assertSame('متواجدون لخدمتكم ‪24‬ ساعة', $response->json('data.support.hours_note'));
        $this->assertSame(self::PRIMARY, $response->json('data.payment.instapay_handle'));
    }

    // --------------------------------------------------------------- the cache

    public function test_changing_the_split_from_the_dashboard_takes_effect_immediately(): void
    {
        $this->seed(OperatorSettingsSeeder::class);

        // Read once so the 15 is cached — this is what the running app does.
        $this->assertSame(15.0, DeliveryCommission::rate());
        $this->assertSame(150.0, DeliveryCommission::platformShare(1000));

        $this->asAdmin()
            ->patchJson('/api/v1/admin/settings', [DeliveryCommission::SETTING => 20])
            ->assertOk();

        // Without `Settings::forget` in the controller this is still 15.
        $this->assertSame(20.0, DeliveryCommission::rate());
        $this->assertSame(200.0, DeliveryCommission::platformShare(1000));
        $this->assertSame(800.0, DeliveryCommission::driverShare(1000));
    }
}
