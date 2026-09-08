<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Banner;
use App\Models\HomePopup;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\User;
use App\Support\CheckoutPricer;
use App\Support\FirstOrderPerk;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * «خلي اول اورد للحساب توصيل مجاني واستخدمها بقا في العروض اللي بتظهر اول ما تدخل
 * الرئيسيه» — operator, 2026-09-08.
 *
 * Two halves that have to agree, which is why they are tested together: the offer
 * itself, and the slide that announces it. A banner promising free delivery to an
 * account that will be charged for it is worse than no banner at all.
 */
class FirstOrderFreeDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
    }

    private function quoteFor(User $customer): array
    {
        $address = $this->makeAddress($customer);
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $product = $this->makeProduct($restaurant, 'سندوتش جمبري', 100);
        $cart = $this->makeCart($customer, [[$restaurant, $product, 1]]);

        return app(CheckoutPricer::class)->price($customer, $cart, $address, null, 'cash');
    }

    /** An order in a state that means this account has already been served. */
    private function giveAnOrder(User $customer, OrderStatus $status): void
    {
        $group = OrderGroup::query()->create([
            'user_id' => $customer->id,
            'group_number' => 'G-'.random_int(1000, 9999),
            'idempotency_key' => (string) Str::uuid(),
            'city_id' => $this->city->id,
            'service_zone_id' => $this->zone->id,
            'payment_method' => 'cash',
            'restaurant_count' => 1,
            'subtotal' => 100,
            'delivery_fee' => 20,
            'total' => 120,
        ]);

        Order::query()->create([
            'order_group_id' => $group->id,
            'user_id' => $customer->id,
            'restaurant_id' => $this->makeRestaurant('مكان سابق')->id,
            'city_id' => $this->city->id,
            'service_zone_id' => $this->zone->id,
            'order_number' => 'O-'.random_int(1000, 9999),
            'idempotency_key' => (string) Str::uuid(),
            'group_sequence' => 1,
            'status' => $status->value,
            'payment_method' => 'cash',
            'restaurant_name_snapshot' => 'مكان سابق',
            'customer_name_snapshot' => 'أحمد',
            'delivery_address_text' => 'شارع البحر',
            'delivery_contact_phone' => $customer->phone,
            'subtotal' => 100,
            'delivery_fee_share' => 20,
            'total' => 120,
        ]);
    }

    public function test_the_first_order_on_an_account_pays_no_delivery(): void
    {
        $quote = $this->quoteFor($this->makeCustomer());

        $this->assertTrue($quote['first_order_free_applied']);
        $this->assertSame(0.0, $quote['delivery_fee']);
        // The base fee is still reported: `CUS-21` strikes it through to show what the
        // offer is worth, and a quote that hid it could not.
        $this->assertSame(20.0, $quote['delivery_base_fee']);
        // The service fee is untouched: D-63 makes it neither delivery nor commission,
        // and the offer is about delivery. 100 subtotal + 0 delivery + the fee.
        $this->assertSame(100.0 + $quote['platform_fee'], $quote['total']);
    }

    public function test_an_account_that_has_been_served_pays_the_fee(): void
    {
        $customer = $this->makeCustomer();
        $this->giveAnOrder($customer, OrderStatus::Delivered);

        $quote = $this->quoteFor($customer);

        $this->assertFalse($quote['first_order_free_applied']);
        $this->assertSame(20.0, $quote['delivery_fee']);
    }

    public function test_a_cancelled_first_attempt_does_not_burn_the_offer(): void
    {
        // A shop that refused, or an order cancelled while cancelling was still free,
        // is not a delivery. Charging for the next one would punish the customer for
        // somebody else's decision.
        $customer = $this->makeCustomer();
        $this->giveAnOrder($customer, OrderStatus::Cancelled);

        $this->assertTrue($this->quoteFor($customer)['first_order_free_applied']);
    }

    public function test_an_order_already_on_the_road_spends_it(): void
    {
        // A second order placed while the first is still being carried is not a first
        // order, even though nothing has been delivered yet.
        $customer = $this->makeCustomer();
        $this->giveAnOrder($customer, OrderStatus::OnTheWay);

        $this->assertFalse($this->quoteFor($customer)['first_order_free_applied']);
    }

    public function test_the_operator_can_switch_it_off(): void
    {
        Settings::set(FirstOrderPerk::SETTING, 0, 'delivery');

        $quote = $this->quoteFor($this->makeCustomer());

        $this->assertFalse($quote['first_order_free_applied']);
        $this->assertSame(20.0, $quote['delivery_fee']);
    }

    public function test_the_slide_is_shown_only_to_the_accounts_it_is_true_for(): void
    {
        $newAccountOnly = Banner::query()->create([
            'title_ar' => 'أول طلب لك علينا',
            'image_ar' => 'https://example.test/first.png',
            'audience' => 'first_order',
            'is_active' => true,
        ]);
        Banner::query()->create([
            'title_ar' => 'عرض عام',
            'image_ar' => 'https://example.test/all.png',
            'audience' => 'all',
            'is_active' => true,
        ]);

        // Signed out: the platform cannot know whether this visitor is new, so the
        // offer that depends on knowing is not shown.
        $this->getJson('/api/v1/banners')
            ->assertOk()
            ->assertJsonMissing(['id' => $newAccountOnly->id]);

        $newcomer = $this->makeCustomer();
        $this->actingAs($newcomer, 'sanctum')
            ->getJson('/api/v1/banners')
            ->assertOk()
            ->assertJsonFragment(['id' => $newAccountOnly->id]);

        $returning = $this->makeCustomer();
        $this->giveAnOrder($returning, OrderStatus::Delivered);
        $this->actingAs($returning, 'sanctum')
            ->getJson('/api/v1/banners')
            ->assertOk()
            ->assertJsonMissing(['id' => $newAccountOnly->id]);
    }

    public function test_the_popup_queue_is_narrowed_the_same_way_as_the_banners(): void
    {
        // «العرض المنبثق» carries the same six destinations as a banner by design and
        // is authored from the same page, so «who sees it» has to mean the same thing
        // on both — otherwise the free-delivery card could be shown as a popup to an
        // account the banner beside it already knows will be charged.
        $newAccountOnly = HomePopup::query()->create([
            'title_ar' => 'أول طلب لك علينا',
            'body_ar' => 'التوصيل مجاني على أول طلب.',
            'primary_label_ar' => 'اطلب الآن',
            'link_type' => 'none',
            'audience' => 'first_order',
            'frequency' => 'once',
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/home-popups')
            ->assertOk()
            ->assertJsonMissing(['id' => $newAccountOnly->id]);

        $this->actingAs($this->makeCustomer(), 'sanctum')
            ->getJson('/api/v1/home-popups')
            ->assertOk()
            ->assertJsonFragment(['id' => $newAccountOnly->id]);

        $returning = $this->makeCustomer();
        $this->giveAnOrder($returning, OrderStatus::Delivered);
        $this->actingAs($returning, 'sanctum')
            ->getJson('/api/v1/home-popups')
            ->assertOk()
            ->assertJsonMissing(['id' => $newAccountOnly->id]);
    }

    public function test_switching_the_offer_off_takes_its_slide_with_it(): void
    {
        // Otherwise the home screen keeps promising free delivery the checkout will
        // not honour — the one failure mode worse than not running the offer at all.
        $slide = Banner::query()->create([
            'title_ar' => 'أول طلب لك علينا',
            'image_ar' => 'https://example.test/first.png',
            'audience' => 'first_order',
            'is_active' => true,
        ]);
        Settings::set(FirstOrderPerk::SETTING, 0, 'delivery');

        $this->actingAs($this->makeCustomer(), 'sanctum')
            ->getJson('/api/v1/banners')
            ->assertOk()
            ->assertJsonMissing(['id' => $slide->id]);
    }
}
