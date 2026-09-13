<?php

namespace Tests\Feature;

use App\Actions\Customer\CreateOrderAction;
use App\Actions\Driver\DeliverOrderAction;
use App\Actions\Driver\PickupOrderAction;
use App\Enums\AdminScopeType;
use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\DispatchService;
use App\Support\OrderDeadlines;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * doc 62 — the flows that had no end, the codes the operator switches, and the offer
 * that said «‪0.00‬» with nothing to say why.
 */
class DeadlinesCodesAndOffersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->set('ring.enabled', false);
    }

    // ------------------------------------------------------------ deadlines

    public function test_a_basket_nobody_confirmed_is_cancelled_and_both_sides_are_told_once(): void
    {
        [$order, $customer] = $this->place('vodafone_cash');
        $order->update(['status' => 'awaiting_customer_confirmation']);
        $order->orderGroup->update(['confirmation_opened_at' => now(), 'confirm_deadline_at' => now()->addMinutes(10)]);

        $this->travel(11)->minutes();
        $this->assertSame(1, OrderDeadlines::expireConfirmations());

        $order->refresh();
        $this->assertSame('cancelled', $order->status->value);
        $this->assertSame('customer_did_not_confirm', $order->cancellation_reason_code);
        $this->assertSame(1, Notification::query()->where('user_id', $customer->id)->where('type', 'order.confirm_expired')->count());
        $this->assertDatabaseHas('notifications', ['user_id' => $order->restaurant->user_id, 'type' => 'order.confirm_expired_restaurant']);
        // Said by the expiry, not also by the generic line.
        $this->assertSame(0, Notification::query()->where('user_id', $customer->id)->where('type', 'order.cancelled')->count());
    }

    public function test_an_unpaid_transfer_is_reminded_once_then_cancelled_but_a_receipt_under_review_is_left_alone(): void
    {
        [$order, $customer] = $this->place('instapay');
        $order->update(['status' => 'awaiting_payment']);
        $order->orderGroup->payment->update(['status' => 'awaiting_proof', 'gate_opened_at' => now(), 'deadline_at' => now()->addMinutes(15), 'amount' => $order->total]);

        [$reviewed] = $this->place('instapay');
        $reviewed->update(['status' => 'awaiting_payment']);
        $reviewed->orderGroup->payment->update(['status' => 'under_review', 'gate_opened_at' => now(), 'deadline_at' => now()->addMinutes(15)]);

        $this->travel(11)->minutes();
        OrderDeadlines::remindPayments();
        OrderDeadlines::remindPayments();
        $this->assertSame(1, Notification::query()->where('user_id', $customer->id)->where('type', 'payment.due_reminder')->count());

        $this->travel(5)->minutes();
        OrderDeadlines::expirePayments();

        $this->assertSame('cancelled', $order->fresh()->status->value);
        $this->assertSame('payment_not_received', $order->fresh()->cancellation_reason_code);
        $this->assertSame('failed', $order->orderGroup->payment->fresh()->status->value);
        $this->assertDatabaseHas('notifications', ['user_id' => $customer->id, 'type' => 'payment.expired']);

        $this->assertSame('awaiting_payment', $reviewed->fresh()->status->value);
    }

    // ---------------------------------------------------------------- codes

    public function test_an_order_placed_with_both_codes_off_is_picked_up_and_delivered_with_one_button_each(): void
    {
        $this->set('orders.pickup_code_required', false);
        $this->set('orders.delivery_code_required', false);

        [$order] = $this->place('cash');
        $driver = $this->captain();
        $order->update(['status' => 'arrived_restaurant', 'driver_id' => $driver->id]);
        $driver->update(['active_order_id' => $order->id]);

        // The switch flipped back mid-shift does not strand an order already placed.
        $this->set('orders.pickup_code_required', true);
        $this->set('orders.delivery_code_required', true);

        $picked = app(PickupOrderAction::class)->execute($driver, $order->fresh(), null);
        $this->assertSame('picked_up', $picked->status->value);
        $this->assertNull($picked->getRawOriginal('delivery_otp'));

        $picked->update(['status' => 'on_the_way']);
        $delivered = app(DeliverOrderAction::class)->execute($driver->fresh(), $picked->fresh(), null, null);
        $this->assertSame('delivered', $delivered->status->value);
    }

    public function test_the_code_lock_follows_the_panel_and_tells_the_office(): void
    {
        $this->set('order.otp_max_attempts', 3);

        $admin = User::factory()->create(['type' => 'admin', 'is_active' => true]);
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        [$order] = $this->place('cash');
        $driver = $this->captain();
        $order->update(['status' => 'arrived_restaurant', 'driver_id' => $driver->id]);

        foreach (range(1, 3) as $try) {
            try {
                app(PickupOrderAction::class)->execute($driver, $order->fresh(), '0000');
            } catch (ApiException) {
            }
        }

        $this->assertSame(3, (int) $order->fresh()->pickup_otp_attempts);
        $this->assertDatabaseHas('notifications', ['user_id' => $admin->id, 'type' => 'order.otp_locked_admin']);

        $this->expectExceptionMessage('تم قفل إدخال الكود');
        app(PickupOrderAction::class)->execute($driver, $order->fresh(), $order->getRawOriginal('pickup_otp'));
    }

    // --------------------------------------------------------------- offers

    public function test_a_free_first_order_offer_says_why_the_earning_is_zero(): void
    {
        $this->set('delivery.first_order_free', true);

        [$order] = $this->place('cash');
        $order->update(['status' => 'searching_driver', 'dispatch_started_at' => now()]);
        $this->assertSame('first_order', $order->free_delivery_reason);

        $driver = $this->captain(['is_available' => true, 'current_lat' => 31.3900, 'current_lng' => 31.4900, 'location_updated_at' => now()]);

        app(DispatchService::class)->offerBacklogTo($driver);

        $row = Notification::query()->where('user_id', $driver->user_id)->where('type', 'offer.new')->sole();

        $this->assertStringContainsString('أرباحك ‪0.00‬ ج.م — توصيل مجاني (أول طلب للعميل)', $row->payload['body_ar']);
        // The backlog path used to send no distance at all, printed as «—».
        $this->assertStringContainsString('يبعد عنك ‪', $row->payload['body_ar']);
        $this->assertStringContainsString('الرحلة ‪', $row->payload['body_ar']);
        $this->assertStringNotContainsString('—  ', $row->payload['body_ar']);
    }

    public function test_an_offer_tells_the_captain_what_he_pays_the_place(): void
    {
        $this->set('delivery.first_order_free', false);

        [$order] = $this->place('cash');
        $order->update(['status' => 'searching_driver', 'dispatch_started_at' => now()]);
        $driver = $this->captain(['is_available' => true]);

        app(DispatchService::class)->offerBacklogTo($driver);

        $row = Notification::query()->where('user_id', $driver->user_id)->where('type', 'offer.new')->sole();
        $this->assertStringContainsString('تدفع للمكان ‪80.00‬ ج.م', $row->payload['body_ar']);
        $this->assertStringContainsString('أرباحك ‪17.00‬ ج.م', $row->payload['body_ar']);
    }

    // ------------------------------------------------------------ plumbing

    /** @return array{0: Order, 1: User} */
    private function place(string $method): array
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $restaurant = $this->makeRestaurant('مكان '.random_int(100, 999));
        $this->makeCart($customer, [[$restaurant, $this->makeProduct($restaurant, 'وجبة', 40), 2]]);

        $group = app(CreateOrderAction::class)->execute($customer, ['address_id' => $address->id, 'payment_method' => $method], 'idem-'.uniqid());

        return [Order::query()->where('order_group_id', $group->id)->firstOrFail(), $customer];
    }

    private function captain(array $overrides = []): Driver
    {
        $user = User::query()->create([
            'name' => 'كابتن', 'phone' => '010'.random_int(10000000, 99999999),
            'type' => 'driver', 'is_active' => true,
        ]);

        return Driver::query()->create(array_merge([
            'user_id' => $user->id,
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'earning_type' => 'percent_of_delivery_fee',
            'earning_value' => 0,
            'is_online' => true,
            'is_active' => true,
            'is_available' => false,
        ], $overrides));
    }

    private function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'group' => 'test']);
        Settings::forget($key);
    }
}
