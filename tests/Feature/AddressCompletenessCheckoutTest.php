<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\ScheduledOrder;
use App\Models\User;
use App\Models\UserAddress;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * D-111 — «ولو فيه عنوان ناقص قله قبل ما بيأكد الاوردر انه لازم يكمل كذا في العنوان».
 *
 * An address may be saved incomplete and may not be ordered to, and the refusal names
 * what is missing in the customer's words. Plus the other half of the same screen: an
 * address that is not the customer's is a sentence, not a 404.
 */
class AddressCompletenessCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation(deliveryFee: 20.0);

        $this->customer = $this->makeCustomer();
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $this->makeCart($this->customer, [[$restaurant, $this->makeProduct($restaurant, 'ساندويتش', 60), 1]]);

        Sanctum::actingAs($this->customer);
        $this->withHeaders(['X-App' => 'app', 'Accept-Language' => 'ar']);
    }

    /** The first-launch address of D-110: a pin and an area, and nothing typed. */
    private function pinOnlyAddress(): UserAddress
    {
        $id = $this->postJson('/api/v1/addresses', [
            'label' => 'home',
            'zone_id' => $this->zone->id,
            'latitude' => 31.3835,
            'longitude' => 31.4835,
            'is_default' => true,
        ])->assertCreated()->json('data.id');

        return UserAddress::query()->findOrFail($id);
    }

    private function order(int $addressId, string $key = 'complete-test'): TestResponse
    {
        return $this->postJson('/api/v1/orders', [
            'address_id' => $addressId,
            'payment_method' => 'cash',
        ], ['Idempotency-Key' => $key]);
    }

    public function test_an_address_without_a_street_is_saved_and_says_what_it_lacks(): void
    {
        $address = $this->pinOnlyAddress();

        $row = collect($this->getJson('/api/v1/addresses')->assertOk()->json('data'))->firstWhere('id', $address->id);

        $this->assertFalse($row['is_complete']);
        $this->assertSame([['field' => 'street', 'label' => 'الشارع', 'label_en' => 'Street']], $row['missing_fields']);
    }

    public function test_checkout_names_the_missing_fields_before_it_takes_the_order(): void
    {
        $address = $this->pinOnlyAddress();

        $quote = $this->postJson('/api/v1/checkout/quote', ['address_id' => $address->id, 'payment_method' => 'cash'])
            ->assertOk();

        $blocker = collect($quote->json('data.blockers'))->firstWhere('code', 'address_incomplete');
        $this->assertSame('أكمل بيانات العنوان قبل تأكيد الطلب: الشارع', $blocker['message']);
        $this->assertSame($address->id, $blocker['address_id']);
        $this->assertSame('street', $blocker['missing_fields'][0]['field']);

        $this->order($address->id)
            ->assertStatus(422)
            ->assertJsonPath('error', 'address_incomplete')
            ->assertJsonPath('message', 'أكمل بيانات العنوان قبل تأكيد الطلب: الشارع');

        $this->assertSame(0, OrderGroup::query()->count());

        // Completed in the form, it goes through.
        $this->patchJson("/api/v1/addresses/{$address->id}", ['street' => 'شارع البحر'])
            ->assertOk()
            ->assertJsonPath('data.is_complete', true)
            ->assertJsonPath('data.missing_fields', []);

        $this->order($address->id)->assertCreated();
    }

    public function test_the_required_fields_are_the_operators(): void
    {
        $address = $this->makeAddress($this->customer);   // a street, nothing else

        Settings::set('addresses.required_fields', ['street', 'building', 'floor'], 'addresses');

        $this->order($address->id)
            ->assertStatus(422)
            ->assertJsonPath('error', 'address_incomplete')
            ->assertJsonPath('message', 'أكمل بيانات العنوان قبل تأكيد الطلب: رقم العمارة، الدور');

        // Nothing required but the pin and the area — which the form never lets go.
        Settings::set('addresses.required_fields', [], 'addresses');
        $pinOnly = $this->pinOnlyAddress();

        $this->order($pinOnly->id, 'pin-only')->assertCreated();
        $this->assertSame(1, Order::query()->count());
    }

    public function test_an_address_that_is_not_yours_is_a_sentence_not_a_404(): void
    {
        $stranger = $this->makeCustomer();
        $theirs = $this->makeAddress($stranger);

        $mine = $this->makeAddress($this->customer);
        $mine->delete();

        foreach ([$theirs->id, $mine->id, 999999] as $id) {
            $this->postJson('/api/v1/checkout/quote', ['address_id' => $id, 'payment_method' => 'cash'])
                ->assertStatus(422)
                ->assertJsonPath('error', 'address_not_found')
                ->assertJsonPath('message', 'العنوان غير موجود — اختر عنوانًا من عناوينك')
                ->assertJsonPath('errors.address_id.0', 'العنوان غير موجود — اختر عنوانًا من عناوينك');

            $this->order($id, 'not-mine-'.$id)
                ->assertStatus(422)
                ->assertJsonPath('error', 'address_not_found');

            $this->postJson('/api/v1/scheduled-orders', [
                'address_id' => $id,
                'payment_method' => 'cash',
                'scheduled_for' => now()->addHours(3)->toIso8601String(),
            ])->assertStatus(422)->assertJsonPath('error', 'address_not_found');
        }

        $this->assertSame(0, OrderGroup::query()->count());
        $this->assertSame(0, ScheduledOrder::query()->count());
    }

    public function test_the_quote_says_why_the_delivery_fee_is_zero(): void
    {
        $address = $this->makeAddress($this->customer);

        // A brand-new account: the platform's welcome offer.
        $this->postJson('/api/v1/checkout/quote', ['address_id' => $address->id, 'payment_method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('data.delivery_fee', 0)
            ->assertJsonPath('data.first_order_free_applied', true)
            ->assertJsonPath('data.free_delivery_reason', 'first_order');

        Settings::set('delivery.first_order_free', false, 'delivery');

        $this->postJson('/api/v1/checkout/quote', ['address_id' => $address->id, 'payment_method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('data.free_delivery_reason', null);

        // The area's own threshold.
        $this->zone->update(['free_delivery_over' => 50]);

        $this->postJson('/api/v1/checkout/quote', ['address_id' => $address->id, 'payment_method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('data.free_delivery_applied', true)
            ->assertJsonPath('data.free_delivery_reason', 'threshold');
    }
}
