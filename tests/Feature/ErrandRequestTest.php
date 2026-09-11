<?php

namespace Tests\Feature;

use App\Actions\Errand\ErrandReference;
use App\Enums\AdminScopeType;
use App\Enums\ErrandStatus;
use App\Models\Driver;
use App\Models\ErrandRequest;
use App\Models\Notification;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * doc 40 — «اطلب كابتن».
 *
 * Operator, 2026-09-07: «عايزين نضيف خدمة داخل التطبيق أن العميل يطلب كابتن خارج خدمات
 * التطبيق … هو بيحدد السعر … هيبعت لكل المتاحين الموجودين … ولو مفيش حد متاح بيبعت
 * notification للأدمن وهو بيتصرف».
 *
 * The behaviours worth pinning are the ones that would be expensive to get wrong in
 * production: the accept race, the redaction of contact details on the open board, and
 * the customer's cancel window closing at pickup.
 */
class ErrandRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);
        Storage::fake('public');

        $this->customer = $this->makeCustomer();
    }

    public function test_a_customer_publishes_an_errand_and_it_gets_a_sequential_reference(): void
    {
        $this->actingAsCustomer();

        $response = $this->postJson('/api/v1/customer/errands', $this->payload());

        $response->assertCreated();
        // Derived from the row's own id, not from «this is the first errand ever»:
        // MySQL does not roll an AUTO_INCREMENT back with the transaction, so any other
        // test in the run that inserts an errand moves this number and the assertion
        // failed for a reason that had nothing to do with references.
        $this->assertSame(
            ErrandReference::forId((int) $response->json('data.id')),
            $response->json('data.reference'),
        );
        $this->assertSame('open', $response->json('data.status'));
        $this->assertEquals(45, $response->json('data.offered_fee'));
        // The suggestion is stored beside the offer, never instead of it.
        $this->assertNotNull($response->json('data.suggested_fee'));
    }

    public function test_the_offer_is_not_clamped_to_the_suggestion(): void
    {
        $this->actingAsCustomer();

        // Absurdly low on purpose. The mechanism is that nobody takes it, not that the
        // form refuses it — clamping would quietly turn the offer back into a price list.
        $response = $this->postJson('/api/v1/customer/errands', $this->payload(['offered_fee' => 3]));

        $response->assertCreated();
        $this->assertEquals(3, $response->json('data.offered_fee'));
    }

    public function test_a_customer_may_only_have_one_live_errand(): void
    {
        $this->actingAsCustomer();
        $this->postJson('/api/v1/customer/errands', $this->payload())->assertCreated();

        $this->postJson('/api/v1/customer/errands', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('error', 'errand_already_open');
    }

    public function test_a_pickup_outside_coverage_is_refused(): void
    {
        $this->actingAsCustomer();

        // Cairo — nowhere near the seeded Gamasa city circle.
        $this->postJson('/api/v1/customer/errands', $this->payload([
            'pickup_lat' => 30.0444,
            'pickup_lng' => 31.2357,
        ]))->assertStatus(422)->assertJsonPath('error', 'pickup_outside_coverage');
    }

    public function test_the_open_board_hides_contact_numbers_until_a_captain_takes_the_job(): void
    {
        $errand = $this->publish();
        $driver = $this->makeDriver();

        $this->actingAsDriver($driver);

        $board = $this->getJson('/api/v1/driver/errands/board');
        $board->assertOk();
        // A captain who has not accepted has no business ringing either end.
        $this->assertNull($board->json('data.0.pickup.contact_phone'));
        $this->assertNull($board->json('data.0.dropoff.contact_phone'));

        $this->postJson("/api/v1/driver/errands/{$errand->id}/accept")->assertOk();

        $mine = $this->getJson("/api/v1/driver/errands/{$errand->id}");
        $this->assertSame('01099887766', $mine->json('data.dropoff.contact_phone'));
    }

    public function test_only_the_first_captain_to_accept_gets_the_errand(): void
    {
        $errand = $this->publish();
        $first = $this->makeDriver();
        $second = $this->makeDriver();

        $this->actingAsDriver($first);
        $this->postJson("/api/v1/driver/errands/{$errand->id}/accept")->assertOk();

        $this->actingAsDriver($second);
        $this->postJson("/api/v1/driver/errands/{$errand->id}/accept")
            ->assertStatus(409)
            ->assertJsonPath('error', 'errand_taken');

        $this->assertSame($first->id, $errand->fresh()->driver_id);
    }

    public function test_the_captain_records_purchases_and_the_total_follows(): void
    {
        $errand = $this->publish();
        $driver = $this->makeDriver();
        $this->actingAsDriver($driver);

        $this->postJson("/api/v1/driver/errands/{$errand->id}/accept")->assertOk();

        $response = $this->post("/api/v1/driver/errands/{$errand->id}/purchases", [
            'purchases_amount' => 120.50,
            'purchases_note' => 'دواء من الصيدلية',
            'receipts' => [UploadedFile::fake()->image('receipt.jpg')],
        ]);

        $response->assertOk();
        $this->assertEquals(120.5, $response->json('data.purchases_amount'));
        // 45 offered + 120.50 laid out — the one number asked for at the door.
        $this->assertEquals(165.5, $response->json('data.total_due'));
        $this->assertCount(1, $response->json('data.receipt_urls'));
        // Stored where a receipt belongs, not under `complaints/`.
        $this->assertStringContainsString('/errands/', $response->json('data.receipt_urls.0'));
    }

    public function test_a_customer_may_cancel_before_pickup_but_not_after(): void
    {
        $errand = $this->publish();
        $driver = $this->makeDriver();

        $this->actingAsDriver($driver);
        $this->postJson("/api/v1/driver/errands/{$errand->id}/accept")->assertOk();

        $this->actingAsCustomer();
        // Still only assigned — the captain has not laid out any money yet.
        $this->postJson("/api/v1/customer/errands/{$errand->id}/cancel")->assertOk();

        $errand->fresh()->update(['status' => ErrandStatus::PickedUp, 'driver_id' => $driver->id]);

        $this->postJson("/api/v1/customer/errands/{$errand->id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('error', 'errand_already_collected');
    }

    public function test_an_unclaimed_errand_appears_in_the_admin_alert_dock(): void
    {
        $this->publish();

        $this->actingAsAdmin();

        $response = $this->getJson('/api/v1/admin/dashboard/open-alerts');

        $response->assertOk();
        $this->assertSame(1, $response->json('data.counts.errand_unclaimed'));
        $this->assertContains('errand_unclaimed', array_column($response->json('data.items'), 'kind'));
    }

    /**
     * 2026-09-08 — «حاول تخليها continuous… كل دقيقة تتشيك… تقوم بعت تاني».
     *
     * `publish()` ran once, at creation, against whoever happened to be online in that
     * second. On a town with seven captains that is frequently nobody, and the request
     * then sat on a board no phone had been told to open.
     */
    public function test_a_captain_who_comes_online_later_still_hears_about_an_open_errand(): void
    {
        $errand = $this->publish();

        // Nobody was on shift when it was published.
        $this->assertSame(0, Notification::query()->where('type', 'errand.new')->count());

        $this->travel(2)->minutes();
        $late = $this->makeDriver();

        $this->artisan('cr7:tick --once');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $late->user_id,
            'type' => 'errand.new',
        ]);
        $this->assertNotNull($errand->fresh()->last_published_at);
    }

    /** …and is not rung again every minute for the same one. */
    public function test_the_republish_does_not_ring_a_captain_twice(): void
    {
        $driver = $this->makeDriver();
        $this->publish();

        $this->travel(2)->minutes();
        $this->artisan('cr7:tick --once');
        $this->travel(2)->minutes();
        $this->artisan('cr7:tick --once');

        $this->assertSame(1, Notification::query()
            ->where('user_id', $driver->user_id)
            ->where('type', 'errand.new')
            ->count());
    }

    /**
     * An operator's note is *about* the job, for the platform — not a line for the
     * captain it names or the customer it is about. Same rule D-53 applies to a
     * complaint's internal note.
     */
    public function test_an_operators_internal_note_never_reaches_the_captain(): void
    {
        $errand = $this->publish();
        $driver = $this->makeDriver();

        $this->actingAsAdmin();
        $this->postJson("/api/v1/admin/errands/{$errand->id}/assign", [
            'driver_id' => $driver->id,
            'note' => 'الكابتن السابق لا يرد على الهاتف',
        ])->assertOk();

        // The admin reads it.
        $this->getJson("/api/v1/admin/errands/{$errand->id}")
            ->assertJsonPath('data.events.1.note', 'الكابتن السابق لا يرد على الهاتف');

        // The captain it names does not.
        $this->actingAsDriver($driver);
        $mine = $this->getJson("/api/v1/driver/errands/{$errand->id}");
        $mine->assertOk();
        foreach ($mine->json('data.events') as $event) {
            $this->assertNull($event['note']);
        }

        // Neither does the customer.
        $this->actingAsCustomer();
        $theirs = $this->getJson("/api/v1/customer/errands/{$errand->id}");
        $theirs->assertOk();
        foreach ($theirs->json('data.events') as $event) {
            $this->assertNull($event['note']);
        }
    }

    /**
     * D-72's errand half: an operator's assignment is an offer, not a fact.
     *
     * This used to assert `assigned` straight out of the POST, which is exactly what the
     * operator objected to — the captain learned they owned an errand from a push telling
     * them where to ride. Now it stops at `offered` and waits for an answer.
     */
    public function test_an_admin_assignment_is_an_offer_the_captain_must_accept(): void
    {
        $errand = $this->publish();
        $driver = $this->makeDriver();

        $this->actingAsAdmin();

        $response = $this->postJson("/api/v1/admin/errands/{$errand->id}/assign", [
            'driver_id' => $driver->id,
            'note' => 'لم يقبله أحد',
        ]);

        $response->assertOk();
        $this->assertSame('offered', $response->json('data.status'));
        $this->assertSame($driver->id, $response->json('data.offered_driver_id'));
        // Nobody owns it yet. A `driver_id` here is a captain riding to a pickup they
        // have not agreed to.
        $this->assertNull($errand->fresh()->driver_id);

        $this->actingAsDriver($driver);

        $mine = $this->getJson('/api/v1/driver/errands/mine');
        $mine->assertOk();
        $this->assertSame($errand->id, $mine->json('data.0.id'));
        $this->assertTrue($mine->json('data.0.is_offer_for_me'));

        $accepted = $this->postJson("/api/v1/driver/errands/{$errand->id}/accept");
        $accepted->assertOk();
        $this->assertSame('assigned', $accepted->json('data.status'));
        // The operator keeps the credit — the captain answered a question, they did not
        // take it off the board.
        $this->assertSame('admin', $accepted->json('data.assigned_by'));
        $this->assertSame($driver->id, $errand->fresh()->driver_id);
    }

    public function test_a_declined_offer_goes_back_to_the_board_and_not_to_the_same_captain(): void
    {
        $errand = $this->publish();
        $driver = $this->makeDriver();

        $this->actingAsAdmin();
        $this->postJson("/api/v1/admin/errands/{$errand->id}/assign", ['driver_id' => $driver->id])->assertOk();

        $this->actingAsDriver($driver);
        $declined = $this->postJson("/api/v1/driver/errands/{$errand->id}/decline", ['reason' => 'بعيد عني']);

        $declined->assertOk();
        $this->assertSame('open', $declined->json('data.status'));
        $this->assertNull($errand->fresh()->offered_driver_id);
        $this->assertSame([$driver->id], $errand->fresh()->declined_driver_ids);

        // Back on the board for everyone else — and off this captain's.
        $this->assertSame([], $this->getJson('/api/v1/driver/errands/board')->json('data'));

        $other = $this->makeDriver();
        $this->actingAsDriver($other);
        $this->assertCount(1, $this->getJson('/api/v1/driver/errands/board')->json('data'));
    }

    public function test_a_captain_cannot_take_an_errand_offered_to_somebody_else(): void
    {
        $errand = $this->publish();
        $offered = $this->makeDriver();
        $other = $this->makeDriver();

        $this->actingAsAdmin();
        $this->postJson("/api/v1/admin/errands/{$errand->id}/assign", ['driver_id' => $offered->id])->assertOk();

        $this->actingAsDriver($other);
        $this->postJson("/api/v1/driver/errands/{$errand->id}/accept")
            ->assertStatus(409)
            ->assertJsonPath('error', 'errand_offered_elsewhere');
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'pickup_lat' => 31.3835,
            'pickup_lng' => 31.4835,
            'pickup_address_text' => 'صيدلية النور — شارع البحر',
            'dropoff_lat' => 31.3845,
            'dropoff_lng' => 31.4845,
            'dropoff_address_text' => 'عمارات مبارك — الدور الثالث',
            'dropoff_contact_phone' => '01099887766',
            'details' => 'من فضلك اشترِ علبة باراسيتامول وأحضرها إلى العنوان',
            'offered_fee' => 45,
        ], $overrides);
    }

    private function publish(): ErrandRequest
    {
        $this->actingAsCustomer();
        $this->postJson('/api/v1/customer/errands', $this->payload())->assertCreated();

        return ErrandRequest::query()->latest('id')->firstOrFail();
    }

    private function actingAsCustomer(): void
    {
        Sanctum::actingAs($this->customer);
        $this->withHeader('X-App', 'app');
    }

    private function actingAsDriver(Driver $driver): void
    {
        Sanctum::actingAs(User::query()->findOrFail($driver->user_id));
        $this->withHeader('X-App', 'app');
    }

    private function actingAsAdmin(): void
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($admin);
        $this->withHeader('X-App', 'admin');
    }

    private function makeDriver(): Driver
    {
        $user = User::factory()->create(['type' => 'driver']);

        return Driver::query()->create([
            'user_id' => $user->id,
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'earning_type' => 'fixed',
            'earning_value' => 15,
            'is_active' => true,
            'is_online' => true,
            'is_available' => true,
            'current_lat' => 31.3836,
            'current_lng' => 31.4836,
            'location_updated_at' => now(),
        ]);
    }
}
