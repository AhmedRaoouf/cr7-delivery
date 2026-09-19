<?php

namespace Tests\Feature;

use App\Actions\Auth\DeleteAccountAction;
use App\Actions\Customer\CreateOrderAction;
use App\Enums\ErrandStatus;
use App\Models\Complaint;
use App\Models\ComplaintMessage;
use App\Models\Driver;
use App\Models\ErrandRequest;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\Rating;
use App\Models\ScheduledOrder;
use App\Models\User;
use App\Models\UserAddress;
use App\Support\Auth\AccountErasure;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batch 72 — «delete my account» deletes the personal data behind the account, not just the
 * sign-in (Apple 5.1.1(v), Google Play's account-deletion policy) — and refuses while an
 * order is still moving.
 */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Order $order;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        Settings::set('delivery.first_order_free', false);
        Storage::fake('public');

        $this->customer = $this->makeCustomer();
        $this->customer->update(['email' => 'ahmed@example.com', 'gender' => 'male', 'birth_date' => '1995-05-05']);
        $address = $this->makeAddress($this->customer);
        $address->update(['building' => '12', 'floor' => '3', 'landmark' => 'بجوار المسجد', 'contact_phone' => '01099998888']);
        $restaurant = $this->makeRestaurant('كنج بروست');
        $this->makeCart($this->customer, [[$restaurant, $this->makeProduct($restaurant, 'بروست', 90), 1]]);

        $group = app(CreateOrderAction::class)->execute($this->customer, [
            'address_id' => $address->id, 'payment_method' => 'cash', 'note' => 'بدون بصل',
        ], 'idem-'.uniqid());
        $this->order = Order::query()->where('order_group_id', $group->id)->firstOrFail();
        $this->order->update(['customer_note' => 'بدون بصل', 'delivery_instructions' => 'الدور الثالث']);
        $group->update(['customer_note' => 'بدون بصل', 'delivery_instructions' => 'الدور الثالث']);

        $captain = User::query()->create(['name' => 'محمود علي', 'phone' => '01011112222', 'type' => 'driver', 'is_active' => true]);
        $this->driver = Driver::query()->create([
            'user_id' => $captain->id, 'city_id' => $this->city->id, 'vehicle_type' => 'motorcycle',
            'vehicle_plate' => 'ط ر 1234', 'national_id' => '29901011234567', 'earning_type' => 'percent_of_delivery_fee',
            'earning_value' => 0, 'is_online' => true, 'is_active' => true, 'is_available' => true,
        ]);
        $this->order->update(['driver_id' => $this->driver->id, 'status' => 'delivered']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────────

    private function stored(string $folder): string
    {
        static $n = 0;
        $path = $folder.'/f-'.(++$n).'.jpg';
        Storage::disk('public')->put($path, 'x'.$n);

        return Storage::disk('public')->url($path);
    }

    private function exists(string $url): bool
    {
        return Storage::disk('public')->exists(ltrim(substr($url, strlen(rtrim(Storage::disk('public')->url(''), '/'))), '/'));
    }

    /** @return array<string, string> the files the customer's data points at */
    private function seedPersonalData(): array
    {
        $files = ['avatar' => $this->stored('avatars'), 'complaint' => $this->stored('complaints'), 'message' => $this->stored('complaints')];
        $this->customer->update(['avatar' => $files['avatar']]);

        // A saved address the customer already «deleted» in the app is still a row with a street.
        $second = UserAddress::query()->create([
            'user_id' => $this->customer->id, 'city_id' => $this->city->id, 'service_zone_id' => $this->zone->id,
            'label' => 'work', 'street' => 'شارع الجيش', 'latitude' => 31.39, 'longitude' => 31.49,
        ]);
        $second->delete();

        DB::table('favorites')->insert(['user_id' => $this->customer->id, 'favoritable_type' => 'restaurant', 'favoritable_id' => $this->order->restaurant_id]);

        Notification::query()->create(['user_id' => $this->customer->id, 'type' => 'order.accepted', 'title_key' => 'k', 'payload' => ['ar' => 'أحمد'], 'order_id' => $this->order->id]);
        DB::table('notification_deliveries')->insert(['notification_id' => Notification::query()->value('id'), 'channel' => 'push', 'device_token' => 'tok', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);

        $this->customer->deviceTokens()->create(['token' => 'fcm-token', 'platform' => 'android', 'app' => 'customer']);
        DB::table('user_verified_phones')->insert(['user_id' => $this->customer->id, 'phone' => $this->customer->phone, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('otp_codes')->insert(['phone' => $this->customer->phone, 'code_hash' => 'x', 'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now()]);

        $complaint = Complaint::query()->create([
            'ticket_number' => 'T-1', 'user_id' => $this->customer->id, 'user_role' => 'customer', 'order_id' => $this->order->id,
            'category' => 'other', 'description' => 'الطلب ناقص', 'attachments' => [$files['complaint']], 'status' => 'open', 'priority' => 'normal',
        ]);
        ComplaintMessage::query()->create([
            'complaint_id' => $complaint->id, 'sender_id' => $this->customer->id, 'sender_role' => 'customer',
            'visibility' => 'all', 'message' => 'شكرًا', 'attachments' => [$files['message']],
        ]);

        Rating::query()->create([
            'order_id' => $this->order->id, 'user_id' => $this->customer->id, 'rateable_type' => 'restaurant',
            'rateable_id' => $this->order->restaurant_id, 'stars' => 4, 'comment' => 'ممتاز جدًا',
        ]);

        ScheduledOrder::query()->create([
            'reference' => 'S-DONE', 'user_id' => $this->customer->id, 'scheduled_for' => now()->subDay(),
            'status' => ScheduledOrder::STATUS_RELEASED, 'payload' => ['address_id' => 1], 'basket_snapshot' => [], 'estimated_total' => 100, 'restaurant_count' => 1,
        ]);

        $this->errand(ErrandStatus::Delivered);

        return $files;
    }

    private function errand(ErrandStatus $status, string $ref = 'ER-1'): ErrandRequest
    {
        return ErrandRequest::query()->create([
            'reference' => $ref, 'user_id' => $this->customer->id, 'city_id' => $this->city->id, 'service_zone_id' => $this->zone->id,
            'status' => $status, 'pickup_address_text' => 'صيدلية العزبي بجوار البنك', 'pickup_lat' => 31.3843211, 'pickup_lng' => 31.4841234,
            'pickup_contact_phone' => '01055554444', 'dropoff_address_text' => 'شارع البحر عمارة 5', 'dropoff_lat' => 31.3861234, 'dropoff_lng' => 31.4861234,
            'dropoff_contact_phone' => '01099998888', 'details' => 'دواء لوالدتي', 'distance_km' => 2, 'offered_fee' => 100,
            'purchases_note' => 'ملاحظة', 'cancel_reason' => 'سبب شخصي',
        ]);
    }

    private function client(): static
    {
        Sanctum::actingAs($this->customer);

        return $this->withHeaders(['X-App' => 'app', 'Accept-Language' => 'ar']);
    }

    // ── what is erased ──────────────────────────────────────────────────────────

    public function test_deleting_erases_the_personal_rows_and_files_and_blanks_the_records_that_stay(): void
    {
        $files = $this->seedPersonalData();
        $id = $this->customer->id;
        $phone = $this->customer->phone;

        $this->client()->deleteJson('/api/v1/auth/account', ['reason' => 'privacy'])->assertOk();

        // Erased outright — the soft-deleted address too.
        foreach (['user_addresses', 'favorites', 'carts', 'notifications', 'device_tokens', 'user_verified_phones'] as $table) {
            $this->assertSame(0, DB::table($table)->where('user_id', $id)->count(), $table);
        }
        $this->assertSame(0, DB::table('notification_deliveries')->count(), 'cascaded with the notification');
        $this->assertSame(0, DB::table('otp_codes')->where('phone', $phone)->count());
        $this->assertSame(0, DB::table('cart_items')->count());
        $this->assertSame(0, DB::table('scheduled_orders')->where('user_id', $id)->count(), 'a finished scheduled order is only a payload');
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $id)->count());
        $this->assertSame(0, DB::table('refresh_tokens')->where('user_id', $id)->count());

        // The photos: profile and complaint attachments.
        foreach ($files as $name => $url) {
            $this->assertFalse($this->exists($url), "{$name} photo must be gone");
        }

        // The user row.
        $user = User::withTrashed()->find($id);
        $this->assertSame('مستخدم محذوف', $user->name);
        $this->assertStringStartsWith('deleted_', $user->phone);
        $this->assertStringEndsWith('@deleted.local', $user->email);
        $this->assertNull($user->avatar);
        $this->assertNull($user->gender);
        $this->assertNull($user->birth_date);
        $this->assertNull($user->default_address_id);
        $this->assertFalse((bool) $user->is_active);
        $this->assertTrue($user->trashed());

        // Blanked, not deleted: the order and its money.
        $order = Order::query()->find($this->order->id);
        $this->assertNotNull($order, 'the order stays');
        $this->assertSame('مستخدم محذوف', $order->customer_name_snapshot);
        $this->assertNull($order->delivery_contact_phone);
        $this->assertNull($order->customer_note);
        $this->assertNull($order->delivery_instructions);
        $this->assertSame('وسط جمصة', $order->delivery_address_text, 'the street is replaced by the area');
        $this->assertSame(['area' => 'وسط جمصة'] + array_filter(['city' => $order->delivery_address_snapshot['city'] ?? null]), $order->delivery_address_snapshot);
        $this->assertSame('31.3800000', (string) $order->delivery_latitude, 'the pin is coarsened to ~1 km');
        $this->assertEquals(90, (float) $order->subtotal, 'and the money is untouched');

        $group = OrderGroup::query()->find($order->order_group_id);
        $this->assertNull($group->delivery_contact_phone);
        $this->assertNull($group->customer_note);
        $this->assertSame('وسط جمصة', $group->delivery_address_text);
        $this->assertNull($group->address_id);

        $errand = ErrandRequest::query()->where('user_id', $id)->sole();
        $this->assertSame('عنوان محذوف', $errand->pickup_address_text);
        $this->assertNull($errand->dropoff_contact_phone);
        $this->assertSame('', $errand->details);
        $this->assertNull($errand->purchases_note);
        $this->assertNull($errand->cancel_reason);
        $this->assertSame('31.3800000', (string) $errand->pickup_lat);
        $this->assertEquals(100, (float) $errand->offered_fee);

        // Kept: the complaint and its words, without the photos; the rating without its comment.
        $complaint = Complaint::query()->where('user_id', $id)->sole();
        $this->assertSame('الطلب ناقص', $complaint->description);
        $this->assertNull($complaint->attachments);
        $this->assertNull(ComplaintMessage::query()->where('sender_id', $id)->sole()->attachments);
        $rating = Rating::query()->where('user_id', $id)->sole();
        $this->assertNull($rating->comment);
        $this->assertSame(4, (int) $rating->stars);
    }

    public function test_the_restaurant_and_the_captain_still_read_the_order_with_the_anonymised_customer(): void
    {
        $this->client()->deleteJson('/api/v1/auth/account')->assertOk();

        Sanctum::actingAs($this->order->restaurant->owner);
        $payload = $this->withHeaders(['X-App' => 'restaurant'])->getJson('/api/v1/restaurant/orders/'.$this->order->id)->assertOk()->json('data');

        $this->assertSame('مستخدم محذوف', $payload['customer']['name']);
        $this->assertNull($payload['customer']['phone']);
        $this->assertSame('وسط جمصة', $payload['customer']['address']['text']);
        $this->assertNull($payload['customer']['address']['street']);
        $this->assertNull($payload['customer']['address']['landmark']);
        $this->assertSame('01011112222', $payload['captain']['phone'], 'the other parties are untouched');

        Sanctum::actingAs($this->driver->user);
        $history = $this->withHeaders(['X-App' => 'app'])->getJson('/api/v1/driver/orders')->assertOk()->json('data');
        $this->assertNotEmpty($history);
        $this->assertStringNotContainsString('ahmed@example.com', json_encode($history));
        $this->assertStringNotContainsString('01099998888', json_encode($history), 'the address contact phone is gone from the captain history');
        $this->assertStringNotContainsString('شارع البحر', json_encode($history, JSON_UNESCAPED_UNICODE), 'and so is the street');

        // The order, the group and the customer's own row still exist, so every foreign key holds.
        $this->assertNotNull(Order::query()->find($this->order->id)->order_group_id);
        $this->assertNotNull(User::withTrashed()->find($this->customer->id));
    }

    // ── the active-work guard ───────────────────────────────────────────────────

    public function test_an_order_that_is_not_over_blocks_deletion_with_409(): void
    {
        $this->seedPersonalData();
        $this->order->update(['status' => 'preparing']);

        $response = $this->client()->deleteJson('/api/v1/auth/account')->assertStatus(409);

        $this->assertFalse($response->json('success'));
        $this->assertSame('active_order_blocks_deletion', $response->json('error'));
        $this->assertSame('لديك طلب جارٍ. انتظر حتى يكتمل أو ألغِه قبل حذف الحساب.', $response->json('message'));
        $this->assertSame(['active_orders' => 1, 'active_errands' => 0, 'scheduled_orders' => 0], $response->json('meta'));

        // Nothing was touched: the account, its address, its photo.
        $user = User::query()->find($this->customer->id);
        $this->assertSame('أحمد', $user->name);
        $this->assertSame(1, UserAddress::query()->where('user_id', $user->id)->count());
        $this->assertTrue($this->exists($user->avatar));

        // Once the order is over, the same request goes through.
        $this->order->update(['status' => 'cancelled']);
        $this->client()->deleteJson('/api/v1/auth/account')->assertOk();
    }

    public function test_every_non_terminal_state_blocks_and_delivery_issue_is_one_of_them(): void
    {
        foreach (['pending', 'confirmed', 'ready', 'on_the_way', 'delivery_issue'] as $status) {
            $this->order->update(['status' => $status]);
            $this->assertSame(1, AccountErasure::activeWork($this->customer)['active_orders'], $status);
        }

        foreach (['delivered', 'cancelled'] as $status) {
            $this->order->update(['status' => $status]);
            $this->assertSame(0, AccountErasure::activeWork($this->customer)['active_orders'], $status);
        }
    }

    public function test_an_open_errand_and_a_scheduled_order_block_too(): void
    {
        $errand = $this->errand(ErrandStatus::Assigned);

        $this->client()->deleteJson('/api/v1/auth/account')->assertStatus(409)
            ->assertJsonPath('error', 'active_order_blocks_deletion')->assertJsonPath('meta.active_errands', 1);

        $errand->update(['status' => ErrandStatus::Delivered]);
        ScheduledOrder::query()->create([
            'reference' => 'S-1', 'user_id' => $this->customer->id, 'scheduled_for' => now()->addDay(),
            'status' => ScheduledOrder::STATUS_SCHEDULED, 'payload' => [], 'basket_snapshot' => [], 'estimated_total' => 100, 'restaurant_count' => 1,
        ]);

        $this->client()->deleteJson('/api/v1/auth/account')->assertStatus(409)->assertJsonPath('meta.scheduled_orders', 1);

        ScheduledOrder::query()->update(['status' => ScheduledOrder::STATUS_CANCELLED]);
        $this->client()->deleteJson('/api/v1/auth/account')->assertOk();
        $this->assertSame(0, ScheduledOrder::query()->where('user_id', $this->customer->id)->count());
    }

    // ── atomicity ───────────────────────────────────────────────────────────────

    public function test_a_failure_part_way_rolls_everything_back_and_leaves_every_file_alone(): void
    {
        $files = $this->seedPersonalData();
        $before = [
            'addresses' => DB::table('user_addresses')->where('user_id', $this->customer->id)->count(),
            'notifications' => DB::table('notifications')->count(),
            'orderName' => Order::query()->find($this->order->id)->customer_name_snapshot,
        ];

        // The user row is written last, after every erasure above it has run.
        User::updating(function (User $user) {
            if ($user->name === 'مستخدم محذوف') {
                throw new \RuntimeException('simulated failure');
            }
        });

        try {
            app(DeleteAccountAction::class)->execute($this->customer->fresh(), null);
            $this->fail('the failure must surface');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure', $e->getMessage());
        } finally {
            User::flushEventListeners();
        }

        $this->assertSame($before['addresses'], DB::table('user_addresses')->where('user_id', $this->customer->id)->count());
        $this->assertSame($before['notifications'], DB::table('notifications')->count());
        $this->assertSame($before['orderName'], Order::query()->find($this->order->id)->customer_name_snapshot);
        $this->assertNull(User::withTrashed()->find($this->customer->id)->deleted_at);
        $this->assertNotNull(Complaint::query()->where('user_id', $this->customer->id)->value('attachments'));
        $this->assertSame(1, $this->customer->deviceTokens()->count());

        foreach ($files as $name => $url) {
            $this->assertTrue($this->exists($url), "{$name} file must survive a rolled-back erasure");
        }
    }

    public function test_a_second_call_does_nothing_and_a_second_request_is_unauthenticated(): void
    {
        $this->seedPersonalData();
        $action = app(DeleteAccountAction::class);

        $action->execute($this->customer, null);
        $snapshot = [User::withTrashed()->find($this->customer->id)->only(['name', 'phone', 'email']), Order::query()->find($this->order->id)->only(['customer_name_snapshot', 'delivery_address_text'])];

        $action->execute($this->customer->fresh() ?? User::withTrashed()->find($this->customer->id), null);
        $action->execute(User::withTrashed()->find($this->customer->id), null);

        $this->assertSame($snapshot, [User::withTrashed()->find($this->customer->id)->only(['name', 'phone', 'email']), Order::query()->find($this->order->id)->only(['customer_name_snapshot', 'delivery_address_text'])]);

        // Over HTTP the first call ended every session, so the second cannot even authenticate.
        $customer = $this->makeCustomer();
        $token = $customer->createToken('t')->plainTextToken;
        $this->withToken($token)->withHeaders(['X-App' => 'app'])->deleteJson('/api/v1/auth/account')->assertOk();
        $this->app['auth']->forgetGuards(); // a test keeps the resolved user between requests; a real request does not
        $this->withToken($token)->withHeaders(['X-App' => 'app'])->deleteJson('/api/v1/auth/account')->assertStatus(401);
    }

    // ── other roles ─────────────────────────────────────────────────────────────

    public function test_a_captain_or_a_place_cannot_delete_itself_through_the_api(): void
    {
        foreach ([[$this->driver->user, 'app'], [$this->order->restaurant->owner, 'restaurant']] as [$user, $app]) {
            Sanctum::actingAs($user);

            $response = $this->withHeaders(['X-App' => $app])->deleteJson('/api/v1/auth/account')->assertStatus(403);
            $this->assertSame('account_deletion_by_request', $response->json('error'));

            $fresh = User::query()->find($user->id);
            $this->assertNotSame('مستخدم محذوف', $fresh->name);
            $this->assertTrue((bool) $fresh->is_active);
        }

        $this->assertNotNull(Driver::query()->find($this->driver->id)->national_id, 'the captain row is untouched');
    }

    public function test_an_unrelated_customers_data_is_not_touched(): void
    {
        $other = $this->makeCustomer();
        $address = $this->makeAddress($other);
        $other->deviceTokens()->create(['token' => 'other-token', 'platform' => 'android', 'app' => 'customer']);

        $this->client()->deleteJson('/api/v1/auth/account')->assertOk();

        $this->assertSame(1, UserAddress::query()->where('user_id', $other->id)->count());
        $this->assertSame($address->id, $other->fresh()->default_address_id);
        $this->assertSame(1, $other->deviceTokens()->count());
        $this->assertSame('أحمد', $other->fresh()->name);
    }

    // ── the written-request path ────────────────────────────────────────────────

    public function test_the_office_command_runs_the_same_erasure_and_refuses_what_the_api_refuses(): void
    {
        $this->seedPersonalData();
        $phone = $this->customer->phone;

        $this->artisan('cr7:erase-account', ['phone' => '+2'.$phone, '--force' => true])->assertSuccessful();

        $this->assertSame(0, UserAddress::withTrashed()->where('user_id', $this->customer->id)->count());
        $this->assertSame('مستخدم محذوف', User::withTrashed()->find($this->customer->id)->name);

        // A captain, a stranger, and an account with an order in flight are all refused.
        $this->artisan('cr7:erase-account', ['phone' => $this->driver->user->phone, '--force' => true])->assertFailed();
        $this->artisan('cr7:erase-account', ['phone' => '01000000001', '--force' => true])->assertFailed();

        $busy = $this->makeCustomer();
        $group = OrderGroup::query()->find($this->order->order_group_id);
        $this->order->update(['user_id' => $busy->id, 'status' => 'preparing']);
        $group->update(['user_id' => $busy->id]);
        $this->artisan('cr7:erase-account', ['phone' => $busy->phone, '--force' => true])->assertFailed();
        $this->assertNull(User::withTrashed()->find($busy->id)->deleted_at);
    }
}
