<?php

namespace Tests\Feature;

use App\Actions\Admin\ModerateAccountAction;
use App\Actions\Admin\OfferOrderToDriverAction;
use App\Actions\Customer\CreateComplaintAction;
use App\Actions\Customer\CreateOrderAction;
use App\Actions\Driver\AcceptOfferAction;
use App\Actions\Driver\RejectOfferAction;
use App\Enums\AdminScopeType;
use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Models\Complaint;
use App\Models\Driver;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\DispatchService;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The 2026-09-08 pass, from the operator's own list.
 *
 * Four things that were all «built» and none of which reached a person:
 *
 * 1. An admin assignment wrote `result = accepted` on the captain's behalf and told
 *    nobody. Now it is an offer, and a refusal comes back to whoever sent it.
 * 2. `users.is_active` was read at login and nowhere else, so blocking an account that
 *    was already signed in blocked nothing.
 * 3. Moderation existed for admin accounts only — the one population that needed it
 *    least — and 404'd on a customer or a captain.
 * 4. A complaint was a row that sat there until somebody happened to open `ADM-41`.
 */
class AssignmentAndModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class]);

        foreach ([
            'dispatch.batch_size' => 3,
            'dispatch.radius_km' => 5,
            'dispatch.radius_expand_km' => 2,
            'dispatch.max_rounds' => 3,
            'dispatch.location_stale_seconds' => 90,
        ] as $key => $value) {
            Setting::query()->create(['key' => $key, 'value' => $value, 'group' => 'test']);
        }
    }

    // ------------------------------------------------------------ assignment

    public function test_an_admin_assignment_arrives_as_an_offer_the_captain_can_refuse(): void
    {
        [$order, $driver, $admin] = $this->scenario();

        app(OfferOrderToDriverAction::class)->execute($admin, $order, $driver->id, 'nearest', null);

        $fresh = $order->fresh();

        // Not assigned — offered. The captain's «قبول» has to happen first.
        $this->assertNull($fresh->driver_id);
        $this->assertSame(OrderStatus::SearchingDriver, $fresh->status);

        $this->assertDatabaseHas('order_driver_attempts', [
            'order_id' => $order->id,
            'driver_id' => $driver->id,
            'result' => 'sent',
            'assigned_by' => $admin->id,
            // The 2026-09-08 rule: it waits for an answer, not for a clock.
            'expires_at' => null,
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $driver->user_id,
            'type' => 'offer.assigned',
        ]);
    }

    public function test_a_refused_assignment_goes_back_to_the_operator_who_sent_it(): void
    {
        [$order, $driver, $admin] = $this->scenario();

        app(OfferOrderToDriverAction::class)->execute($admin, $order, $driver->id, 'nearest', null);
        app(RejectOfferAction::class)->execute($driver->fresh(), $order->id, 'too_far');

        $this->assertDatabaseHas('order_driver_attempts', [
            'order_id' => $order->id,
            'driver_id' => $driver->id,
            'result' => 'rejected',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => 'offer.assignment_declined',
        ]);
    }

    /** An ordinary round has no `assigned_by`, so a refusal must not page anybody. */
    public function test_refusing_an_ordinary_offer_pages_nobody(): void
    {
        [$order, $driver, $admin] = $this->scenario();

        app(DispatchService::class)->dispatchRound($order->fresh(), 1);
        app(RejectOfferAction::class)->execute($driver->fresh(), $order->id, 'too_far');

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $admin->id,
            'type' => 'offer.assignment_declined',
        ]);
    }

    public function test_the_endpoint_offers_by_default_and_forces_only_when_asked(): void
    {
        [$order, $driver, $admin] = $this->scenario();

        $this->actingAs($admin)
            ->withHeaders(['X-App' => 'admin'])
            ->postJson("/api/v1/admin/orders/{$order->id}/reassign-driver", [
                'driver_id' => $driver->id,
                'reason_code' => 'nearest',
            ])
            ->assertOk();

        $this->assertNull($order->fresh()->driver_id);

        $this->actingAs($admin)
            ->withHeaders(['X-App' => 'admin'])
            ->postJson("/api/v1/admin/orders/{$order->id}/reassign-driver", [
                'driver_id' => $driver->id,
                'reason_code' => 'nearest',
                'mode' => 'force',
            ])
            ->assertOk();

        $this->assertSame($driver->id, $order->fresh()->driver_id);
    }

    /**
     * doc 95 — a second «إسناد» to the captain who already accepted. The offer path used
     * to clear `driver_id` and put the order back to `searching_driver`; the force path
     * marked their own acceptance `reassigned_by_admin` and changed the pickup code.
     */
    public function test_assigning_the_captain_who_already_carries_it_changes_nothing(): void
    {
        [$order, $driver, $admin] = $this->scenario();

        app(OfferOrderToDriverAction::class)->execute($admin, $order, $driver->id, 'nearest', null);
        app(AcceptOfferAction::class)->execute($driver->fresh(), $order->id);

        $before = $order->fresh();
        $this->assertSame($driver->id, $before->driver_id);

        foreach (['offer', 'force'] as $mode) {
            $this->actingAs($admin)
                ->withHeaders(['X-App' => 'admin'])
                ->postJson("/api/v1/admin/orders/{$order->id}/reassign-driver", [
                    'driver_id' => $driver->id,
                    'reason_code' => 'nearest',
                    'mode' => $mode,
                ])
                ->assertOk();

            $after = $order->fresh();
            $this->assertSame($driver->id, $after->driver_id, $mode);
            $this->assertSame($before->status, $after->status, $mode);
            $this->assertSame($before->pickup_otp, $after->pickup_otp, $mode);
        }

        $this->assertDatabaseHas('order_driver_attempts', [
            'order_id' => $order->id,
            'driver_id' => $driver->id,
            'result' => 'accepted',
        ]);
        $this->assertDatabaseMissing('order_driver_attempts', [
            'order_id' => $order->id,
            'result' => 'reassigned_by_admin',
        ]);
    }

    // ------------------------------------------------------------ moderation

    public function test_a_blocked_account_is_refused_on_the_very_next_request(): void
    {
        $customer = User::factory()->create(['type' => UserType::Customer, 'is_active' => true]);
        $admin = User::factory()->create(['type' => UserType::Admin, 'is_active' => true]);

        // Signed in and working.
        $this->actingAs($customer)->withHeaders(['X-App' => 'app'])
            ->getJson('/api/v1/notifications')->assertOk();

        app(ModerateAccountAction::class)->block($admin, $customer, 'إساءة متكررة للمطاعم');

        // 423, which is the one status the app maps to «sign out and say why».
        $this->actingAs($customer->fresh())->withHeaders(['X-App' => 'app'])
            ->getJson('/api/v1/notifications')
            ->assertStatus(423)
            ->assertJsonPath('error', 'account_suspended')
            ->assertJsonFragment(['message' => 'تم إيقاف حسابك — السبب: إساءة متكررة للمطاعم']);
    }

    public function test_blocking_a_captain_takes_them_off_the_dispatch_pool(): void
    {
        [$order, $driver, $admin] = $this->scenario();

        app(ModerateAccountAction::class)->block($admin, $driver->user, 'مخالفة');

        $fresh = $driver->fresh();
        $this->assertFalse((bool) $fresh->is_online);
        // `is_available` means «carries nothing» since doc 63 (D-117), and a suspended
        // captain with no trip carries nothing. Going offline is what takes them off the
        // pool — and the round below proves it.
        $this->assertTrue((bool) $fresh->is_available);
        $this->assertSame(0, $driver->user->tokens()->count());

        app(DispatchService::class)->dispatchRound($order, 1);
        $this->assertSame(0, $order->driverAttempts()->where('driver_id', $driver->id)->count());
    }

    /**
     * doc 63 §7 — «on unblock the captain is dispatchable again». `block()` wrote
     * `is_available = false`, `unblock()` never wrote it back, and every dispatch query
     * required it — so a captain who had been suspended once was invisible to dispatch for
     * good, however many times they went online.
     */
    public function test_an_unblocked_captain_is_dispatchable_again(): void
    {
        [$order, $driver, $admin] = $this->scenario();

        app(ModerateAccountAction::class)->block($admin, $driver->user, 'مخالفة');
        app(ModerateAccountAction::class)->unblock($admin, $driver->user->fresh());

        // They sign in again and go on shift.
        $this->actingAs($driver->user->fresh())->withHeaders(['X-App' => 'app'])
            ->postJson('/api/v1/driver/status', ['is_online' => true])
            ->assertOk();

        app(DispatchService::class)->dispatchRound($order->fresh(), 1);

        $this->assertDatabaseHas('order_driver_attempts', [
            'order_id' => $order->id,
            'driver_id' => $driver->id,
            'result' => 'sent',
        ]);
    }

    public function test_moderation_reaches_a_customer_which_the_admin_only_route_never_did(): void
    {
        $customer = User::factory()->create(['type' => UserType::Customer, 'is_active' => true]);
        $admin = $this->operator(['users.suspend']);

        // The old route: admin accounts only.
        $this->actingAs($admin)->withHeaders(['X-App' => 'admin'])
            ->postJson("/api/v1/admin/users/{$customer->id}/suspend", ['reason' => 'x'])
            ->assertNotFound();

        $this->actingAs($admin)->withHeaders(['X-App' => 'admin'])
            ->postJson("/api/v1/admin/accounts/{$customer->id}/block", ['reason' => 'إساءة'])
            ->assertOk();

        $this->assertFalse((bool) $customer->fresh()->is_active);
    }

    public function test_deleting_an_account_frees_the_phone_number_for_a_fresh_start(): void
    {
        $customer = User::factory()->create([
            'type' => UserType::Customer,
            'phone' => '01099887766',
            'is_active' => true,
        ]);
        $admin = User::factory()->create(['type' => UserType::Admin, 'is_active' => true]);

        app(ModerateAccountAction::class)->delete($admin, $customer);

        $this->assertSoftDeleted('users', ['id' => $customer->id]);

        // The number is available again — the whole point of «حذف خالص».
        $this->assertDatabaseMissing('users', ['phone' => '01099887766', 'deleted_at' => null]);
        User::factory()->create(['type' => UserType::Customer, 'phone' => '01099887766']);
        $this->assertDatabaseCount('users', 3);
    }

    public function test_a_reset_clears_the_profile_and_leaves_the_ledger_alone(): void
    {
        [$order, , $admin] = $this->scenario();
        $customer = $order->orderGroup->user;
        $customer->update(['email' => 'a@b.com']);

        $password = app(ModerateAccountAction::class)->reset($admin, $customer);

        $this->assertNotSame('', $password);

        $fresh = $customer->fresh();
        $this->assertNull($fresh->email);
        $this->assertTrue((bool) $fresh->must_change_password);
        $this->assertTrue((bool) $fresh->is_active);

        // The order is the platform's record of what happened, not the person's profile.
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_the_last_super_admin_cannot_be_locked_out(): void
    {
        $target = User::factory()->create(['type' => UserType::Admin, 'is_active' => true]);
        $target->assignRole('super_admin');
        $actor = User::factory()->create(['type' => UserType::Admin, 'is_active' => true]);

        $this->expectExceptionMessage('لا يمكن إيقاف آخر حساب super_admin');
        app(ModerateAccountAction::class)->block($actor, $target, 'x');
    }

    // ------------------------------------------------------------ complaints

    public function test_a_new_complaint_reaches_every_operator(): void
    {
        $customer = User::factory()->create(['type' => UserType::Customer, 'is_active' => true]);
        $admin = User::factory()->create(['type' => UserType::Admin, 'is_active' => true]);

        app(CreateComplaintAction::class)->execute($customer, [
            'category' => 'app_problem',
            'description' => 'التطبيق يغلق عند فتح السلة',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => 'complaint.new_admin',
        ]);
    }

    public function test_an_internal_note_never_leaves_the_operators(): void
    {
        $customer = User::factory()->create(['type' => UserType::Customer, 'is_active' => true]);
        $admin = $this->operator(['complaints.view', 'complaints.reply']);

        $complaint = app(CreateComplaintAction::class)->execute($customer, [
            'category' => 'app_problem',
            'description' => 'التطبيق يغلق عند فتح السلة',
        ]);

        $before = Notification::query()->where('user_id', $customer->id)->count();

        $this->actingAs($admin)->withHeaders(['X-App' => 'admin'])
            ->postJson("/api/v1/admin/complaints/{$complaint->id}/messages", [
                'message' => 'الشكوى دي على المطعم نفسه، راجع الطلبات السابقة',
                'visibility' => 'internal',
            ])
            ->assertCreated();

        $this->assertSame(
            $before,
            Notification::query()->where('user_id', $customer->id)->count(),
            'an internal note reached the complainant',
        );
    }

    public function test_an_open_complaint_shows_up_in_the_alert_dock(): void
    {
        $customer = User::factory()->create(['type' => UserType::Customer, 'is_active' => true]);
        $admin = $this->operator(['orders.view']);

        Complaint::query()->create([
            'ticket_number' => 'CMP-260908-ABCDE',
            'user_id' => $customer->id,
            'user_role' => 'customer',
            'category' => 'app_problem',
            'description' => 'التطبيق بطيء',
            'status' => 'open',
            'priority' => 'high',
        ]);

        $this->actingAs($admin)->withHeaders(['X-App' => 'admin'])
            ->getJson('/api/v1/admin/dashboard/open-alerts')
            ->assertOk()
            ->assertJsonPath('data.counts.complaint_open', 1)
            ->assertJsonFragment(['kind' => 'complaint_open']);
    }

    // --------------------------------------------------------------- helpers

    /** @return array{0: Order, 1: Driver, 2: User} */
    private function scenario(): array
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $this->makeCart($customer, [[$restaurant, $this->makeProduct($restaurant, 'سندوتش', 100.0), 1]]);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid(),
        );

        $order = Order::query()->where('order_group_id', $group->id)->firstOrFail();
        $order->update(['status' => OrderStatus::Ready]);

        $driverUser = User::factory()->create(['type' => UserType::Driver, 'name' => 'كابتن وائل']);
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'earning_type' => 'fixed',
            'earning_value' => 15,
            'is_active' => true,
            'is_online' => true,
            'is_available' => true,
            'current_lat' => 31.3841,
            'current_lng' => 31.4841,
            'location_updated_at' => now(),
        ]);

        return [$order->fresh(), $driver, $this->operator()];
    }

    /**
     * A global-scope super admin. Scope is the other half of D-51: without a row here
     * `AdminScope::applyToOrders` matches nothing and every list comes back empty.
     *
     * @param  list<string>  $permissions  ignored for a super admin, kept so the call
     *                                     sites still read as «an operator who may do X»
     */
    private function operator(array $permissions = []): User
    {
        $admin = User::factory()->create(['type' => UserType::Admin, 'is_active' => true]);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        return $admin;
    }
}
