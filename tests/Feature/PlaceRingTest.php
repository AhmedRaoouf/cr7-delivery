<?php

namespace Tests\Feature;

use App\Actions\Customer\CreateOrderAction;
use App\Actions\Restaurant\AcceptOrderAction;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\OrderDeadlines;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * D-100 — «بيرن ‪30‬ ثانيه لو المكان قبل او رفض الرنه بتروح او لو معملش حاجه بيقعد دقيقه
 * ويرن تاني … تلاته بس في حاله ان مفيش رد».
 *
 * What is pinned here is the part the phone cannot do for itself: the ring is a
 * data-only message (so the app's background handler, not the system, draws a looping
 * notification), there are at most three of them a gap apart, and an answer stops them.
 */
class PlaceRingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->configureFirebase();
        $this->fakeFcm();
    }

    public function test_a_new_order_rings_the_place_with_a_data_only_message(): void
    {
        [$order, $owner] = $this->placeOrder();

        $rings = $this->ringsSentTo('tok-'.$owner->id);

        $this->assertCount(1, $rings);
        $this->assertArrayNotHasKey('notification', $rings[0], 'a notification block is drawn by the system once and cannot loop');
        $this->assertSame('order.ring', $rings[0]['data']['type']);
        $this->assertSame('1', $rings[0]['data']['attempt']);
        $this->assertSame('30', $rings[0]['data']['duration_seconds']);
        $this->assertSame('HIGH', $rings[0]['android']['priority']);

        // The feed carries the order once, and the ring is its push.
        $this->assertSame(1, Notification::query()->where('user_id', $owner->id)->where('type', 'order.new')->count());
        $this->assertSame([], $this->alertsSentTo('tok-'.$owner->id));

        $order->refresh();
        $this->assertSame(1, (int) $order->ring_attempts);
        $this->assertEqualsWithDelta(now()->addSeconds(90)->timestamp, $order->ring_next_at->timestamp, 2);
    }

    public function test_an_unanswered_order_rings_three_times_a_minute_after_each_ring_ends(): void
    {
        [$order, $owner] = $this->placeOrder();

        // Not yet: the first ring is still playing, or the gap is still running.
        $this->travel(60)->seconds();
        OrderDeadlines::ringAgain();
        $this->assertCount(1, $this->ringsSentTo('tok-'.$owner->id));

        $this->travel(31)->seconds();
        OrderDeadlines::ringAgain();
        $this->assertCount(2, $this->ringsSentTo('tok-'.$owner->id));

        $this->travel(91)->seconds();
        OrderDeadlines::ringAgain();
        $rings = $this->ringsSentTo('tok-'.$owner->id);
        $this->assertCount(3, $rings);
        $this->assertSame('3', $rings[2]['data']['attempt']);

        // «تلاته بس».
        $this->travel(10)->minutes();
        OrderDeadlines::ringAgain();
        $this->assertCount(3, $this->ringsSentTo('tok-'.$owner->id));
        $this->assertNull($order->fresh()->ring_next_at);
    }

    public function test_an_answer_stops_the_ring_on_every_phone_the_place_has(): void
    {
        [$order, $owner] = $this->placeOrder();
        $owner->deviceTokens()->create(['token' => 'second-phone', 'platform' => 'android', 'app' => 'restaurant', 'device_id' => 'second', 'capabilities' => ['ring']]);

        app(AcceptOrderAction::class)->execute($owner, $order->fresh(), 15, 'POS-1');

        foreach (['tok-'.$owner->id, 'second-phone'] as $token) {
            $stops = collect($this->dataMessagesTo($token))->where('data.type', 'order.ring_stop');
            $this->assertCount(1, $stops, "{$token} was not told to stop");
        }

        $this->assertNull($order->fresh()->ring_next_at);

        // And the clock does not ring an answered order again.
        $this->travel(5)->minutes();
        OrderDeadlines::ringAgain();
        $this->assertCount(1, $this->ringsSentTo('tok-'.$owner->id));
    }

    public function test_an_iphone_gets_a_thirty_second_alert_it_cannot_be_told_to_stop(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $restaurant = $this->makeRestaurant('كنج بروست');
        $restaurant->owner->deviceTokens()->create(['token' => 'iphone', 'platform' => 'ios', 'app' => 'restaurant', 'device_id' => 'ios-1']);
        $this->makeCart($customer, [[$restaurant, $this->makeProduct($restaurant, 'بروست', 90), 1]]);

        $group = app(CreateOrderAction::class)->execute($customer, ['address_id' => $address->id, 'payment_method' => 'cash'], 'idem-'.uniqid());

        $sent = $this->messagesTo('iphone');
        $this->assertCount(1, $sent);
        $this->assertSame('ring.caf', $sent[0]['apns']['payload']['aps']['sound']);
        $this->assertSame('time-sensitive', $sent[0]['apns']['payload']['aps']['interruption-level']);

        app(AcceptOrderAction::class)->execute($restaurant->owner, Order::query()->where('order_group_id', $group->id)->first(), 15, 'POS-2');

        // No stop message to an iOS token: an alert already playing cannot be cancelled.
        // (The acceptance's own «أكّد العميل» push reaches it like any other push.)
        $this->assertCount(0, collect($this->messagesTo('iphone'))->where('data.type', 'order.ring_stop'));
        $this->assertCount(1, collect($this->messagesTo('iphone'))->where('data.type', 'order.ring'));
    }

    public function test_with_the_ring_off_the_place_gets_the_ordinary_push(): void
    {
        Setting::query()->updateOrCreate(['key' => 'ring.enabled'], ['value' => false, 'group' => 'ring']);
        Settings::forget('ring.enabled');

        [, $owner] = $this->placeOrder();

        $this->assertSame([], $this->ringsSentTo('tok-'.$owner->id));
        $alerts = $this->alertsSentTo('tok-'.$owner->id);
        $this->assertCount(1, $alerts);
        $this->assertSame('order.new', $alerts[0]['data']['type']);
    }

    /**
     * The old APK's background handler is empty: a data-only ring sent to it while it is in
     * the background draws nothing, so a place that has not updated would hear about no
     * new order at all. It gets the push it always got.
     */
    public function test_a_phone_on_the_build_before_the_ring_gets_the_ordinary_push(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $restaurant = $this->makeRestaurant('كنج بروست');
        $owner = $restaurant->owner;
        $owner->deviceTokens()->create(['token' => 'old-apk', 'platform' => 'android', 'app' => 'restaurant', 'device_id' => 'old']);
        $owner->deviceTokens()->create(['token' => 'new-apk', 'platform' => 'android', 'app' => 'restaurant', 'device_id' => 'new', 'capabilities' => ['ring']]);
        $owner->deviceTokens()->create(['token' => 'board', 'platform' => 'web', 'app' => 'restaurant', 'device_id' => 'browser']);
        $this->makeCart($customer, [[$restaurant, $this->makeProduct($restaurant, 'بروست', 90), 1]]);

        $group = app(CreateOrderAction::class)->execute($customer, ['address_id' => $address->id, 'payment_method' => 'cash'], 'idem-'.uniqid());

        foreach (['old-apk', 'board'] as $token) {
            $this->assertSame([], $this->ringsSentTo($token), "{$token} was rung");
            $alerts = $this->alertsSentTo($token);
            $this->assertCount(1, $alerts, "{$token} heard nothing");
            $this->assertSame('order.new', $alerts[0]['data']['type']);
        }

        $this->assertCount(1, $this->ringsSentTo('new-apk'));
        $this->assertSame([], $this->alertsSentTo('new-apk'), 'the ringing phone was also sent the ordinary push');
        $this->assertSame(1, Notification::query()->where('user_id', $owner->id)->where('type', 'order.new')->count());

        // Only a phone that was rung is told to stop.
        app(AcceptOrderAction::class)->execute($owner, Order::query()->where('order_group_id', $group->id)->first(), 15, 'POS-3');
        $this->assertCount(0, collect($this->dataMessagesTo('old-apk'))->where('data.type', 'order.ring_stop'));
        $this->assertCount(1, collect($this->dataMessagesTo('new-apk'))->where('data.type', 'order.ring_stop'));
    }

    public function test_a_phone_says_whether_it_can_ring_every_time_it_registers(): void
    {
        [, $owner] = $this->placeOrder();
        Sanctum::actingAs($owner);

        $register = fn (array $extra) => $this->withHeaders(['X-App' => 'restaurant'])
            ->postJson('/api/v1/devices', ['token' => 'tok-x', 'platform' => 'android', 'app' => 'restaurant', 'device_id' => 'phone-x'] + $extra)
            ->assertOk();

        $register(['capabilities' => ['ring'], 'app_version' => '1.0.2+3']);
        $device = $owner->deviceTokens()->where('device_id', 'phone-x')->sole();
        $this->assertTrue($device->rings());
        $this->assertSame('1.0.2+3', $device->app_version);

        // The same phone rolled back to the old APK registers without the list, and stops ringing.
        $register([]);
        $this->assertFalse($device->fresh()->rings());

        $this->withHeaders(['X-App' => 'restaurant'])
            ->postJson('/api/v1/devices', ['token' => 'tok-x', 'platform' => 'android', 'app' => 'restaurant', 'device_id' => 'phone-x', 'capabilities' => ['teleport']])
            ->assertStatus(422);
    }

    // ------------------------------------------------------------ plumbing

    /** @return array{0: Order, 1: User} */
    private function placeOrder(): array
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $product = $this->makeProduct($restaurant, 'سندوتش', 40);
        $this->makeCart($customer, [[$restaurant, $product, 2]]);

        $restaurant->owner->deviceTokens()->create([
            'token' => 'tok-'.$restaurant->owner->id,
            'platform' => 'android',
            'app' => 'restaurant',
            'device_id' => 'dev-'.$restaurant->owner->id,
            'capabilities' => ['ring'],
        ]);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid(),
        );

        return [Order::query()->where('order_group_id', $group->id)->firstOrFail(), $restaurant->owner];
    }

    /** @return list<array<string, mixed>> every FCM message sent to this token */
    private function messagesTo(string $token): array
    {
        return Http::recorded()
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $request) => str_contains($request->url(), 'fcm.googleapis.com'))
            ->map(fn (Request $request) => $request->data()['message'] ?? [])
            ->filter(fn (array $message) => ($message['token'] ?? null) === $token)
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function dataMessagesTo(string $token): array
    {
        return array_values(array_filter($this->messagesTo($token), fn (array $m) => ! isset($m['notification'])));
    }

    /** @return list<array<string, mixed>> */
    private function ringsSentTo(string $token): array
    {
        return array_values(array_filter($this->messagesTo($token), fn (array $m) => ($m['data']['type'] ?? null) === 'order.ring'));
    }

    /** @return list<array<string, mixed>> messages drawn by the system — with a notification block */
    private function alertsSentTo(string $token): array
    {
        return array_values(array_filter($this->messagesTo($token), fn (array $m) => isset($m['notification'])));
    }

    private function configureFirebase(): void
    {
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

    private function fakeFcm(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/cr7-test/messages/1']),
        ]);
        Cache::forget('fcm:access-token');
    }
}
