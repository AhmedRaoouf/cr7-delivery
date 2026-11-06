<?php

namespace Tests\Feature;

use App\Actions\Restaurant\StartPreparingAction;
use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Restaurant;
use App\Support\DriverPresence;
use App\Support\Settings;
use App\Support\SettingsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTrips;
use Tests\TestCase;

/**
 * 2026-11-06 — `dispatch.strategy = auto_offer`: the nearest free connected captains are
 * offered the order ten minutes before it is due, and each accepts or refuses.
 */
class EarlyOfferTest extends TestCase
{
    use BuildsTrips;
    use RefreshDatabase;

    private Restaurant $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        Settings::set('delivery.first_order_free', false);
        Settings::set('ring.enabled', false);
        Settings::set('dispatch.strategy', 'auto_offer');
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'UTC'));

        $this->home = $this->makeRestaurant('كنج بروست', 31.3840, 31.4840);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_panel_accepts_the_new_strategy(): void
    {
        SettingsCatalog::validate(['dispatch.strategy' => 'auto_offer']);
        $this->assertSame('auto_offer', SettingsCatalog::get('dispatch.strategy'));
    }

    public function test_a_short_countdown_offers_at_once_and_the_order_keeps_cooking(): void
    {
        $near = $this->connected($this->captain('كابتن قريب', 31.3845, 31.4845));
        $other = $this->connected($this->captain('كابتن تاني', 31.3900, 31.4845));
        $order = $this->confirmed($this->single($this->home), 7);

        app(StartPreparingAction::class)->execute($this->home->owner, $order, 'P-7');

        $fresh = $order->fresh();
        $this->assertNull($fresh->driver_id, 'nobody is assigned');
        $this->assertSame(OrderStatus::Preparing, $fresh->status, 'the kitchen keeps the order');
        $this->assertSame(1, $near->driverAttempts()->where('order_id', $order->id)->where('result', 'sent')->count());
        $this->assertSame(1, $other->driverAttempts()->where('order_id', $order->id)->where('result', 'sent')->count());

        $this->asCaptain($near)->getJson('/api/v1/driver/offers')
            ->assertOk()
            ->assertJsonPath('data.0.order_id', $order->id);
    }

    public function test_an_online_captain_whose_app_is_in_the_background_is_offered_too(): void
    {
        // Switch on, a fix three minutes old, no request in the last two minutes.
        $pocket = $this->captain('كابتن في جيبه', 31.3845, 31.4845, ['location_updated_at' => now()->subMinutes(3)]);
        $order = $this->confirmed($this->single($this->home), 7);

        app(StartPreparingAction::class)->execute($this->home->owner, $order, 'P-7');

        $this->assertSame(1, $pocket->driverAttempts()->where('order_id', $order->id)->where('result', 'sent')->count());
    }

    public function test_accepting_takes_the_order_while_it_cooks(): void
    {
        $near = $this->connected($this->captain('كابتن قريب', 31.3845, 31.4845));
        $other = $this->connected($this->captain('كابتن تاني', 31.3900, 31.4845));
        $order = $this->confirmed($this->single($this->home), 7);
        app(StartPreparingAction::class)->execute($this->home->owner, $order, 'P-7');

        $this->acceptAs($near, $order)->assertOk();

        $fresh = $order->fresh();
        $this->assertSame($near->id, $fresh->driver_id);
        $this->assertSame(OrderStatus::DriverAssigned, $fresh->status);
        $this->assertSame('accept', $fresh->assigned_via);
        $this->assertNull($fresh->ready_at, 'the food is still cooking');
        $this->assertSame('superseded', $other->driverAttempts()->where('order_id', $order->id)->first()->result->value);

        $this->acceptAs($other, $order)->assertStatus(409);
    }

    public function test_a_long_countdown_waits_until_the_lead(): void
    {
        $captain = $this->connected($this->captain('كابتن قريب', 31.3845, 31.4845));
        $order = $this->confirmed($this->single($this->home), 15);
        app(StartPreparingAction::class)->execute($this->home->owner, $order, 'P-15');

        $this->assertSame(0, $order->driverAttempts()->count(), '15 left, lead 10: not yet');

        $this->travel(5)->minutes();
        $this->keepConnected($captain);
        $this->tick();

        $this->assertSame(1, $captain->driverAttempts()->where('order_id', $order->id)->where('result', 'sent')->count());
        $this->assertNull($order->fresh()->driver_id);
        $this->assertSame(OrderStatus::Preparing, $order->fresh()->status);
    }

    public function test_a_refusal_is_not_asked_again_while_it_cooks_and_ready_hands_it_to_dispatch(): void
    {
        $refuses = $this->connected($this->captain('كابتن رافض', 31.3845, 31.4845));
        $waits = $this->connected($this->captain('كابتن متردد', 31.3900, 31.4845));
        $order = $this->confirmed($this->single($this->home), 7);
        app(StartPreparingAction::class)->execute($this->home->owner, $order, 'P-7');

        $this->asCaptain($refuses)->postJson("/api/v1/driver/orders/{$order->id}/reject", ['reason_code' => 'busy'])->assertOk();

        $this->travel(3)->minutes();
        $this->keepConnected($refuses, $waits);
        $this->tick();
        $this->assertSame(1, $refuses->driverAttempts()->where('order_id', $order->id)->count(), 'one row, the refusal');

        // Food ready, nobody took it: the open offer still stands and the order is searching.
        $ready = $this->ready($order->fresh());
        $this->assertSame(OrderStatus::SearchingDriver, $ready->fresh()->status);
        $this->acceptAs($waits, $order)->assertOk();
        $this->assertSame($waits->id, $order->fresh()->driver_id);
        $this->assertNotNull($order->fresh()->ready_at);
    }

    public function test_a_captain_stepping_off_a_cooking_order_sends_it_back_to_the_kitchen_and_others_are_offered(): void
    {
        $first = $this->connected($this->captain('كابتن أول', 31.3845, 31.4845));
        $second = $this->connected($this->captain('كابتن تاني', 31.3900, 31.4845));
        $order = $this->confirmed($this->single($this->home), 7);
        app(StartPreparingAction::class)->execute($this->home->owner, $order, 'P-7');
        $this->acceptAs($first, $order)->assertOk();

        $this->asCaptain($first)->postJson("/api/v1/driver/orders/{$order->id}/cancel", ['reason_code' => 'other', 'note' => 'عطل'])
            ->assertOk();

        $fresh = $order->fresh();
        $this->assertNull($fresh->driver_id);
        $this->assertSame(OrderStatus::Preparing, $fresh->status);
        $this->assertSame(1, $second->driverAttempts()->where('order_id', $order->id)->where('result', 'sent')->count());
        $this->assertSame(0, $first->driverAttempts()->where('order_id', $order->id)->where('result', 'sent')->count());
    }

    // ------------------------------------------------------------------ helpers

    private function single(Restaurant $place): Order
    {
        return $this->basket([$place])->first();
    }

    private function confirmed(Order $order, int $prepMinutes): Order
    {
        $order->update(['status' => OrderStatus::Confirmed, 'prep_minutes' => $prepMinutes, 'confirmed_at' => now()]);

        return $order->fresh();
    }

    private function connected(Driver $captain): Driver
    {
        DriverPresence::touch($captain);

        return $captain;
    }

    private function keepConnected(Driver ...$captains): void
    {
        foreach ($captains as $captain) {
            $captain->update(['location_updated_at' => now()]);
            DriverPresence::touch($captain->fresh());
        }
    }

    private function tick(): void
    {
        $this->artisan('cr7:tick', ['--once' => true])->assertSuccessful();
    }
}
