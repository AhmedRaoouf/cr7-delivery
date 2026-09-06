<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «لما بعمل شكوى، عنوان الشكوى تبقى ظاهرة هي والتفاصيل والصورة وأي خانة بحطها تبقى ظاهرة
 * للعميل والأدمن».
 *
 * Two defects, one report:
 *
 *  1. The thread rendered `messages` and the body lived in `complaints.description`, so
 *     `CUS-42`, `RES-31` and `ADM-41` all opened on «لا توجد رسائل بعد» over a ticket that
 *     had a paragraph in it.
 *  2. The attachments were the **device's** file paths, stored verbatim. Every reader got
 *     `/data/user/0/…/x.jpg` and a broken image.
 */
class ComplaintThreadSeedTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        Storage::fake('public');

        $this->restaurant = $this->makeRestaurant('سعد الدمياطي');
        $this->customer = $this->makeCustomer();
    }

    private function order(): Order
    {
        $group = OrderGroup::query()->create([
            'group_number' => 'G'.random_int(100000, 999999),
            'idempotency_key' => (string) Str::uuid(),
            'user_id' => $this->customer->id,
            'city_id' => $this->city->id,
            'service_zone_id' => $this->zone->id,
            'payment_method' => 'cash',
            'rollup_status' => 'completed',
            'subtotal' => 100,
            'delivery_fee' => 20,
            'total' => 120,
        ]);

        return Order::query()->create([
            'order_group_id' => $group->id,
            'order_number' => 'O'.random_int(100000, 999999),
            'idempotency_key' => (string) Str::uuid(),
            'group_sequence' => 1,
            'user_id' => $this->customer->id,
            'restaurant_id' => $this->restaurant->id,
            'restaurant_name_snapshot' => $this->restaurant->name_ar,
            'city_id' => $this->city->id,
            'service_zone_id' => $this->zone->id,
            'status' => 'delivered',
            'payment_method' => 'cash',
            'delivery_address_text' => 'شارع البحر',
            'delivery_latitude' => 31.3835,
            'delivery_longitude' => 31.4835,
            'subtotal' => 100,
            'delivery_fee_share' => 20,
            'total' => 120,
        ]);
    }

    private function file(): array
    {
        return $this->withHeaders(['X-App' => 'app', 'X-Role' => 'customer'])
            ->post('/api/v1/complaints', [
                'category' => 'food_quality',
                'subject' => 'الأكل وصل بارد',
                'description' => 'الطلب اتأخر ساعة والأكل وصل بارد تمامًا.',
                'order_id' => $this->order()->id,
                'attachments' => [UploadedFile::fake()->image('proof.jpg')],
            ])
            ->assertCreated()
            ->json('data');
    }

    public function test_what_the_customer_typed_is_the_first_message_of_the_thread(): void
    {
        Sanctum::actingAs($this->customer);

        $created = $this->file();
        $messages = $created['messages'] ?? [];

        $this->assertCount(1, $messages, 'the ticket opened with an empty thread');
        $this->assertSame('الطلب اتأخر ساعة والأكل وصل بارد تمامًا.', $messages[0]['message']);
        $this->assertSame('customer', $messages[0]['sender_role']);
        // The dashboards read `body`, the app reads `message`. Both travel.
        $this->assertSame($messages[0]['message'], $messages[0]['body']);
    }

    public function test_the_photo_is_a_url_this_server_wrote_not_a_path_off_the_phone(): void
    {
        Sanctum::actingAs($this->customer);

        $created = $this->file();

        $attachments = $created['attachments'];
        $this->assertCount(1, $attachments);
        $this->assertStringContainsString('complaints/', $attachments[0]);
        $this->assertStringNotContainsString('/data/user/', $attachments[0]);

        // and the same list rides the seeded message, so a reader of the thread sees it
        $this->assertSame($attachments, $created['messages'][0]['attachments']);

        Storage::disk('public')->assertExists(
            'complaints/'.basename((string) $attachments[0]),
        );
    }

    public function test_a_device_path_sent_as_a_string_is_refused(): void
    {
        Sanctum::actingAs($this->customer);

        $this->withHeaders(['X-App' => 'app', 'X-Role' => 'customer'])
            ->postJson('/api/v1/complaints', [
                'category' => 'other',
                'description' => 'شكوى',
                'attachments' => ['/data/user/0/com.cr7.delivery/cache/x.jpg'],
            ])
            ->assertStatus(422);
    }

    public function test_the_customer_reads_the_thread_back_with_the_body_in_it(): void
    {
        Sanctum::actingAs($this->customer);
        $created = $this->file();

        $body = $this->withHeaders(['X-App' => 'app', 'X-Role' => 'customer'])
            ->getJson('/api/v1/complaints/'.$created['id'])
            ->assertOk()
            ->json('data');

        $this->assertSame('الأكل وصل بارد', $body['subject']);
        $this->assertCount(1, $body['messages']);
        $this->assertStringContainsString('وصل بارد', $body['messages'][0]['message']);
    }

    public function test_the_restaurant_sees_the_same_first_message_on_its_mirror(): void
    {
        Sanctum::actingAs($this->customer);
        $created = $this->file();

        $complaint = Complaint::query()->findOrFail($created['id']);
        $this->assertTrue($complaint->visible_to_restaurant, 'food_quality auto-mirrors (doc 24 §4.2)');

        Sanctum::actingAs(User::query()->findOrFail($this->restaurant->user_id));

        $mirrored = $this->withHeaders(['X-App' => 'restaurant'])
            ->getJson('/api/v1/restaurant/complaints/'.$complaint->id)
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $mirrored['messages']);
        $this->assertStringContainsString('وصل بارد', $mirrored['messages'][0]['message']);
        $this->assertSame($mirrored['messages'][0]['message'], $mirrored['messages'][0]['body']);
    }

    public function test_a_captain_can_open_and_read_their_own_tickets(): void
    {
        // `DRV-32` sends a captain to the customer's «تذاكري» screens, and the routes
        // behind them were `type:customer` — so the screen the app offered answered 403.
        $captain = User::query()->create([
            'name' => 'كابتن',
            'phone' => '0100'.random_int(1000000, 9999999),
            'type' => 'driver',
            'is_active' => true,
            'phone_verified_at' => now(),
        ]);
        Sanctum::actingAs($captain);

        $created = $this->withHeaders(['X-App' => 'app', 'X-Role' => 'driver'])
            ->postJson('/api/v1/complaints', [
                'category' => 'app_problem',
                'subject' => 'التطبيق بيقفل',
                'description' => 'التطبيق بيقفل وأنا في نص طلب.',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('driver', $created['user_role']);
        $this->assertSame('driver', $created['messages'][0]['sender_role']);

        $this->withHeaders(['X-App' => 'app', 'X-Role' => 'driver'])
            ->getJson('/api/v1/complaints')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
