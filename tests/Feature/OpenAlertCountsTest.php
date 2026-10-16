<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\Restaurant;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET /admin/dashboard/open-alerts` — the badge must not lie when the list is capped.
 *
 * `items` is limited to `ALERT_LIMIT` per kind on purpose: the dock is a wake-up call,
 * not a work queue. But `total` and `counts` were derived from that capped collection, so
 * on a night with 137 stranded orders the sidebar, the bell and the dock all read «20».
 * A number that stops moving while the problem grows is read as «handled» — the one thing
 * an alert must never say.
 *
 * The second test is the reason the fix is worth a test at all: `ADM-03`'s counter
 * endpoint and this one now come from a single private method, so they cannot drift apart
 * again and show two different numbers on the same screen.
 */
class OpenAlertCountsTest extends TestCase
{
    use RefreshDatabase;

    /** `DashboardController::ALERT_LIMIT`. */
    private const LIMIT = 20;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class]);
    }

    public function test_the_count_is_the_truth_even_when_the_list_is_capped(): void
    {
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $stranded = self::LIMIT + 7;

        for ($i = 0; $i < $stranded; $i++) {
            $this->makeOrder($restaurant, 'waiting_for_driver');
        }

        $this->admin();

        $body = $this->withHeader('X-App', 'admin')
            ->getJson('/api/v1/admin/dashboard/open-alerts')
            ->assertOk()
            ->json('data');

        // The list is a sample …
        $this->assertCount(self::LIMIT, $body['items']);
        $this->assertSame(self::LIMIT, $body['shown']);

        // … and the badge says how big the thing it is a sample of really is.
        $this->assertSame($stranded, $body['counts']['no_driver']);
        $this->assertSame($stranded, $body['total']);
    }

    public function test_the_two_alert_endpoints_cannot_disagree(): void
    {
        $restaurant = $this->makeRestaurant('سندوتش عمر');

        $this->makeOrder($restaurant, 'waiting_for_driver');
        $this->makeOrder($restaurant, 'waiting_for_driver');
        $this->makeOrder($restaurant, 'restaurant_timeout');
        $this->makeOrder($restaurant, 'delivery_issue');

        $this->admin();

        $counts = $this->withHeader('X-App', 'admin')
            ->getJson('/api/v1/admin/dashboard/alerts')
            ->assertOk()
            ->json('data');

        $open = $this->withHeader('X-App', 'admin')
            ->getJson('/api/v1/admin/dashboard/open-alerts')
            ->assertOk()
            ->json('data');

        $this->assertSame($counts['no_driver'], $open['counts']['no_driver']);
        $this->assertSame($counts['restaurant_timeouts'], $open['counts']['restaurant_timeout']);
        $this->assertSame($counts['delivery_issues'], $open['counts']['delivery_issue']);
        $this->assertSame($counts['payments_to_verify'], $open['counts']['payment_review']);

        // 🔴 These two are why the test's own name was not true. `alertCounts()`
        // has computed six kinds since the errand queue and the complaints dock
        // were added, and `/dashboard/alerts` published four of them — so the
        // badge read «4» over a dock that listed six kinds of row. The
        // assertion below asserts the promise rather than a subset of it.
        $this->assertSame($counts['errands_unclaimed'], $open['counts']['errand_unclaimed']);
        $this->assertSame($counts['complaints_open'], $open['counts']['complaint_open']);

        $this->assertSame(
            array_sum($open['counts']),
            array_sum($counts),
            'every kind the dock lists must also be a number the badge counts',
        );

        $this->assertSame(4, $open['total']);
    }

    public function test_a_reply_takes_the_complaint_off_the_dock_and_the_customer_writing_again_puts_it_back(): void
    {
        $admin = $this->admin();
        $customer = $this->makeCustomer();
        $complaint = \App\Models\Complaint::query()->create([
            'ticket_number' => 'T-REPLY', 'user_id' => $customer->id, 'user_role' => 'customer',
            'category' => 'other', 'description' => 'x', 'status' => 'open', 'priority' => 'normal',
        ]);
        $complaint->messages()->create(['sender_id' => $customer->id, 'sender_role' => 'customer', 'visibility' => 'all', 'message' => 'x']);

        $count = fn () => $this->withHeader('X-App', 'admin')->getJson('/api/v1/admin/dashboard/open-alerts')->json('data.counts.complaint_open');

        $this->assertSame(1, $count());

        $this->withHeader('X-App', 'admin')->postJson("/api/v1/admin/complaints/{$complaint->id}/messages", ['message' => 'داخلي', 'visibility' => 'internal'])->assertCreated();
        $this->assertSame(1, $count(), 'an internal note answers nobody');

        $this->withHeader('X-App', 'admin')->postJson("/api/v1/admin/complaints/{$complaint->id}/messages", ['message' => 'تم'])->assertCreated();
        $this->assertSame(0, $count());

        $complaint->messages()->create(['sender_id' => $customer->id, 'sender_role' => 'customer', 'visibility' => 'all', 'message' => 'لسه']);
        $this->assertSame(1, $count());
    }

    public function test_an_operator_can_dismiss_any_alert_for_themselves_only(): void
    {
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $order = $this->makeOrder($restaurant, 'restaurant_timeout');
        $this->makeOrder($restaurant, 'restaurant_timeout');
        $admin = $this->admin();

        $get = fn () => $this->withHeader('X-App', 'admin')->getJson('/api/v1/admin/dashboard/open-alerts')->json('data');

        $this->assertSame(2, $get()['counts']['restaurant_timeout']);

        $this->withHeader('X-App', 'admin')
            ->postJson('/api/v1/admin/dashboard/open-alerts/dismiss', ['kind' => 'restaurant_timeout', 'id' => $order->id])
            ->assertOk();

        $after = $get();
        $this->assertSame(1, $after['counts']['restaurant_timeout']);
        $this->assertNotContains($order->id, array_column($after['items'], 'id'));
        $this->assertSame('restaurant_timeout', $order->fresh()->status->value ?? $order->fresh()->status);

        $other = User::factory()->create(['type' => 'admin']);
        $other->assignRole('super_admin');
        $other->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($other);
        $this->assertSame(2, $get()['counts']['restaurant_timeout']);
    }

    private function makeOrder(Restaurant $restaurant, string $status): Order
    {
        $customer = $this->makeCustomer();

        $group = OrderGroup::query()->create([
            'group_number' => 'G'.random_int(100000, 999999),
            'idempotency_key' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'city_id' => $this->city->id,
            'service_zone_id' => $this->zone->id,
            'payment_method' => 'cash',
            'rollup_status' => 'in_progress',
            'subtotal' => 100,
            'delivery_fee' => 20,
            'total' => 120,
        ]);

        return Order::query()->create([
            'order_group_id' => $group->id,
            'order_number' => 'O'.random_int(100000, 999999),
            'idempotency_key' => (string) Str::uuid(),
            'group_sequence' => 1,
            'user_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'restaurant_name_snapshot' => $restaurant->name_ar,
            'city_id' => $this->city->id,
            'service_zone_id' => $this->zone->id,
            'status' => $status,
            'payment_method' => 'cash',
            'delivery_address_text' => 'شارع البحر',
            'delivery_latitude' => 31.3835,
            'delivery_longitude' => 31.4835,
            'subtotal' => 100,
            'delivery_fee_share' => 20,
            'total' => 120,
            // D-76 — «stranded» is now «no captain, and the search started at least two
            // minutes ago», so a test order that means to be stranded has to have been
            // looking for one. Backdated past `dispatch.escalate_after_seconds` (120).
            'dispatch_started_at' => in_array($status, ['ready', 'searching_driver', 'waiting_for_driver'], true)
                ? now()->subMinutes(5)
                : null,
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($admin);

        return $admin;
    }
}
