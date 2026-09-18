<?php

namespace Tests\Feature\Security;

use App\Actions\Customer\CreateOrderAction;
use App\Enums\AdminScopeType;
use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\Order;
use App\Models\User;
use App\Providers\TelescopeServiceProvider;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Laravel\Telescope\Telescope;
use Tests\TestCase;

/**
 * Batch 67 — the rules a hand-written request could step around.
 */
class BusinessRuleHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);
    }

    public function test_a_captain_cannot_reopen_a_finished_order_as_a_delivery_issue(): void
    {
        $order = $this->order();
        $driver = $this->driver();
        $order->update(['status' => OrderStatus::Delivered, 'driver_id' => $driver->id, 'delivered_at' => now()]);

        Sanctum::actingAs($driver->user);

        // `POST /driver/orders/{id}/issue` moved *any* order the captain had ever
        // carried to `delivery_issue` — a delivered one, a cancelled one — which
        // reopened the basket on every board and in the customer's history.
        $this->withHeaders(['X-App' => 'app'])
            ->postJson("/api/v1/driver/orders/{$order->id}/issue", ['category' => 'other'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_transition');

        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    public function test_a_captain_can_still_report_a_problem_on_the_road(): void
    {
        $order = $this->order();
        $driver = $this->driver();
        $order->update(['status' => OrderStatus::OnTheWay, 'driver_id' => $driver->id]);

        Sanctum::actingAs($driver->user);

        $this->withHeaders(['X-App' => 'app'])
            ->postJson("/api/v1/driver/orders/{$order->id}/issue", [
                'category' => 'customer_unreachable',
                'attachments' => ['javascript:alert(1)'],
            ])
            ->assertStatus(422);

        $this->withHeaders(['X-App' => 'app'])
            ->postJson("/api/v1/driver/orders/{$order->id}/issue", ['category' => 'customer_unreachable'])
            ->assertCreated();

        $this->assertSame(OrderStatus::DeliveryIssue, $order->fresh()->status);
    }

    public function test_a_wrong_delivery_code_is_counted_even_when_it_throws(): void
    {
        $order = $this->order();
        $driver = $this->driver();
        $order->update([
            'status' => OrderStatus::ArrivedCustomer,
            'driver_id' => $driver->id,
            'delivery_otp' => '4321',
            'delivery_code_required' => true,
        ]);

        Sanctum::actingAs($driver->user);
        $limit = \App\Support\SettingsCatalog::int('order.otp_max_attempts');

        for ($i = 0; $i < $limit; $i++) {
            $this->withHeaders(['X-App' => 'app'])
                ->postJson("/api/v1/driver/orders/{$order->id}/deliver", ['otp' => '0000'])
                ->assertStatus(422);
        }

        // The right code after the limit is still refused: the counter held.
        $this->withHeaders(['X-App' => 'app'])
            ->postJson("/api/v1/driver/orders/{$order->id}/deliver", ['otp' => '4321'])
            ->assertStatus(423)
            ->assertJsonPath('error', 'delivery_otp_locked');

        $this->assertSame($limit, (int) $order->fresh()->delivery_otp_attempts);
        $this->assertSame(OrderStatus::ArrivedCustomer, $order->fresh()->status);
    }

    public function test_a_refund_can_never_exceed_what_the_order_cost(): void
    {
        $order = $this->order();
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($admin);

        $this->withHeaders(['X-App' => 'admin'])->postJson('/api/v1/admin/refunds', [
            'order_id' => $order->id,
            'amount' => 1_000_000,
            'type' => 'partial',
        ])->assertStatus(422)->assertJsonPath('error', 'refund_exceeds_order');

        $this->withHeaders(['X-App' => 'admin'])->postJson('/api/v1/admin/refunds', [
            'order_id' => $order->id,
            'amount' => (float) $order->total,
            'type' => 'full',
        ])->assertCreated();

        // …and the second one, on top of the first, is refused too.
        $this->withHeaders(['X-App' => 'admin'])->postJson('/api/v1/admin/refunds', [
            'order_id' => $order->id,
            'amount' => 1,
            'type' => 'partial',
        ])->assertStatus(422)->assertJsonPath('error', 'refund_exceeds_order');
    }

    public function test_telescope_never_stores_a_token_a_code_or_a_password(): void
    {
        (new TelescopeServiceProvider($this->app))->register();

        foreach (['data.token', 'data.refresh_token', 'data.registration_token', 'data.static_otp', 'data.temp_password', 'data.pickup_otp'] as $key) {
            $this->assertContains($key, Telescope::$hiddenResponseParameters, $key);
        }

        foreach (['refresh_token', 'registration_token', 'password', 'current_password', 'code', 'otp'] as $key) {
            $this->assertContains($key, Telescope::$hiddenRequestParameters, $key);
        }
    }

    // ------------------------------------------------------------------ helpers

    private function order(): Order
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $this->makeCart($customer, [[$restaurant, $this->makeProduct($restaurant, 'سندوتش', 100.0), 1]]);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid('', true),
        );

        return Order::query()->where('order_group_id', $group->id)->firstOrFail();
    }

    private function driver(): Driver
    {
        $user = User::factory()->create(['type' => 'driver']);

        return Driver::query()->create([
            'user_id' => $user->id,
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'earning_type' => 'fixed',
            'earning_value' => 15,
            'is_active' => true,
            'is_online' => true,
            'is_available' => false,
        ]);
    }
}
