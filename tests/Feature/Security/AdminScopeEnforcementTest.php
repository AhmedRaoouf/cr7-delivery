<?php

namespace Tests\Feature\Security;

use App\Actions\Customer\CreateOrderAction;
use App\Enums\AdminScopeType;
use App\Models\City;
use App\Models\Complaint;
use App\Models\DeliveryIssue;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\ScheduledOrder;
use App\Models\ServiceZone;
use App\Models\Settlement;
use App\Models\User;
use App\Support\AdminScope;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batch 67 — D-50…D-52, «a permission says *what*, a geographic scope says *whose
 * rows*, and both must pass — out of scope returns 404, never 403».
 *
 * The scope was enforced on orders, restaurants, captains, customers, cities, zones,
 * pricing rules, errands and captain applications. It was **not** enforced on
 * complaints, payments and their proof files, refunds, settlements, delivery issues,
 * captain documents, a restaurant's menu, order groups, scheduled orders, account
 * moderation, the direct-order ratio, and five of the reports — so an operator scoped to
 * one city could read and act on every other city's rows through those routes. And the
 * rows doc 12 §10.1 makes global-only (users, roles, settings, the audit log) answered
 * any admin whose role carried the permission, including the scope route itself.
 *
 * Every request below is made by an operator holding **every permission** there is,
 * scoped to a second city, against a row in the first city.
 */
class AdminScopeEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private City $otherCity;

    private ServiceZone $otherZone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);
        Storage::fake('public');
        Storage::fake('local');

        $this->otherCity = City::query()->create([
            'name_ar' => 'المنصورة الجديدة',
            'name_en' => 'New Mansoura',
            'center_lat' => 31.45,
            'center_lng' => 31.46,
            'pricing_type' => 'fixed_zone',
            'default_min_order' => 50,
            'is_active' => true,
        ]);

        $this->otherZone = $this->otherCity->serviceZones()->create([
            'name_ar' => 'الحي الأول',
            'name_en' => 'District 1',
            'center_lat' => 31.45,
            'center_lng' => 31.46,
            'radius_km' => 3,
            'delivery_fee' => 20,
            'min_order_value' => 0,
            'avg_delivery_minutes' => 30,
            'is_active' => true,
        ]);
    }

    public function test_an_operator_scoped_to_another_city_gets_404_on_every_kind_of_row(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $order = $this->orderAt($restaurant);
        $product = Product::query()->where('restaurant_id', $restaurant->id)->firstOrFail();
        $payment = Payment::query()->where('order_group_id', $order->order_group_id)->firstOrFail();
        $complaint = $this->complaintOn($order);
        $issue = DeliveryIssue::query()->create([
            'order_id' => $order->id,
            'reported_by' => 'driver',
            'category' => 'other',
            'status' => 'open',
        ]);
        $driver = $this->driverIn($this->zone);
        $document = DriverDocument::query()->create([
            'driver_id' => $driver->id,
            'type' => 'national_id',
            'file' => 'driver-documents/x.jpg',
            'status' => 'pending',
        ]);
        $settlement = Settlement::query()->create([
            'party_type' => 'restaurant',
            'party_id' => $restaurant->id,
            'period_start' => now()->subWeek()->toDateString(),
            'period_end' => now()->toDateString(),
            'status' => 'pending',
        ]);
        $scheduled = ScheduledOrder::query()->create([
            'reference' => 'SCH-TEST-1',
            'user_id' => $order->user_id,
            'address_id' => $order->orderGroup->address_id,
            'scheduled_for' => now()->addDay(),
            'status' => ScheduledOrder::STATUS_SCHEDULED,
            'payload' => [],
            'basket_snapshot' => [],
        ]);
        $customer = User::query()->findOrFail($order->user_id);

        $this->actAs($this->scopedOperator());

        // Rows reached by id.
        $this->getJson("/api/v1/admin/orders/{$order->id}")->assertNotFound();
        $this->getJson("/api/v1/admin/complaints/{$complaint->id}")->assertNotFound();
        $this->patchJson("/api/v1/admin/complaints/{$complaint->id}", ['status' => 'resolved'])->assertNotFound();
        $this->postJson("/api/v1/admin/complaints/{$complaint->id}/messages", ['message' => 'x'])->assertNotFound();
        $this->postJson("/api/v1/admin/complaints/{$complaint->id}/assign", ['assigned_to' => $customer->id])->assertNotFound();
        $this->postJson("/api/v1/admin/complaints/{$complaint->id}/escalate", ['priority' => 'high'])->assertNotFound();
        $this->getJson("/api/v1/admin/payments/{$payment->id}")->assertNotFound();
        $this->postJson("/api/v1/admin/payments/{$payment->id}/approve")->assertNotFound();
        $this->postJson("/api/v1/admin/payments/{$payment->id}/reject", ['reason' => 'x'])->assertNotFound();
        $this->postJson('/api/v1/admin/refunds', [
            'order_id' => $order->id,
            'amount' => 10,
            'type' => 'partial',
            'reason_code' => 'other',
        ])->assertNotFound();
        $this->getJson("/api/v1/admin/settlements/{$settlement->id}")->assertNotFound();
        $this->getJson("/api/v1/admin/settlements/{$settlement->id}/export")->assertNotFound();
        $this->postJson("/api/v1/admin/settlements/{$settlement->id}/pay")->assertNotFound();
        $this->postJson("/api/v1/admin/delivery-issues/{$issue->id}/resolve", ['resolution_note' => 'x'])->assertNotFound();
        $this->getJson("/api/v1/admin/drivers/{$driver->id}/documents")->assertNotFound();
        $this->postJson("/api/v1/admin/driver-documents/{$document->id}/review", ['status' => 'approved'])->assertNotFound();
        $this->getJson("/api/v1/admin/driver-documents/{$document->id}/file")->assertNotFound();
        $this->getJson("/api/v1/admin/restaurants/{$restaurant->id}/products")->assertNotFound();
        $this->getJson("/api/v1/admin/restaurants/{$restaurant->id}/categories")->assertNotFound();
        $this->getJson("/api/v1/admin/restaurants/{$restaurant->id}/direct-order-ratio")->assertNotFound();
        $this->getJson("/api/v1/admin/products/{$product->id}/option-groups")->assertNotFound();
        $this->postJson("/api/v1/admin/order-groups/{$order->order_group_id}/recalculate")->assertNotFound();
        $this->getJson("/api/v1/admin/scheduled-orders/{$scheduled->id}")->assertNotFound();
        $this->postJson("/api/v1/admin/scheduled-orders/{$scheduled->id}/cancel")->assertNotFound();
        $this->postJson("/api/v1/admin/accounts/{$customer->id}/block", ['reason' => 'x'])->assertNotFound();
        $this->postJson("/api/v1/admin/accounts/{$restaurant->user_id}/reset")->assertNotFound();
        $this->postJson('/api/v1/admin/settlements/generate', [
            'party_type' => 'restaurant',
            'party_id' => $restaurant->id,
            'period_start' => now()->subWeek()->toDateString(),
            'period_end' => now()->toDateString(),
        ])->assertNotFound();

        // Lists and counts answer only for the operator's own city.
        $this->assertSame([], $this->getJson('/api/v1/admin/complaints')->assertOk()->json('data'));
        $this->assertSame([], $this->getJson('/api/v1/admin/payments')->assertOk()->json('data'));
        $this->assertSame([], $this->getJson('/api/v1/admin/settlements')->assertOk()->json('data'));
        $this->assertSame([], $this->getJson('/api/v1/admin/delivery-issues')->assertOk()->json('data'));
        $this->assertSame([], $this->getJson('/api/v1/admin/scheduled-orders')->assertOk()->json('data'));
        $this->assertSame(0, (int) $this->getJson('/api/v1/admin/complaints/sla')->assertOk()->json('data.within')
            + (int) $this->getJson('/api/v1/admin/complaints/sla')->json('data.due_soon'));

        // Nothing moved.
        $this->assertSame('open', $complaint->fresh()->status->value);
        $this->assertSame('pending', $document->fresh()->status->value ?? $document->fresh()->status);
        $this->assertTrue((bool) $customer->fresh()->is_active);
    }

    public function test_the_same_operator_still_works_inside_their_own_city(): void
    {
        $restaurant = $this->makeRestaurant('مطعم المنصورة');
        $order = $this->orderAt($restaurant, inOtherCity: true);
        $complaint = $this->complaintOn($order);
        $payment = Payment::query()->where('order_group_id', $order->order_group_id)->firstOrFail();

        $this->actAs($this->scopedOperator());

        $this->getJson("/api/v1/admin/orders/{$order->id}")->assertOk();
        $this->getJson("/api/v1/admin/complaints/{$complaint->id}")->assertOk();
        $this->getJson("/api/v1/admin/payments/{$payment->id}")->assertOk();
        $this->getJson("/api/v1/admin/restaurants/{$restaurant->id}/products")->assertOk();
        $this->assertCount(1, $this->getJson('/api/v1/admin/complaints')->assertOk()->json('data'));
    }

    public function test_the_global_only_surfaces_answer_404_to_a_scoped_operator(): void
    {
        $operator = $this->scopedOperator();
        $this->actAs($operator);

        $this->getJson('/api/v1/admin/users')->assertNotFound();
        $this->getJson("/api/v1/admin/users/{$operator->id}")->assertNotFound();
        // The escalation this closes: a scoped operator widening their own scope.
        $this->postJson("/api/v1/admin/users/{$operator->id}/scope", ['scope_type' => 'global'])->assertNotFound();
        $this->getJson('/api/v1/admin/roles')->assertNotFound();
        $this->getJson('/api/v1/admin/permissions')->assertNotFound();
        $this->getJson('/api/v1/admin/settings')->assertNotFound();
        $this->patchJson('/api/v1/admin/settings', ['settings' => []])->assertNotFound();
        $this->getJson('/api/v1/admin/audit-logs')->assertNotFound();
        $this->postJson('/api/v1/admin/notifications/broadcast', [
            'audience' => 'all',
            'title_ar' => 'x', 'title_en' => 'x', 'body_ar' => 'x', 'body_en' => 'x',
        ])->assertNotFound();

        $this->assertFalse(AdminScope::isGlobal($operator->fresh()));
    }

    public function test_an_admin_cannot_take_over_a_more_privileged_admin(): void
    {
        $boss = User::factory()->create(['type' => 'admin', 'phone' => '01000000011']);
        $boss->assignRole('super_admin');
        $boss->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        // A global operator whose role carries user management but not everything
        // `super_admin` holds — the clone ADM-50 makes in one click.
        $role = Role::query()->create(['name' => 'people_ops', 'guard_name' => 'web', 'label_ar' => 'الأفراد', 'label_en' => 'People']);
        $role->syncPermissions(['users.view', 'users.manage', 'users.suspend', 'roles.manage', 'scope.manage']);
        $operator = User::factory()->create(['type' => 'admin', 'phone' => '01000000012']);
        $operator->assignRole('people_ops');
        $operator->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        $this->actAs($operator);

        // Before: a temporary password for the super admin, handed to the operator.
        $this->postJson("/api/v1/admin/users/{$boss->id}/reset-password")
            ->assertStatus(403)->assertJsonPath('error', 'permission_escalation');
        $this->postJson("/api/v1/admin/users/{$boss->id}/suspend", ['reason' => 'x'])
            ->assertStatus(403)->assertJsonPath('error', 'permission_escalation');
        $this->deleteJson("/api/v1/admin/users/{$boss->id}")
            ->assertStatus(403)->assertJsonPath('error', 'permission_escalation');
        $this->patchJson("/api/v1/admin/users/{$boss->id}", ['name' => 'x'])
            ->assertStatus(403)->assertJsonPath('error', 'permission_escalation');
        $this->postJson("/api/v1/admin/users/{$boss->id}/scope", ['scope_type' => 'city', 'city_ids' => [$this->city->id]])
            ->assertStatus(403);

        // Nor can anybody move their own scope.
        $this->postJson("/api/v1/admin/users/{$operator->id}/scope", ['scope_type' => 'global'])
            ->assertStatus(422)->assertJsonPath('error', 'cannot_modify_self');

        $this->assertTrue((bool) $boss->fresh()->is_active);
        $this->assertTrue(AdminScope::isGlobal($boss->fresh()));
    }

    public function test_a_scope_names_cities_and_zones_that_exist(): void
    {
        $boss = User::factory()->create(['type' => 'admin', 'phone' => '01000000013']);
        $boss->assignRole('super_admin');
        $boss->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        $target = User::factory()->create(['type' => 'admin', 'phone' => '01000000014']);
        $target->assignRole('support');

        $this->actAs($boss);

        $this->postJson("/api/v1/admin/users/{$target->id}/scope", ['scope_type' => 'city', 'city_ids' => [987654]])
            ->assertStatus(422);
        $this->postJson("/api/v1/admin/users/{$target->id}/scope", ['scope_type' => 'city', 'city_ids' => [$this->city->id]])
            ->assertOk();
    }

    public function test_a_revoke_that_cannot_take_effect_is_refused_and_named(): void
    {
        $boss = User::factory()->create(['type' => 'admin', 'phone' => '01000000015']);
        $boss->assignRole('super_admin');
        $boss->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        $target = User::factory()->create(['type' => 'admin', 'phone' => '01000000016']);
        $target->assignRole('operations');

        $this->actAs($boss);

        // `orders.cancel` comes from the role. Revoking it directly used to answer 200
        // and leave the operator able to cancel orders — a control that reported
        // success and did nothing (D-51: «never a silent trim»).
        $this->postJson("/api/v1/admin/users/{$target->id}/permissions", ['revokes' => ['orders.cancel']])
            ->assertStatus(422)
            ->assertJsonPath('error', 'permission_held_by_role')
            ->assertJsonPath('meta.permissions', ['orders.cancel']);

        $this->assertTrue($target->fresh()->can('orders.cancel'));
    }

    public function test_internal_notes_and_mirroring_need_their_own_permissions(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $complaint = $this->complaintOn($this->orderAt($restaurant));

        // `operations` holds complaints.reply and complaints.update, not
        // complaints.note_internal or complaints.mirror (doc 12 §4).
        $operator = User::factory()->create(['type' => 'admin', 'phone' => '01000000017']);
        $operator->assignRole('operations');
        $operator->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        $this->actAs($operator);

        $this->postJson("/api/v1/admin/complaints/{$complaint->id}/messages", [
            'message' => 'ملاحظة داخلية',
            'visibility' => 'internal',
        ])->assertStatus(403);

        $this->patchJson("/api/v1/admin/complaints/{$complaint->id}", ['visible_to_restaurant' => false])
            ->assertStatus(403);

        // …and a ticket is assigned to an operator, never to a customer.
        $this->postJson("/api/v1/admin/complaints/{$complaint->id}/assign", ['assigned_to' => $complaint->user_id])
            ->assertStatus(422);

        $this->postJson("/api/v1/admin/complaints/{$complaint->id}/messages", ['message' => 'رد للعميل'])
            ->assertCreated();
    }

    // ------------------------------------------------------------------ helpers

    private function scopedOperator(): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'city_boss', 'guard_name' => 'web'],
            ['label_ar' => 'مدير مدينة', 'label_en' => 'City boss'],
        );
        $role->syncPermissions(Permission::query()->pluck('name')->all());

        $operator = User::factory()->create(['type' => 'admin']);
        $operator->assignRole('city_boss');
        $operator->adminScopes()->create(['scope_type' => AdminScopeType::City, 'city_id' => $this->otherCity->id]);

        return $operator;
    }

    private function orderAt(Restaurant $restaurant, bool $inOtherCity = false): Order
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $this->makeCart($customer, [[$restaurant, $this->makeProduct($restaurant, 'سندوتش', 100.0), 1]]);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid('', true),
        );

        $order = Order::query()->where('order_group_id', $group->id)->firstOrFail();

        if ($inOtherCity) {
            // Placed where the coverage geometry allows, then filed under the second
            // city — the scope reads the row's own columns, not the map.
            $order->update(['city_id' => $this->otherCity->id, 'service_zone_id' => $this->otherZone->id]);
            $address->update(['city_id' => $this->otherCity->id, 'service_zone_id' => $this->otherZone->id]);
            $restaurant->update(['city_id' => $this->otherCity->id, 'service_zone_id' => $this->otherZone->id]);
        }

        return $order;
    }

    private function complaintOn(Order $order): Complaint
    {
        return Complaint::query()->create([
            'ticket_number' => 'CMP-'.uniqid(),
            'user_id' => $order->user_id,
            'user_role' => 'customer',
            'order_id' => $order->id,
            'order_group_id' => $order->order_group_id,
            'restaurant_id' => $order->restaurant_id,
            'category' => 'food_quality',
            'description' => 'الطعام بارد',
            'status' => 'open',
            'priority' => 'normal',
            'visible_to_restaurant' => true,
            'due_at' => now()->addDay(),
        ]);
    }

    private function driverIn(ServiceZone $zone): Driver
    {
        $user = User::factory()->create(['type' => 'driver']);
        $driver = Driver::query()->create([
            'user_id' => $user->id,
            'city_id' => $zone->city_id,
            'vehicle_type' => 'motorcycle',
            'earning_type' => 'fixed',
            'earning_value' => 15,
            'is_active' => true,
        ]);
        $driver->assignedZones()->attach($zone->id);

        return $driver;
    }

    private function actAs(User $user): void
    {
        Sanctum::actingAs($user);
        $this->withHeader('X-App', 'admin');
    }
}
