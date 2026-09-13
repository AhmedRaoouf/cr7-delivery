<?php

namespace Tests\Feature;

use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «عايز نحكم كامل في شكل التطبيق من الادمن» — doc 62.
 *
 * Every switch doc 62 puts on `ADM-46` that a client has to know before it draws a screen
 * is published by `GET /config`: the payment picker, the sign-up form, the address form,
 * the basket, the board's ring and the captain's code field. Neither client keeps its own
 * copy of any of them.
 */
class ConfigPublishesThePanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_defaults_are_published_when_nobody_has_touched_the_panel(): void
    {
        $data = $this->getJson('/api/v1/config')->assertOk()->json('data');

        $this->assertSame(['cash', 'vodafone_cash', 'instapay'], array_column($data['payment']['methods'], 'value'));
        $this->assertTrue($data['payment']['cash_enabled']);

        $this->assertNull($data['auth']['static_otp']);
        $this->assertFalse($data['auth']['otp_test_mode']);
        $this->assertTrue($data['auth']['register_requires_otp']);
        $this->assertTrue($data['auth']['admin_login_by_email']);

        $this->assertSame([['field' => 'street', 'label' => 'الشارع', 'label_en' => 'Street']], $data['addresses']['required_fields']);
        $this->assertTrue($data['addresses']['alternate_phone_allowed']);
        $this->assertTrue($data['addresses']['alternate_phone_requires_otp']);

        $this->assertSame(['max_restaurants' => 2, 'max_qty_per_line' => 10], $data['cart']);
        $this->assertSame(['enabled' => true, 'duration_seconds' => 30, 'gap_seconds' => 60, 'max_attempts' => 3], $data['ring']);
        $this->assertSame(['pickup_code_required' => true, 'delivery_code_required' => true], $data['orders']);
    }

    public function test_every_switch_the_operator_moves_is_what_the_clients_read(): void
    {
        Settings::set('payment.vodafone_cash_enabled', false, 'payment');
        Settings::set('payment.vodafone_cash_number', '01099450110', 'payment');
        Settings::set('payment.vodafone_cash_number_alt', '01095958686', 'payment');
        Settings::set('payment.instapay_handle', '01099450110', 'payment');
        Settings::set('otp.mode', 'test', 'otp');
        Settings::set('otp.test_code', '654321', 'otp');
        Settings::set('auth.register_requires_otp', false, 'otp');
        Settings::set('addresses.required_fields', ['street', 'building'], 'addresses');
        Settings::set('addresses.alternate_phone_requires_otp', false, 'addresses');
        Settings::set('cart.max_restaurants', 3, 'cart');
        Settings::set('cart.max_qty_per_line', 20, 'cart');
        Settings::set('ring.enabled', false, 'ring');
        Settings::set('ring.gap_seconds', 90, 'ring');
        Settings::set('orders.delivery_code_required', false, 'codes');

        $response = $this->getJson('/api/v1/config')->assertOk();

        // The picker: Vodafone Cash gone, InstaPay carrying its own destination.
        $response
            ->assertJsonPath('data.payment.methods', [
                ['value' => 'cash', 'label' => 'الدفع عند الاستلام', 'label_en' => 'Cash on delivery'],
                ['value' => 'instapay', 'label' => 'إنستا باي', 'label_en' => 'InstaPay', 'handle' => '01099450110'],
            ])
            ->assertJsonPath('data.payment.vodafone_cash_enabled', false)
            ->assertJsonPath('data.payment.instapay_enabled', true)
            ->assertJsonPath('data.payment.vodafone_cash_number', '01099450110')
            ->assertJsonPath('data.payment.vodafone_cash_number_alt', '01095958686')
            ->assertJsonPath('data.payment.instapay_handle', '01099450110');

        $response
            ->assertJsonPath('data.auth.static_otp', '654321')
            ->assertJsonPath('data.auth.otp_test_mode', true)
            ->assertJsonPath('data.auth.register_requires_otp', false);

        $response
            ->assertJsonPath('data.addresses.required_fields.1.field', 'building')
            ->assertJsonPath('data.addresses.required_fields.1.label', 'رقم العمارة')
            ->assertJsonPath('data.addresses.alternate_phone_requires_otp', false);

        $response
            ->assertJsonPath('data.cart.max_restaurants', 3)
            ->assertJsonPath('data.cart.max_qty_per_line', 20)
            ->assertJsonPath('data.ring.enabled', false)
            ->assertJsonPath('data.ring.gap_seconds', 90)
            ->assertJsonPath('data.orders.pickup_code_required', true)
            ->assertJsonPath('data.orders.delivery_code_required', false);
    }
}
