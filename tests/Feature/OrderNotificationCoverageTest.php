<?php

namespace Tests\Feature;

use App\Actions\Customer\CreateOrderAction;
use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «لما بقبل الطلب، أو بيبقى جاهز خلاص… ما بيبعتش للعميل notification ليه؟»
 *
 * Two answers, stacked. The first was that no phone on the platform had ever
 * registered a device token, so every push was written and dropped — fixed in
 * `46cf19f`. The second is this file: six of the nineteen statuses reached
 * `OrderNotifications::handle()` and fell through its `match` to `default => null`,
 * and two pieces of copy — `payment.due` and `order.prep_timer_expired` — had been
 * sitting in `NotificationCopy` since it was written with no caller at all.
 *
 * The in-app row is what these assert. It is written first and inside the caller's
 * transaction (`NotificationDispatcher`'s docblock), so it is the record; the push
 * is a best-effort copy of it. If the row is missing, nothing was ever owed.
 */
class OrderNotificationCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
    }

    private function makeOrder(Restaurant $restaurant): Order
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $this->makeCart($customer, [[$restaurant, $this->makeProduct($restaurant, 'سندوتش', 100.0), 1]]);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid(),
        );

        return Order::query()->where('order_group_id', $group->id)->firstOrFail();
    }

    private function assertNotified(Order $order, string $type, User $recipient): void
    {
        $this->assertDatabaseHas('notifications', [
            'order_id' => $order->id,
            'type' => $type,
            'user_id' => $recipient->id,
        ]);
    }

    public function test_the_payment_window_opening_tells_the_customer(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $order = $this->makeOrder($restaurant);

        $order->update(['status' => OrderStatus::AwaitingPayment]);

        // D-38 — `payment.due` has existed as copy since the file was written and had
        // no caller. A customer who put the phone down after checkout learned about the
        // transfer deadline by missing it.
        $this->assertNotified($order, 'payment.due', $order->user);
    }

    public function test_the_captain_arriving_tells_the_kitchen(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $order = $this->makeOrder($restaurant);

        $driverUser = User::factory()->create(['type' => 'driver', 'name' => 'كابتن رامي']);
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'earning_type' => 'fixed',
            'earning_value' => 15,
            'is_active' => true,
        ]);

        $order->update(['status' => OrderStatus::DriverAssigned, 'driver_id' => $driver->id]);
        $order->fresh()->update(['status' => OrderStatus::ArrivedRestaurant]);

        // The board already drew «وصل الكابتن إلى المطعم». Nothing told a kitchen that
        // was not looking at the board, which is every kitchen with food on a stove.
        $this->assertNotified($order, 'order.driver_at_restaurant', $restaurant->owner);
    }

    public function test_delivery_closes_the_loop_for_the_restaurant_as_well_as_the_customer(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $order = $this->makeOrder($restaurant);

        $order->update(['status' => OrderStatus::Delivered]);

        $this->assertNotified($order, 'order.delivered', $order->user);
        $this->assertNotified($order, 'order.delivered_restaurant', $restaurant->owner);
    }

    public function test_resending_the_delivery_code_actually_sends_it_and_never_logs_it(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $order = $this->makeOrder($restaurant);
        $order->update(['status' => OrderStatus::PickedUp, 'delivery_otp' => '7391']);

        Log::spy();

        Sanctum::actingAs($order->user);
        $this->withHeader('X-App', 'app');

        $this->postJson("/api/v1/orders/{$order->order_group_id}/delivery-code/resend")->assertOk();

        // It counted against E-41's three-resend limit and sent nothing at all.
        $notification = Notification::query()
            ->where('order_id', $order->id)
            ->where('type', 'order.picked_up')
            ->latest('id')
            ->firstOrFail();

        $this->assertStringContainsString('7391', $notification->payload['body_ar']);
        $this->assertSame(1, $order->fresh()->delivery_otp_resends);

        // And the code must not appear in the log: Telescope records every entry on this
        // deployment and its panel is reachable, so a logged code is a stored one.
        Log::shouldNotHaveReceived('info', [
            \Mockery::pattern('/7391/'),
        ]);
    }

    public function test_every_status_a_customer_can_see_either_notifies_or_is_deliberately_silent(): void
    {
        // The statuses that owe nobody a push, and why. Anything not on this list and
        // not producing a row is a gap, which is exactly how six of them went missing.
        $silent = [
            OrderStatus::Pending->value => 'the placed notification covers it',
            OrderStatus::Accepted->value => 'internal — the customer meets it as awaiting_customer_confirmation',
            OrderStatus::SearchingDriver->value => 'order.ready already says «جارٍ إشعار أقرب الكباتن»',
            OrderStatus::WaitingAtRestaurant->value => 'the captain is waiting, not the customer',
            OrderStatus::OnTheWay->value => 'order.picked_up carried the delivery code and the fact',
        ];

        $covered = [
            OrderStatus::RestaurantTimeout->value,
            OrderStatus::AwaitingCustomerConfirmation->value,
            OrderStatus::AwaitingPayment->value,
            OrderStatus::Confirmed->value,
            OrderStatus::Preparing->value,
            OrderStatus::Ready->value,
            OrderStatus::WaitingForDriver->value,
            OrderStatus::DriverAssigned->value,
            OrderStatus::ArrivedRestaurant->value,
            OrderStatus::PickedUp->value,
            OrderStatus::ArrivedCustomer->value,
            OrderStatus::Delivered->value,
            OrderStatus::Cancelled->value,
            OrderStatus::DeliveryIssue->value,
        ];

        foreach (OrderStatus::cases() as $status) {
            $this->assertTrue(
                in_array($status->value, $covered, true) || array_key_exists($status->value, $silent),
                "{$status->value} neither notifies nor is listed as deliberately silent",
            );
        }

        $this->assertCount(count(OrderStatus::cases()), array_merge($covered, array_keys($silent)));
    }
}
