<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\Driver;
use App\Models\ErrandRequest;
use App\Models\User;
use App\Support\ErrandDispatcher;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SendsPushesAfterTheResponse;
use Tests\TestCase;

/**
 * doc 40 — a **tapped** errand push has to land on the errand.
 *
 * The notification row carries `deep_link` and the centre routes from it, so «الإشعارات»
 * always opened the right screen. A push tapped in the system tray has no row in its
 * hand: `PushDestination` reads `data['errand_id']`, and answers nothing without it —
 * the tap merely opened the app. Only `errand.new` ever carried it, so every other
 * errand push — the captain's «المشوار مِلكك», the customer's «الكابتن في الطريق», the
 * operator's offer — was a notification you could not follow.
 *
 * Asserted over the path each one really takes. Everything an endpoint sends leaves
 * through `afterResponse()`, which over HTTP serialises the push closure's source and
 * parses it back (2026-09-14); the operator's escalation is the cron's, and runs inline.
 */
class ErrandPushesCarryTheirIdTest extends TestCase
{
    use RefreshDatabase;
    use SendsPushesAfterTheResponse;

    private User $customer;

    private Driver $captain;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);
        $this->configureFirebase();
        $this->fakeFcm();

        $this->customer = $this->phone($this->makeCustomer(), 'customer-phone');
        $this->captain = $this->makeCaptain('captain-phone');
        $this->operator = $this->makeOperator();
    }

    // ------------------------------------------------------------------ tests

    public function test_the_board_push_carries_the_errand(): void
    {
        $this->pretendThisIsAWebRequest();

        $this->asCustomer()->postJson('/api/v1/customer/errands', $this->payload())->assertCreated();

        $this->assertCarriesTheErrand('captain-phone', 'errand.new', $this->latestErrand());
    }

    public function test_the_two_pushes_of_an_accept_carry_the_errand(): void
    {
        $errand = $this->publish();

        $this->pretendThisIsAWebRequest();

        $this->asCaptain($this->captain)
            ->postJson("/api/v1/driver/errands/{$errand->id}/accept")
            ->assertOk();

        $this->assertCarriesTheErrand('captain-phone', 'errand.taken', $errand);
        $this->assertCarriesTheErrand('customer-phone', 'errand.assigned_customer', $errand);
    }

    public function test_an_operators_offer_and_the_captains_refusal_carry_the_errand(): void
    {
        $errand = $this->publish();

        $this->pretendThisIsAWebRequest();

        $this->asOperator()
            ->postJson("/api/v1/admin/errands/{$errand->id}/assign", ['driver_id' => $this->captain->id])
            ->assertOk();

        $this->assertCarriesTheErrand('captain-phone', 'errand.offered', $errand);

        $this->asCaptain($this->captain)
            ->postJson("/api/v1/driver/errands/{$errand->id}/decline", ['reason' => 'بعيد عني'])
            ->assertOk();

        $this->assertCarriesTheErrand('operator-phone', 'errand.offer_declined', $errand);
    }

    public function test_the_pickup_and_the_delivery_carry_the_errand(): void
    {
        $errand = $this->publish();
        $this->asCaptain($this->captain)->postJson("/api/v1/driver/errands/{$errand->id}/accept")->assertOk();

        $this->pretendThisIsAWebRequest();

        $this->asCaptain($this->captain)->postJson("/api/v1/driver/errands/{$errand->id}/picked-up")->assertOk();
        $this->assertCarriesTheErrand('customer-phone', 'errand.picked_up_customer', $errand);

        $this->asCaptain($this->captain)->postJson("/api/v1/driver/errands/{$errand->id}/deliver")->assertOk();
        $this->assertCarriesTheErrand('customer-phone', 'errand.delivered_customer', $errand);
    }

    public function test_the_captain_told_the_errand_is_off_is_told_which_errand(): void
    {
        $errand = $this->publish();
        $this->asCaptain($this->captain)->postJson("/api/v1/driver/errands/{$errand->id}/accept")->assertOk();

        $this->pretendThisIsAWebRequest();

        $this->asCustomer()->postJson("/api/v1/customer/errands/{$errand->id}/cancel")->assertOk();

        $this->assertCarriesTheErrand('captain-phone', 'errand.cancelled_driver', $errand);
    }

    /** A reassignment tells the captain who had it, and offers it to the one who has it now. */
    public function test_a_reassignment_names_the_errand_to_both_captains(): void
    {
        $errand = $this->publish();
        $this->asCaptain($this->captain)->postJson("/api/v1/driver/errands/{$errand->id}/accept")->assertOk();
        $second = $this->makeCaptain('second-phone');

        $this->pretendThisIsAWebRequest();

        $this->asOperator()
            ->postJson("/api/v1/admin/errands/{$errand->id}/assign", ['driver_id' => $second->id])
            ->assertOk();

        $this->assertCarriesTheErrand('captain-phone', 'errand.cancelled_driver', $errand);
        $this->assertCarriesTheErrand('second-phone', 'errand.offered', $errand);
    }

    /**
     * The cron's, not an endpoint's: `cr7:tick` runs in the console, where the push is
     * sent in place rather than after a response there is none of.
     */
    public function test_the_operators_escalation_carries_the_errand(): void
    {
        $errand = $this->publish();

        $this->travel(10)->minutes();
        $this->assertSame(1, app(ErrandDispatcher::class)->escalateStranded());

        $this->assertCarriesTheErrand('operator-phone', 'errand.no_driver_admin', $errand);
    }

    // ---------------------------------------------------------------- helpers

    /** Every push of this type that left, and the errand each one points at. */
    private function assertCarriesTheErrand(string $token, string $type, ErrandRequest $errand): void
    {
        $pushes = $this->pushesOfType($token, $type);

        $this->assertNotEmpty($pushes, "{$type} never reached {$token}");

        foreach ($pushes as $push) {
            $this->assertSame((string) $errand->id, $push['data']['errand_id'] ?? null, "{$type} carries no errand id");
            $this->assertSame('errand', $push['data']['kind'] ?? null, "{$type} does not say what the id is");
        }
    }

    private function publish(): ErrandRequest
    {
        $this->asCustomer()->postJson('/api/v1/customer/errands', $this->payload())->assertCreated();

        return $this->latestErrand();
    }

    private function latestErrand(): ErrandRequest
    {
        return ErrandRequest::query()->latest('id')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'pickup_lat' => 31.3835,
            'pickup_lng' => 31.4835,
            'pickup_address_text' => 'صيدلية النور — شارع البحر',
            'dropoff_lat' => 31.3845,
            'dropoff_lng' => 31.4845,
            'dropoff_address_text' => 'عمارات مبارك — الدور الثالث',
            'dropoff_contact_phone' => '01099887766',
            'details' => 'علبة باراسيتامول',
            'offered_fee' => 45,
        ];
    }

    private function phone(User $user, string $token): User
    {
        $user->forceFill(['locale' => 'ar'])->save();
        $user->deviceTokens()->create([
            'token' => $token,
            'platform' => 'android',
            'app' => $user->type->value === 'driver' ? 'driver' : ($user->type->value === 'admin' ? 'admin' : 'customer'),
            'device_id' => $token.'-device',
        ]);

        return $user;
    }

    private function makeCaptain(string $token): Driver
    {
        $user = $this->phone(User::factory()->create(['type' => 'driver']), $token);

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
        ])->load('user');
    }

    private function makeOperator(): User
    {
        $admin = $this->phone(User::factory()->create(['type' => 'admin']), 'operator-phone');
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        return $admin;
    }

    private function asCustomer(): static
    {
        Sanctum::actingAs($this->customer);

        return $this->withHeaders(['X-App' => 'app', 'Accept-Language' => 'ar']);
    }

    private function asCaptain(Driver $captain): static
    {
        Sanctum::actingAs($captain->user);

        return $this->withHeaders(['X-App' => 'app', 'Accept-Language' => 'ar']);
    }

    private function asOperator(): static
    {
        Sanctum::actingAs($this->operator);

        return $this->withHeaders(['X-App' => 'admin', 'Accept-Language' => 'ar']);
    }
}
