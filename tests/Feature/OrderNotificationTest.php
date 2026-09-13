<?php

namespace Tests\Feature;

use App\Actions\Customer\CreateOrderAction;
use App\Enums\OrderStatus;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Support\Notifications\Isolate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * doc 11 §1–3, wired to the order machine.
 *
 * The notifications hang off `OrderObserver`, so what these tests really assert
 * is that the nineteen places that write `status` all produce the right rows
 * without any of them knowing that notifications exist.
 *
 * The FCM side is faked at the HTTP boundary rather than by swapping the client,
 * so the JWT signing, the token exchange, the message envelope and the
 * dead-token handling are all exercised as written.
 */
class OrderNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->configureFirebase();
        $this->fakeFcm();
    }

    public function test_placing_an_order_tells_the_customer_and_the_restaurant(): void
    {
        [$order, $customer, $restaurantOwner] = $this->placeOrder();

        $this->assertNotified($customer, 'order.placed');
        $this->assertNotified($restaurantOwner, 'order.new');

        // The Arabic body names the order, and the number is isolated: an
        // unisolated «CR7-260904-1258» beside Arabic punctuation renders with its
        // segments reordered.
        // Once per basket (doc 62 §9), so it names the basket's number.
        $row = $this->notificationFor($customer, 'order.placed');
        $this->assertStringContainsString(
            Isolate::ltr($order->orderGroup->group_number),
            $row->payload['body_ar'],
        );
    }

    public function test_the_row_carries_both_locales_not_the_one_in_use(): void
    {
        [, $customer] = $this->placeOrder();

        $row = $this->notificationFor($customer, 'order.placed');

        // A customer who switches the app to English later must not find a centre
        // frozen in the language they were using when the push went out.
        $this->assertSame('تم استلام طلبك', $row->payload['title_ar']);
        $this->assertSame('Order placed', $row->payload['title_en']);
    }

    public function test_the_delivery_code_reaches_the_customer_isolated(): void
    {
        [$order, $customer] = $this->placeOrder();

        $order->forceFill([
            'status' => OrderStatus::ArrivedRestaurant,
            'delivery_otp' => '4821',
        ])->save();

        $order->forceFill(['status' => OrderStatus::PickedUp])->save();

        $row = $this->notificationFor($customer, 'order.picked_up');

        // D-43 — this push is where the customer meets the code. Handing it over
        // with the digits reordered is the one failure in the delivery-code
        // feature that produces no error anywhere: the captain is simply told the
        // correct code is wrong.
        $this->assertStringContainsString(Isolate::ltr('4821'), $row->payload['title_ar']);
        $this->assertStringNotContainsString('{delivery_otp}', $row->payload['body_ar']);
    }

    public function test_a_direct_order_announces_nothing_to_a_customer_who_does_not_exist(): void
    {
        [$order] = $this->placeOrder();
        $order->update(['origin' => 'restaurant_direct']);

        Notification::query()->delete();

        // D-47 — the app never saw this customer, and telling the restaurant about
        // its own action is noise.
        $direct = Order::query()->find($order->id);
        $direct->forceFill(['status' => OrderStatus::Accepted])->save();

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_a_dead_device_token_is_deleted_rather_than_retried_forever(): void
    {
        $this->fakeFcm(Http::response([
            'error' => ['status' => 'UNREGISTERED', 'message' => 'Requested entity was not found.'],
        ], 404));

        [, $customer] = $this->placeOrder();

        // Left in place it is one guaranteed failed request on every future
        // notification to this account, for as long as the account exists.
        $this->assertSame(0, DeviceToken::query()->where('user_id', $customer->id)->count());
        $this->assertDatabaseHas('notification_deliveries', ['status' => 'failed']);

        // And the row itself survives — the centre is the record, the push is a copy.
        $this->assertNotified($customer, 'order.placed');
    }

    public function test_a_dead_fcm_project_does_not_fail_the_order(): void
    {
        $this->fakeFcm(Http::response(['error' => 'nope'], 500));

        [$order, $customer] = $this->placeOrder();

        $order->forceFill(['status' => OrderStatus::Accepted])->save();

        // The transition happened, the centre has the row, and only the delivery
        // is marked failed.
        $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);
        $this->assertNotified($customer, 'order.placed');
        $this->assertDatabaseHas('notification_deliveries', ['status' => 'failed']);
    }

    public function test_an_account_with_no_device_is_still_written_into_the_centre(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $restaurant = $this->makeRestaurant('ناصر القياس');
        $product = $this->makeProduct($restaurant, 'فراخ', 90);
        $this->makeCart($customer, [[$restaurant, $product, 1]]);

        // No `device_tokens` row at all — a customer who has never granted push,
        // or is signed in on the web.
        app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid(),
        );

        $this->assertNotified($customer, 'order.placed');
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    // ----------------------------------------------------------------- helpers

    /** @return array{0: Order, 1: User, 2: User} */
    private function placeOrder(): array
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $product = $this->makeProduct($restaurant, 'سندوتش', 40);
        $this->makeCart($customer, [[$restaurant, $product, 2]]);

        $this->giveDevice($customer);
        $this->giveDevice($restaurant->owner);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid(),
        );

        $order = Order::query()->where('order_group_id', $group->id)->firstOrFail();

        return [$order, $customer, $restaurant->owner];
    }

    private function giveDevice(User $user): void
    {
        $user->deviceTokens()->create([
            'token' => 'tok-'.$user->id,
            'platform' => 'android',
            'app' => $user->type->value === 'restaurant' ? 'restaurant' : 'customer',
            'device_id' => 'dev-'.$user->id,
        ]);
    }

    private function configureFirebase(): void
    {
        // A real service-account shape with a throwaway key, so `openssl_sign`
        // runs for real: a malformed private key is exactly the failure this
        // would otherwise not catch.
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        $path = storage_path('framework/testing/service-account.json');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, json_encode([
            'type' => 'service_account',
            'project_id' => 'cr7-test',
            'client_email' => 'test@cr7-test.iam.gserviceaccount.com',
            'private_key' => $pem,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR));

        config(['services.firebase.credentials' => $path]);
    }

    /**
     * Replaces the stub set rather than adding to it.
     *
     * `Http::fake()` **merges**, and the first registered pattern answers — so a
     * test that re-faked FCM with a 500 was still served `setUp`'s success stub
     * and asserted «the order survived a dead FCM» against an FCM that was
     * perfectly alive. It passed, and it was testing nothing. The only way to
     * genuinely replace a stub is to replace the factory under it.
     *
     * `preventStrayRequests()` then makes any URL not covered here a loud
     * failure rather than a real outbound call from a test run.
     */
    private function fakeFcm(mixed $fcm = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => $fcm ?? Http::response(['name' => 'projects/cr7-test/messages/1']),
        ]);

        // The access token is cached for 55 minutes, and the cache outlives a
        // single test in the array driver — so a test that wanted a fresh token
        // exchange would silently skip it.
        Cache::forget('fcm:access-token');
    }

    private function assertNotified(User $user, string $type): void
    {
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => $type,
        ]);
    }

    private function notificationFor(User $user, string $type): Notification
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->firstOrFail();
    }
}
