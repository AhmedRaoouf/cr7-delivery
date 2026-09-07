<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\Notification;
use App\Models\OrderGroup;
use App\Models\User;
use App\Support\FileUploader;
use App\Support\OrderNumber;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The defects the operator's 2026-09-07 walk turned up, each pinned by the smallest test
 * that would have caught it.
 */
class OperatorWalkFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
    }

    /**
     * `RES-26` was a list of dots and dashes.
     *
     * The table stores `title_key` plus a two-locale `payload` and has no `title`/`body`
     * columns at all, so both dashboards rendered `notification.title ?? '—'` forever.
     */
    public function test_a_notification_carries_a_title_and_body_the_dashboards_can_read(): void
    {
        $user = $this->makeCustomer();

        $notification = Notification::query()->create([
            'user_id' => $user->id,
            'type' => 'order.delivered',
            'title_key' => 'order.delivered',
            'payload' => [
                'title_ar' => 'تم التوصيل ✅',
                'body_ar' => 'نتمنى لك وجبة شهية',
                'title_en' => 'Delivered ✅',
                'body_en' => 'Enjoy!',
            ],
        ]);

        Sanctum::actingAs($user);
        $this->withHeader('X-App', 'app');

        $arabic = $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/notifications');

        $arabic->assertOk();
        $this->assertSame('تم التوصيل ✅', $arabic->json('data.0.title'));
        $this->assertSame('نتمنى لك وجبة شهية', $arabic->json('data.0.body'));

        // The row follows the request's locale, not the one it was written in — an
        // operator who switched to English must not meet a row frozen in Arabic.
        $english = $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/notifications');
        $this->assertSame('Delivered ✅', $english->json('data.0.title'));
        $this->assertSame('Enjoy!', $english->json('data.0.body'));

        $this->assertNotSame('—', $notification->fresh()->title);
    }

    /** A type the copy table does not know still gets a title rather than a dash. */
    public function test_a_notification_with_no_payload_falls_back_to_its_key(): void
    {
        $user = $this->makeCustomer();
        Notification::query()->create([
            'user_id' => $user->id,
            'type' => 'something.new',
            'title_key' => 'something.new',
            'payload' => null,
        ]);

        Sanctum::actingAs($user);
        $this->withHeader('X-App', 'app');

        $this->getJson('/api/v1/notifications')->assertJsonPath('data.0.title', 'something.new');
    }

    /**
     * Every uploaded image 404'd on the live host.
     *
     * Not a missing symlink, which is what it looked like. Laravel's stock `local`
     * (private) disk ships `'serve' => true` and declares no `url`, so the framework
     * registered **its** route at `/storage/{path}` — the same path every public upload
     * URL uses — and `ServeFile` refuses a private disk without a signed URL. 403 in
     * local, 404 in production, for dish photos, logos, banners, avatars and complaint
     * attachments alike.
     */
    public function test_an_uploaded_image_is_served_even_when_the_storage_symlink_is_not(): void
    {
        Storage::fake('public');

        $url = FileUploader::storePublic(UploadedFile::fake()->image('dish.png'), 'products');
        $path = ltrim(parse_url($url, PHP_URL_PATH) ?? '', '/');

        $this->assertStringStartsWith('storage/products/', $path);

        $this->get('/'.$path)->assertOk();
    }

    public function test_the_upload_route_refuses_to_climb_out_of_the_disk(): void
    {
        Storage::fake('public');

        $this->get('/storage/../../.env')->assertNotFound();
        $this->get('/storage/products/does-not-exist.png')->assertNotFound();
    }

    /** «ارقام الطلبات تكون squential بتبدا من 1000». */
    public function test_order_references_are_sequential_from_one_thousand(): void
    {
        $this->assertSame('CR7-1000', OrderNumber::forGroup(1));
        $this->assertSame('CR7-1043', OrderNumber::forGroup(44));
        $this->assertSame('CR7-1043-2', OrderNumber::forSubOrder('CR7-1043', 2));

        // The old shape carried two dashes and a date; the new one cannot collide with it.
        $this->assertStringNotContainsString(now()->format('ymd'), OrderNumber::forGroup(1));
    }

    /**
     * `ADM-25` opened on «حقل lat مطلوب» because the dashboard has always sent
     * `latitude`/`longitude` and the endpoint only ever validated `lat`/`lng`.
     */
    public function test_the_pricing_preview_accepts_both_spellings_and_answers_the_shape_the_client_parses(): void
    {
        $this->seed([PermissionsSeeder::class, RolesSeeder::class]);
        $restaurant = $this->makeRestaurant('سندوتش عمر');

        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($admin);
        $this->withHeader('X-App', 'admin');

        foreach ([
            ['lat' => 31.3835, 'lng' => 31.4835],
            ['latitude' => 31.3835, 'longitude' => 31.4835],
        ] as $point) {
            $response = $this->postJson('/api/v1/admin/pricing-rules/preview', [
                'restaurant_id' => $restaurant->id,
            ] + $point);

            $response->assertOk();
            $response->assertJsonStructure(['data' => [
                'distance_km', 'fee', 'rule_id', 'service_zone_name', 'pricing_type', 'is_serviceable',
            ]]);
            // Inside the seeded zone, so the flat zone fee is what a real order pays.
            $this->assertTrue($response->json('data.is_serviceable'));
            $this->assertEquals(20, $response->json('data.fee'));
        }
    }

    /**
     * «هو ممكن يختار إنه قاعد في الجيزة وهو أصلاً قاعد معايا، فيجيبوا اللي هيقولوه بتاعه
     * بالسعر» — `zone_id` and the pin were both required and never checked against each
     * other, so any customer could pin their real door and file it under the cheapest area.
     */
    public function test_an_address_cannot_claim_a_zone_far_from_its_own_pin(): void
    {
        $customer = $this->makeCustomer();
        Sanctum::actingAs($customer);
        $this->withHeader('X-App', 'app');

        $far = $this->city->serviceZones()->create([
            'name_ar' => 'زيان',
            'name_en' => 'Zayan',
            'center_lat' => 31.6000,
            'center_lng' => 31.9000,
            'radius_km' => 2,
            'delivery_fee' => 70,
            'is_active' => true,
            'is_selectable' => true,
        ]);

        $this->postJson('/api/v1/addresses', [
            'label' => 'home',
            'city_id' => $this->city->id,
            'zone_id' => $far->id,
            'latitude' => 31.3833,
            'longitude' => 31.4833,
        ])->assertStatus(422)->assertJsonValidationErrors('zone_id');

        // The honest pick still works.
        $this->postJson('/api/v1/addresses', [
            'label' => 'home',
            'city_id' => $this->city->id,
            'zone_id' => $this->zone->id,
            'latitude' => 31.3833,
            'longitude' => 31.4833,
        ])->assertCreated();
    }

    public function test_an_address_outside_every_city_is_refused(): void
    {
        $customer = $this->makeCustomer();
        Sanctum::actingAs($customer);
        $this->withHeader('X-App', 'app');

        $this->postJson('/api/v1/addresses', [
            'label' => 'home',
            'city_id' => $this->city->id,
            'zone_id' => $this->zone->id,
            // Cairo.
            'latitude' => 30.0444,
            'longitude' => 31.2357,
        ])->assertStatus(422)->assertJsonValidationErrors('latitude');
    }

    /**
     * The private disk must never be web-reachable.
     *
     * A regression guard, not a re-test: driver documents, national-id photos and
     * payment proofs live on it, and flipping `serve` back on would both re-break every
     * public image (by shadowing `/storage`) and put those files one signed URL away
     * from the open web.
     */
    public function test_the_private_disk_is_not_served_over_http(): void
    {
        $this->assertFalse((bool) config('filesystems.disks.local.serve'));

        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'storage/'))
            ->map(fn ($route) => $route->getName())
            ->values();

        // Exactly one thing answers `/storage/*`, and it is ours.
        $this->assertSame(['uploads.show'], $routes->all());
    }

    /** The reservation and the final reference are both `OrderNumber`'s now. */
    public function test_a_group_number_is_replaced_with_its_sequential_reference(): void
    {
        $this->assertStringStartsWith('CR7-pending-', OrderNumber::pending('abc'));
        $this->assertSame('CR7-1000', OrderNumber::forGroup(1));
        $this->assertSame('CR7-1000', OrderNumber::peek());

        OrderGroup::query()->count(); // table exists; peek reads its max id
    }
}
