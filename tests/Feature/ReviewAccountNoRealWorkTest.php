<?php

namespace Tests\Feature;

use App\Actions\Restaurant\CreateDirectOrderAction;
use App\Enums\ErrandStatus;
use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\ErrandRequest;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\Restaurant;
use App\Models\ScheduledOrder;
use App\Models\User;
use App\Models\UserAddress;
use App\Support\Settings;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A store reviewer may browse, sign in and quote — and may never put real work into a
 * production platform: no order, no scheduled order, no errand, no direct order, and the
 * review captain is never asked for, nor shown, a real customer's job.
 *
 * Every refusal is asserted twice: the answer, and the rows that must not exist. The
 * control in each test is an ordinary account doing the same thing and succeeding, so the
 * guard is proved to be keyed on the review list and on nothing else.
 */
class ReviewAccountNoRealWorkTest extends TestCase
{
    use RefreshDatabase;

    private const REVIEW_CUSTOMER = '01099900101';

    private const REVIEW_CAPTAIN = '01099900102';

    private const REVIEW_PLACE = '01099900103';

    private User $review;

    private UserAddress $reviewAddress;

    private User $real;

    private UserAddress $realAddress;

    private Restaurant $place;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation(deliveryFee: 20.0);
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);
        Settings::set('payment.vodafone_cash_number', '01099450110', 'payment');
        Settings::set('payment.instapay_handle', 'cr7delivery@instapay', 'payment');

        config([
            'services.review.phones' => self::REVIEW_CUSTOMER.','.self::REVIEW_CAPTAIN.','.self::REVIEW_PLACE,
            'services.review.code' => '424242',
        ]);

        $this->place = $this->makeRestaurant('سعد الدمياطي');

        $this->review = $this->makeCustomer();
        $this->review->update(['phone' => self::REVIEW_CUSTOMER]);
        $this->reviewAddress = $this->makeAddress($this->review);

        $this->real = $this->makeCustomer();
        $this->realAddress = $this->makeAddress($this->real);
    }

    // ---------------------------------------------------------------- orders

    public function test_a_review_customer_can_quote_but_not_place_an_order(): void
    {
        $this->makeCart($this->review, [[$this->place, $this->makeProduct($this->place, 'صينية', 100), 1]]);
        $this->as($this->review);

        $this->postJson('/api/v1/checkout/quote', ['address_id' => $this->reviewAddress->id, 'payment_method' => 'cash'])
            ->assertOk();

        $this->postJson('/api/v1/orders', ['address_id' => $this->reviewAddress->id, 'payment_method' => 'cash'], ['Idempotency-Key' => 'review-1'])
            ->assertForbidden()
            ->assertJsonPath('error', 'review_account_demo_only');

        $this->assertSame(0, OrderGroup::query()->count());
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Notification::query()->count(), 'no place, captain or customer was notified');
        $this->assertSame(1, $this->review->cart()->first()->items()->count(), 'the basket is left as it was');
    }

    public function test_an_ordinary_customer_still_places_an_order(): void
    {
        $this->makeCart($this->real, [[$this->place, $this->makeProduct($this->place, 'صينية', 100), 1]]);
        $this->as($this->real);

        $this->postJson('/api/v1/orders', ['address_id' => $this->realAddress->id, 'payment_method' => 'cash'], ['Idempotency-Key' => 'real-1'])
            ->assertCreated();

        $this->assertSame(1, OrderGroup::query()->count());
    }

    public function test_a_review_customer_cannot_schedule_an_order(): void
    {
        $this->makeCart($this->review, [[$this->place, $this->makeProduct($this->place, 'صينية', 100), 1]]);
        $this->as($this->review);

        $this->postJson('/api/v1/scheduled-orders', [
            'address_id' => $this->reviewAddress->id,
            'payment_method' => 'cash',
            'scheduled_for' => now()->addHours(3)->toIso8601String(),
        ])->assertForbidden()->assertJsonPath('error', 'review_account_demo_only');

        $this->assertSame(0, ScheduledOrder::query()->count());
    }

    public function test_an_ordinary_customer_still_schedules_an_order(): void
    {
        $this->makeCart($this->real, [[$this->place, $this->makeProduct($this->place, 'صينية', 100), 1]]);
        $this->as($this->real);

        $this->postJson('/api/v1/scheduled-orders', [
            'address_id' => $this->realAddress->id,
            'payment_method' => 'cash',
            'scheduled_for' => now()->addHours(3)->toIso8601String(),
        ])->assertCreated();

        $this->assertSame(1, ScheduledOrder::query()->count());
    }

    // ---------------------------------------------------------------- errands

    public function test_a_review_customer_cannot_publish_an_errand(): void
    {
        $this->makeCaptain(self::REVIEW_CAPTAIN);
        $this->as($this->review);

        $this->postJson('/api/v1/customer/errands', $this->errand())
            ->assertForbidden()
            ->assertJsonPath('error', 'review_account_demo_only');

        $this->assertSame(0, ErrandRequest::query()->count());
        $this->assertSame(0, Notification::query()->where('type', 'errand.new')->count());
    }

    public function test_an_ordinary_customer_still_publishes_an_errand(): void
    {
        $this->as($this->real);

        $this->postJson('/api/v1/customer/errands', $this->errand())->assertCreated();

        $this->assertSame(1, ErrandRequest::query()->count());
    }

    public function test_the_review_captain_is_never_told_about_a_real_errand_but_a_real_captain_is(): void
    {
        $review = $this->makeCaptain(self::REVIEW_CAPTAIN);
        $real = $this->makeCaptain('01011122233');

        $this->as($this->real);
        $this->postJson('/api/v1/customer/errands', $this->errand())->assertCreated();
        $this->travel(3)->minutes();
        $this->artisan('cr7:tick --once');

        $this->assertSame(0, Notification::query()->where('user_id', $review->user_id)->where('type', 'errand.new')->count());
        $this->assertSame(1, Notification::query()->where('user_id', $real->user_id)->where('type', 'errand.new')->count());
    }

    public function test_the_review_captain_sees_an_empty_board_and_cannot_take_a_real_errand(): void
    {
        $review = $this->makeCaptain(self::REVIEW_CAPTAIN);
        $real = $this->makeCaptain('01011122233');

        $this->as($this->real);
        $this->postJson('/api/v1/customer/errands', $this->errand())->assertCreated();
        $errand = ErrandRequest::query()->firstOrFail();

        $this->as(User::query()->findOrFail($real->user_id));
        $this->assertCount(1, $this->getJson('/api/v1/driver/errands/board')->assertOk()->json('data'));

        $this->as(User::query()->findOrFail($review->user_id));
        $this->assertSame([], $this->getJson('/api/v1/driver/errands/board')->assertOk()->json('data'));

        $this->postJson("/api/v1/driver/errands/{$errand->id}/accept")
            ->assertForbidden()
            ->assertJsonPath('error', 'review_account_demo_only');

        $errand->refresh();
        $this->assertSame(ErrandStatus::Open, $errand->status);
        $this->assertNull($errand->driver_id);
    }

    // ---------------------------------------------------------------- direct orders

    public function test_a_review_place_owner_cannot_create_a_direct_order(): void
    {
        $owner = User::query()->create([
            'name' => 'مراجع المتجر', 'phone' => self::REVIEW_PLACE, 'type' => 'restaurant',
            'is_active' => true, 'phone_verified_at' => now(),
        ]);

        try {
            app(CreateDirectOrderAction::class)->execute($owner, $this->place, [], 'review-direct-1');
            $this->fail('a review place owner must not create a direct order');
        } catch (ApiException $e) {
            $this->assertSame('review_account_demo_only', $e->errorCode);
            $this->assertSame(403, $e->status);
            $this->assertSame(0, Order::query()->count());
        }
    }

    public function test_the_guard_is_keyed_on_the_list_and_nothing_else(): void
    {
        config(['services.review.phones' => null]);
        $this->makeCart($this->review, [[$this->place, $this->makeProduct($this->place, 'صينية', 100), 1]]);
        $this->as($this->review);

        // The same account, the list empty: it is an ordinary customer again.
        $this->postJson('/api/v1/orders', ['address_id' => $this->reviewAddress->id, 'payment_method' => 'cash'], ['Idempotency-Key' => 'off-1'])
            ->assertCreated();
    }

    // ---------------------------------------------------------------- helpers

    private function as(User $user): void
    {
        Sanctum::actingAs($user);
        $this->withHeaders(['X-App' => 'app', 'Accept-Language' => 'ar']);
    }

    private function makeCaptain(string $phone): Driver
    {
        $user = User::factory()->create(['type' => 'driver', 'phone' => $phone]);

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

    /** @return array<string, mixed> */
    private function errand(): array
    {
        return [
            'pickup_lat' => 31.3835,
            'pickup_lng' => 31.4835,
            'pickup_address_text' => 'صيدلية النور — شارع البحر',
            'dropoff_lat' => 31.3845,
            'dropoff_lng' => 31.4845,
            'dropoff_address_text' => 'عمارات مبارك — الدور الثالث',
            'dropoff_contact_phone' => '01099887766',
            'details' => 'من فضلك اشترِ علبة باراسيتامول وأحضرها إلى العنوان',
            'offered_fee' => 45,
        ];
    }
}
