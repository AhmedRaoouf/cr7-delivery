<?php

namespace Tests\Feature;

use App\Models\OrderGroup;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\UserAddress;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * D-109 — «طرق الدفع اشيل واحده او اسيب واحده والارقام اللي هيتحول عليها».
 *
 * A switched-off method disappears from every list the customer picks from and is refused
 * by name by every door that takes one; each method that stays carries its own
 * destination.
 */
class PaymentMethodSwitchTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private UserAddress $address;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();

        Settings::set('payment.vodafone_cash_number', '01099450110', 'payment');
        Settings::set('payment.vodafone_cash_number_alt', '01095958686', 'payment');
        Settings::set('payment.instapay_handle', 'cr7delivery@instapay', 'payment');

        $this->customer = $this->makeCustomer();
        $this->address = $this->makeAddress($this->customer);
        $this->restaurant = $this->makeRestaurant('سندوتش عمر');
        $this->makeCart($this->customer, [[$this->restaurant, $this->makeProduct($this->restaurant, 'ساندويتش', 60), 1]]);

        Sanctum::actingAs($this->customer);
        $this->withHeaders(['X-App' => 'app', 'Accept-Language' => 'ar']);
    }

    private function quote(string $method): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/checkout/quote', [
            'address_id' => $this->address->id,
            'payment_method' => $method,
        ]);
    }

    public function test_each_method_carries_its_own_destination(): void
    {
        $methods = collect($this->quote('cash')->assertOk()->json('data.payment_methods'))->keyBy('value');

        $this->assertSame(['cash', 'vodafone_cash', 'instapay'], $methods->keys()->all());
        $this->assertSame('01099450110', $methods['vodafone_cash']['number']);
        $this->assertSame('01095958686', $methods['vodafone_cash']['number_alt']);
        $this->assertArrayNotHasKey('handle', $methods['vodafone_cash']);
        // The InstaPay row names InstaPay's destination — not the wallet number it used to borrow.
        $this->assertSame('cr7delivery@instapay', $methods['instapay']['handle']);
        $this->assertArrayNotHasKey('number', $methods['instapay']);
        $this->assertArrayNotHasKey('number', $methods['cash']);
    }

    public function test_a_switched_off_method_leaves_the_list_and_is_refused_by_name(): void
    {
        Settings::set('payment.vodafone_cash_enabled', false, 'payment');

        $quote = $this->quote('vodafone_cash')->assertOk();

        $this->assertSame(['cash', 'instapay'], collect($quote->json('data.payment_methods'))->pluck('value')->all());
        $this->assertSame('payment_method_disabled', $quote->json('data.blockers.0.code'));
        $this->assertSame('طريقة الدفع هذه غير متاحة حاليًا', $quote->json('data.blockers.0.message'));

        $this->postJson('/api/v1/orders', [
            'address_id' => $this->address->id,
            'payment_method' => 'vodafone_cash',
        ], ['Idempotency-Key' => 'disabled-method'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'payment_method_disabled')
            ->assertJsonPath('message', 'طريقة الدفع هذه غير متاحة حاليًا');

        $this->assertSame(0, OrderGroup::query()->count());

        // The methods still on are untouched.
        $this->quote('instapay')->assertOk()->assertJsonPath('data.blockers', []);
    }

    public function test_the_gate_shows_the_destination_of_the_method_chosen(): void
    {
        $group = $this->placeOnlineOrderWithOpenGate('instapay');

        $this->getJson("/api/v1/orders/{$group->id}/payment")
            ->assertOk()
            ->assertJsonPath('data.destination.value', 'instapay')
            ->assertJsonPath('data.destination.handle', 'cr7delivery@instapay')
            ->assertJsonMissingPath('data.destination.number')
            ->assertJsonPath('data.wallet_number_alt', '01095958686');
    }

    public function test_switching_to_cash_is_refused_while_cash_is_off(): void
    {
        $group = $this->placeOnlineOrderWithOpenGate('vodafone_cash');

        $this->getJson("/api/v1/orders/{$group->id}/payment")
            ->assertOk()
            ->assertJsonPath('data.destination.number', '01099450110')
            ->assertJsonPath('data.destination.number_alt', '01095958686');

        Settings::set('payment.cash_enabled', false, 'payment');

        $this->postJson("/api/v1/orders/{$group->id}/payment/method")
            ->assertStatus(422)
            ->assertJsonPath('error', 'payment_method_disabled');

        $this->assertSame('vodafone_cash', $group->fresh()->payment_method->value);

        Settings::set('payment.cash_enabled', true, 'payment');

        $this->postJson("/api/v1/orders/{$group->id}/payment/method")->assertOk();
        $this->assertSame('cash', $group->fresh()->payment_method->value);
    }

    /**
     * The order is placed through the real endpoint; the restaurants' answers and the
     * customer's confirmation are then written as their actions write them, which is all
     * the gate reads.
     */
    private function placeOnlineOrderWithOpenGate(string $method): OrderGroup
    {
        $this->postJson('/api/v1/orders', [
            'address_id' => $this->address->id,
            'payment_method' => $method,
        ], ['Idempotency-Key' => 'gate-'.$method])->assertCreated();

        $group = OrderGroup::query()->latest('id')->firstOrFail();
        $group->orders()->update(['status' => 'awaiting_payment']);
        $group->payment()->update([
            'status' => 'awaiting_proof',
            'amount' => $group->total,
            'gate_opened_at' => now(),
            'deadline_at' => now()->addMinutes(15),
        ]);

        return $group->fresh();
    }
}
