<?php

namespace Tests\Feature;

use App\Actions\Customer\CreateOrderAction;
use App\Actions\Restaurant\AcceptOrderAction;
use App\Enums\AdminScopeType;
use App\Enums\OrderStatus;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderAmendment;
use App\Models\OrderGroup;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\PromoCode;
use App\Models\PromoCodeUsage;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use App\Support\FirstOrderPerk;
use App\Support\OrderDeadlines;
use App\Support\Settings;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SendsPushesAfterTheResponse;
use Tests\TestCase;

/**
 * doc 99 §4, D-172 — «يتمكن المكان من مراجعة الطلب وتعديله في حالة وجود أصناف غير متوفرة مع
 * إرسال التعديلات للعميل لمراجعتها ولا يتم استكمال الطلب إلا بعد موافقة العميل».
 *
 * Who may propose, on which orders, what a change may contain, the money after it to the
 * piastre, and every way it closes — accepted, rejected, expired, withdrawn, or overtaken by
 * the order itself.
 */
class OrderAmendmentTest extends TestCase
{
    use RefreshDatabase;
    use SendsPushesAfterTheResponse;

    private Restaurant $place;

    private Product $burger;   // 100

    private Product $fries;    // 50

    private Product $cola;     // 30

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class]);
        $this->configureFirebase();
        $this->fakeFcm();

        Settings::set(FirstOrderPerk::SETTING, false, 'delivery');
        Settings::set('payment.vodafone_cash_number', '01099450110', 'payment');
        Settings::set('payment.instapay_handle', 'cr7delivery@instapay', 'payment');

        $this->place = $this->makeRestaurant('كنج بروست');
        $this->place->update(['amendments_enabled' => true]);
        $this->burger = $this->makeProduct($this->place, 'برجر', 100);
        $this->fries = $this->makeProduct($this->place, 'بطاطس', 50);
        $this->cola = $this->makeProduct($this->place, 'كولا', 30);

        $this->customer = $this->makeCustomer();
        $this->makeAddress($this->customer);
        $this->onApp($this->customer, '1.0.24+30');
    }

    // ------------------------------------------------------------------ who may

    public function test_a_place_with_its_switch_off_is_refused(): void
    {
        $order = $this->order();
        $this->place->update(['amendments_enabled' => false]);

        $this->propose($order, $this->removeFries($order))
            ->assertStatus(403)
            ->assertJsonPath('error', 'amendments_not_allowed');

        $this->assertSame(0, OrderAmendment::query()->count());
    }

    public function test_the_global_switch_off_refuses_the_place_and_the_office(): void
    {
        $order = $this->order();
        Settings::set('orders.amendments_enabled', false, 'amendments');

        $this->propose($order, $this->removeFries($order))->assertStatus(403)->assertJsonPath('error', 'amendments_not_allowed');
        $this->asAdmin($this->adminWith(['orders.view', 'orders.amend']))
            ->postJson("/api/v1/admin/orders/{$order->id}/amendments", $this->removeFries($order))
            ->assertStatus(403)->assertJsonPath('error', 'amendments_not_allowed');
    }

    public function test_an_admin_needs_orders_amend_and_does_not_need_the_place_switch(): void
    {
        $order = $this->order();
        $this->place->update(['amendments_enabled' => false]);

        $this->asAdmin($this->adminWith(['orders.view']))
            ->postJson("/api/v1/admin/orders/{$order->id}/amendments", $this->removeFries($order))
            ->assertStatus(403);

        $admin = $this->adminWith(['orders.view', 'orders.amend']);
        $this->asAdmin($admin)
            ->postJson("/api/v1/admin/orders/{$order->id}/amendments", $this->removeFries($order))
            ->assertCreated()
            ->assertJsonPath('data.proposer_type', 'admin')
            ->assertJsonPath('data.prep_minutes', 20)
            ->assertJsonPath('data.proposed_by.id', $admin->id);

        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'action' => 'orders.amend']);

        // The office's history list and the order detail both carry it.
        $this->asAdmin($admin)->getJson("/api/v1/admin/orders/{$order->id}/amendments")->assertOk()->assertJsonCount(1, 'data');
        $this->asAdmin($this->adminWith(['orders.view']))->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.amendments.0.status', 'pending')
            ->assertJsonPath('data.amendments.0.proposer_type', 'admin');

        // The office takes it back; the place's board shows the order as new again.
        $id = OrderAmendment::query()->value('id');
        $this->asAdmin($this->adminWith(['orders.view']))->deleteJson("/api/v1/admin/orders/{$order->id}/amendments/{$id}")->assertStatus(403);
        $this->asAdmin($admin)->deleteJson("/api/v1/admin/orders/{$order->id}/amendments/{$id}")
            ->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->assertDatabaseHas('order_status_history', ['order_id' => $order->id, 'reason_code' => 'amendment_withdrawn', 'actor_type' => 'admin']);
    }

    public function test_another_place_cannot_propose_on_this_order(): void
    {
        $order = $this->order();
        $other = $this->makeRestaurant('ابن البلد');
        $other->update(['amendments_enabled' => true]);

        Sanctum::actingAs($other->owner);
        $this->withHeader('X-App', 'restaurant')
            ->postJson("/api/v1/restaurant/orders/{$order->id}/amendments", $this->removeFries($order))
            ->assertNotFound();
    }

    public function test_a_customer_on_1_0_23_is_refused_by_name(): void
    {
        $order = $this->order();
        $this->customer->deviceTokens()->delete();
        $this->onApp($this->customer, '1.0.23+29');

        $this->propose($order, $this->removeFries($order))
            ->assertStatus(409)
            ->assertJsonPath('error', 'customer_app_outdated')
            ->assertJsonPath('message', 'نسخة العميل لا تدعم الموافقة على التعديل؛ تواصل معه أو ارفض الطلب')
            ->assertJsonPath('meta.customer_app_version', '1.0.23');

        // The newest device decides: an old phone used yesterday, a new one used today.
        $this->onApp($this->customer, '1.0.24+30', 'new-phone');
        $this->propose($order, $this->removeFries($order))->assertCreated();
    }

    public function test_a_customer_with_no_device_is_refused(): void
    {
        $order = $this->order();
        $this->customer->deviceTokens()->delete();

        $this->propose($order, $this->removeFries($order))->assertStatus(409)->assertJsonPath('error', 'customer_app_outdated');
    }

    public function test_only_a_pending_order_takes_a_change(): void
    {
        $order = $this->order(payment: 'vodafone_cash');
        app(AcceptOrderAction::class)->execute($this->place->owner, $order, 15, null);

        $this->propose($order->fresh(), $this->removeFries($order))
            ->assertStatus(409)
            ->assertJsonPath('error', 'order_not_pending');
    }

    // ------------------------------------------------------------------ what a change may hold

    public function test_a_change_that_changes_nothing_is_refused(): void
    {
        $order = $this->order();
        $item = $this->itemOf($order, $this->fries);

        $this->propose($order, ['prep_minutes' => 20, 'lines' => [['order_item_id' => $item->id, 'quantity' => 1]]])
            ->assertStatus(422)->assertJsonPath('error', 'amendment_empty');
        $this->propose($order, ['prep_minutes' => 20])
            ->assertStatus(422)->assertJsonPath('error', 'amendment_empty');
    }

    public function test_a_line_cannot_grow(): void
    {
        $order = $this->order();
        $item = $this->itemOf($order, $this->burger);

        $this->propose($order, ['prep_minutes' => 20, 'lines' => [['order_item_id' => $item->id, 'quantity' => 3]]])
            ->assertStatus(422)->assertJsonPath('error', 'amendment_quantity_increase');

        // …nor through the back door of «adding» the same plain dish.
        $this->propose($order, ['prep_minutes' => 20, 'additions' => [['product_id' => $this->burger->id, 'quantity' => 1]]])
            ->assertStatus(422)->assertJsonPath('error', 'amendment_quantity_increase');
    }

    public function test_an_addition_must_be_this_places_orderable_and_need_no_choice(): void
    {
        $order = $this->order();
        $other = $this->makeRestaurant('ابن البلد');
        $foreign = $this->makeProduct($other, 'كشري', 40);
        $off = $this->makeProduct($this->place, 'عصير', 25);
        $off->update(['is_available' => false]);
        $sized = $this->makeProduct($this->place, 'بيتزا', 120);
        $group = ProductOptionGroup::query()->create([
            'product_id' => $sized->id, 'name_ar' => 'الحجم', 'name_en' => 'Size', 'is_required' => true,
            'min_select' => 1, 'max_select' => 1, 'selection_type' => 'single', 'sort_order' => 0,
        ]);
        $group->options()->create(['name_ar' => 'كبير', 'name_en' => 'Large', 'price_delta' => 30, 'is_available' => true, 'sort_order' => 0]);

        $add = fn (Product $p) => ['prep_minutes' => 20, 'additions' => [['product_id' => $p->id, 'quantity' => 1]]];

        $this->propose($order, $add($foreign))->assertStatus(422)->assertJsonPath('error', 'amendment_product_foreign');
        $this->propose($order, $add($off))->assertStatus(422)->assertJsonPath('error', 'product_unavailable');
        $this->propose($order, $add($sized))->assertStatus(422)->assertJsonPath('error', 'product_needs_options');
        $this->propose($order, ['prep_minutes' => 20, 'additions' => [['product_id' => 999999, 'quantity' => 1]]])
            ->assertStatus(422)->assertJsonPath('error', 'amendment_product_foreign');
    }

    public function test_a_change_that_empties_the_order_is_refused(): void
    {
        $order = $this->order();

        $this->propose($order, ['prep_minutes' => 20, 'lines' => [
            ['order_item_id' => $this->itemOf($order, $this->burger)->id, 'quantity' => 0],
            ['order_item_id' => $this->itemOf($order, $this->fries)->id, 'quantity' => 0],
        ]])->assertStatus(422)->assertJsonPath('error', 'amendment_subtotal_zero');
    }

    public function test_a_line_from_another_order_and_bad_prep_minutes_are_refused(): void
    {
        $order = $this->order();
        $elsewhere = $this->order();

        $this->propose($order, ['prep_minutes' => 20, 'lines' => [['order_item_id' => $this->itemOf($elsewhere, $this->fries)->id, 'quantity' => 0]]])
            ->assertStatus(422)->assertJsonPath('error', 'validation_failed');
        $this->propose($order, ['prep_minutes' => 500] + $this->removeFries($order))
            ->assertStatus(422)->assertJsonPath('error', 'prep_minutes_out_of_range');
        $this->propose($order, ['lines' => [['order_item_id' => $this->itemOf($order, $this->fries)->id, 'quantity' => 0]]])
            ->assertStatus(422)->assertJsonPath('error', 'validation_failed');
    }

    // ------------------------------------------------------------------ money

    public function test_single_place_cash_accept_rewrites_the_items_and_confirms_to_the_piastre(): void
    {
        // برجر 100 × 2 + بطاطس 50 = 250; + 20 delivery + 5 service = 275.
        $order = $this->order();
        $group = $order->orderGroup;
        $this->assertEquals(275, (float) $group->total);

        // One burger, no fries, a cola: 130; + 25 = 155.
        $response = $this->propose($order, $this->standardChange($order))->assertCreated();
        $response->assertJsonPath('data.money_before.total', 275)
            ->assertJsonPath('data.money_after.total', 155)
            ->assertJsonPath('data.money_after.order_subtotal', 130)
            ->assertJsonPath('data.delta', -120)
            ->assertJsonPath('data.status', 'pending');

        $amendmentId = $response->json('data.id');

        $this->asCustomer()->getJson("/api/v1/orders/{$group->id}/amendment")
            ->assertOk()
            ->assertJsonPath('data.id', $amendmentId)
            ->assertJsonPath('data.restaurant.name', 'كنج بروست')
            ->assertJsonPath('data.money_after.total', 155)
            ->assertJsonPath('data.cash_ok', true)
            ->assertJsonPath('data.can_respond', true)
            ->assertJsonPath('data.additions.0.name', 'كولا')
            ->assertJsonPath('data.additions.0.line_total', 30);

        $this->accept($group, $amendmentId, 155)->assertOk()->assertJsonPath('data.status', 'accepted');

        $order->refresh();
        $items = $order->items()->get();
        $this->assertCount(2, $items);
        $this->assertSame(1, (int) $items->firstWhere('product_id', $this->burger->id)->quantity);
        $this->assertEquals(100, (float) $items->firstWhere('product_id', $this->burger->id)->line_total);
        $this->assertNull($items->firstWhere('product_id', $this->fries->id));
        $cola = $items->firstWhere('product_id', $this->cola->id);
        $this->assertSame('كولا', $cola->product_name_ar_snapshot);
        $this->assertEquals(30, (float) $cola->unit_price_snapshot);

        $this->assertMoney($order, subtotal: 130, discount: 0, delivery: 20, platform: 5, total: 155);
        $this->assertGroupMoney($group->fresh(), subtotal: 130, discount: 0, delivery: 20, total: 155);

        // Accepted with the proposed prep minutes, then — single place, cash — confirmed.
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame(25, (int) $order->prep_minutes);
        $this->assertSame('POS-7', $order->restaurant_order_ref);
        $this->assertSame('accepted', $order->amendment_status);
        $this->assertDatabaseHas('order_status_history', ['order_id' => $order->id, 'reason_code' => 'amendment_accepted', 'actor_type' => 'customer']);
        $this->assertDatabaseHas('order_status_history', ['order_id' => $order->id, 'to_status' => 'accepted', 'actor_type' => 'restaurant']);
        $this->assertTrue(Notification::query()->where('user_id', $this->place->user_id)->where('type', 'order.amendment_accepted_restaurant')->exists());
    }

    public function test_single_place_transfer_goes_to_payment_on_the_new_total_without_asking_again(): void
    {
        $order = $this->order(payment: 'vodafone_cash');
        $group = $order->orderGroup;

        $id = $this->propose($order, $this->standardChange($order))->assertCreated()->json('data.id');
        $this->accept($group, $id, 155)->assertOk();

        $order->refresh();
        $group->refresh();
        $this->assertSame(OrderStatus::AwaitingPayment, $order->status);
        $this->assertEquals(155, (float) $group->total);
        $this->assertSame('awaiting_proof', $group->payment->status->value ?? $group->payment->status);
        $this->assertEquals(155, (float) $group->payment->amount);
        $this->assertNotNull($group->payment->deadline_at);
        // The approval was the confirmation: no «please confirm» was sent after it.
        $this->assertFalse(Notification::query()->where('user_id', $this->customer->id)->where('type', 'order.confirm_required')->exists());
        $this->assertTrue(Notification::query()->where('user_id', $this->customer->id)->where('type', 'payment.due')->exists());
    }

    public function test_two_places_with_a_code_for_one_of_them_are_re_split_to_the_piastre(): void
    {
        // doc 96 — 10 % on كنج بروست only. P: برجر × 2 = 200; Q: كشري 100.
        $other = $this->makeRestaurant('ابن البلد');
        $koshary = $this->makeProduct($other, 'كشري', 100);
        PromoCode::query()->create([
            'code' => 'KING10', 'title_ar' => 'خصم', 'title_en' => 'Discount', 'type' => 'percent', 'value' => 10,
            'is_active' => true, 'restrict_restaurant_ids' => [$this->place->id],
        ]);

        $group = $this->place([[$this->place, $this->burger, 2], [$other, $koshary, 1]], 'cash', 'KING10');
        $p = $group->orders()->where('restaurant_id', $this->place->id)->first();
        $q = $group->orders()->where('restaurant_id', $other->id)->first();

        // 300 − 20 + (20 + 10 extra stop) + 5 = 315.
        $this->assertEquals(315, (float) $group->total);
        $this->assertEquals(20, (float) $p->discount_share);

        // The other place has already answered.
        app(AcceptOrderAction::class)->execute($other->owner, $q, 15, null);

        // One burger: P = 100, discount 10 % of 100 = 10; 200 − 10 + 30 + 5 = 225.
        $id = $this->propose($p, ['prep_minutes' => 20, 'lines' => [['order_item_id' => $this->itemOf($p, $this->burger)->id, 'quantity' => 1]]])
            ->assertCreated()
            ->assertJsonPath('data.money_after.total', 225)
            ->assertJsonPath('data.money_after.discount', 10)
            ->json('data.id');

        $this->accept($group, $id, 225)->assertOk();

        $this->assertMoney($p->fresh(), subtotal: 100, discount: 10, delivery: 15, platform: 2.5, total: 107.5);
        $this->assertMoney($q->fresh(), subtotal: 100, discount: 0, delivery: 15, platform: 2.5, total: 117.5);
        $this->assertGroupMoney($group->fresh(), subtotal: 200, discount: 10, delivery: 30, total: 225);
        $this->assertEquals(10, (float) PromoCodeUsage::query()->where('order_id', $p->id)->value('discount_amount'));
        $this->assertEquals(0, (float) PromoCodeUsage::query()->where('order_id', $q->id)->value('discount_amount'));

        // Both places answered and the customer approved the new total: confirmed.
        $this->assertSame(OrderStatus::Confirmed, $p->fresh()->status);
        $this->assertSame(OrderStatus::Confirmed, $q->fresh()->status);
        $this->assertSame('customer', $group->fresh()->confirmed_by?->value ?? $group->fresh()->confirmed_by);
    }

    public function test_a_code_whose_minimum_is_no_longer_met_stops_discounting(): void
    {
        PromoCode::query()->create([
            'code' => 'MIN200', 'title_ar' => 'خصم', 'title_en' => 'Discount', 'type' => 'fixed', 'value' => 40,
            'min_order_value' => 200, 'is_active' => true,
        ]);
        $group = $this->place([[$this->place, $this->burger, 2], [$this->place, $this->fries, 1]], 'cash', 'MIN200');
        $order = $group->orders()->first();
        $this->assertEquals(235, (float) $group->total); // 250 − 40 + 25

        // 130 is under the code's 200: no discount — 130 + 25 = 155.
        $this->propose($order, $this->standardChange($order))->assertCreated()
            ->assertJsonPath('data.money_after.discount', 0)
            ->assertJsonPath('data.money_after.total', 155)
            ->assertJsonPath('data.delta', -80);
    }

    public function test_free_first_delivery_stays_free(): void
    {
        Settings::set(FirstOrderPerk::SETTING, true, 'delivery');
        $order = $this->order();
        $this->assertEquals(255, (float) $order->orderGroup->total); // 250 + 0 + 5
        $this->assertSame('first_order', $order->free_delivery_reason);

        $id = $this->propose($order, $this->standardChange($order))->assertCreated()->assertJsonPath('data.money_after.total', 135)->json('data.id');
        $this->accept($order->orderGroup, $id, 135)->assertOk();

        $this->assertMoney($order->fresh(), subtotal: 130, discount: 0, delivery: 0, platform: 5, total: 135);
        $this->assertSame('first_order', $order->fresh()->free_delivery_reason);
    }

    public function test_a_far_places_surcharge_stays_in_the_fee(): void
    {
        $this->place->update(['delivery_surcharge' => 100]);
        $order = $this->order();
        $this->assertEquals(375, (float) $order->orderGroup->total); // 250 + 120 + 5

        $id = $this->propose($order, $this->standardChange($order))->assertCreated()->assertJsonPath('data.money_after.delivery_fee', 120)->json('data.id');
        $this->accept($order->orderGroup, $id, 255)->assertOk();

        $this->assertMoney($order->fresh(), subtotal: 130, discount: 0, delivery: 120, platform: 5, total: 255);
    }

    public function test_a_cash_total_over_the_limit_is_refused_with_the_existing_sentence(): void
    {
        Settings::set('payment.cash_max_total', 300, 'payment');
        $order = $this->order(); // 275 cash
        $big = $this->makeProduct($this->place, 'صينية', 200);

        $id = $this->propose($order, ['prep_minutes' => 20, 'additions' => [['product_id' => $big->id, 'quantity' => 1]]])
            ->assertCreated()
            ->assertJsonPath('data.cash_ok', false)
            ->json('data.id');

        $this->asCustomer()->getJson("/api/v1/orders/{$order->order_group_id}/amendment")->assertJsonPath('data.cash_ok', false);

        $this->accept($order->orderGroup, $id, 475)
            ->assertStatus(422)
            ->assertJsonPath('error', 'cash_not_allowed')
            ->assertJsonPath('message', 'الدفع نقدًا متاح للطلبات أقل من ‪300‬ ج.م — اختر فودافون كاش أو إنستا باي')
            ->assertJsonPath('meta.total', 475);

        // Nothing moved; the customer can still decline.
        $this->assertSame(2, $order->items()->count());
        $this->assertSame('pending', OrderAmendment::query()->find($id)->status->value);
        $this->reject($order->orderGroup, $id)->assertOk();
    }

    // ------------------------------------------------------------------ the customer's answer

    public function test_a_stale_total_is_not_approved(): void
    {
        $order = $this->order();
        $id = $this->propose($order, $this->standardChange($order))->json('data.id');

        $this->accept($order->orderGroup, $id, 156)
            ->assertStatus(409)
            ->assertJsonPath('error', 'amendment_changed')
            ->assertJsonPath('meta.total', 155);

        // The cola got dearer since the place proposed it.
        $this->cola->update(['price' => 35]);
        $this->accept($order->orderGroup, $id, 155)->assertStatus(409)->assertJsonPath('error', 'amendment_changed');

        $this->assertSame('pending', OrderAmendment::query()->find($id)->status->value);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(2, $order->items()->count());
    }

    public function test_a_second_accept_changes_nothing(): void
    {
        $order = $this->order();
        $id = $this->propose($order, $this->standardChange($order))->json('data.id');

        $this->accept($order->orderGroup, $id, 155)->assertOk();
        $this->accept($order->orderGroup, $id, 155)->assertStatus(409)->assertJsonPath('error', 'amendment_closed');
        $this->reject($order->orderGroup, $id)->assertStatus(409)->assertJsonPath('error', 'amendment_closed');

        $this->assertSame(2, $order->items()->count());
        $this->assertSame(1, $order->statusHistory()->where('to_status', 'accepted')->count());
    }

    public function test_an_answer_after_the_deadline_is_refused_and_the_tick_closes_it(): void
    {
        $order = $this->order();
        $id = $this->propose($order, $this->standardChange($order))->json('data.id');

        $this->travel(11)->minutes();
        $this->accept($order->orderGroup, $id, 155)->assertStatus(409)->assertJsonPath('error', 'amendment_closed');

        Artisan::call('cr7:tick', ['--once' => true]);

        $amendment = OrderAmendment::query()->find($id);
        $this->assertSame('expired', $amendment->status->value);
        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame('expired', $order->amendment_status);
        $this->assertNotNull($order->answer_window_started_at);
        $this->assertDatabaseHas('order_status_history', ['order_id' => $order->id, 'reason_code' => 'amendment_expired', 'actor_type' => 'system']);
        $this->assertTrue(Notification::query()->where('user_id', $this->place->user_id)->where('type', 'order.amendment_expired_restaurant')->exists());

        // A second tick finds nothing to do.
        Artisan::call('cr7:tick', ['--once' => true]);
        $this->assertSame(1, $order->statusHistory()->where('reason_code', 'amendment_expired')->count());

        // The place answers the original.
        $this->asPlace()->postJson("/api/v1/restaurant/orders/{$order->id}/accept", ['prep_minutes' => 15])->assertOk();
        $this->assertEquals(275, (float) $order->orderGroup->fresh()->total);
    }

    public function test_a_rejected_change_returns_the_order_to_the_place_which_may_accept_the_original(): void
    {
        $order = $this->order();
        $id = $this->propose($order, $this->standardChange($order))->json('data.id');

        $this->reject($order->orderGroup, $id, 'أريد الطلب كما هو')->assertOk()->assertJsonPath('data.status', 'rejected');

        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame('rejected', $order->amendment_status);
        $this->assertSame(2, $order->items()->count());
        $this->assertTrue(Notification::query()->where('user_id', $this->place->user_id)->where('type', 'order.amendment_rejected_restaurant')->exists());

        // «جديد» again, with the outcome on the card.
        $board = $this->asPlace()->getJson('/api/v1/restaurant/orders?queue=new')->assertOk();
        $card = collect($board->json('data'))->firstWhere('id', $order->id);
        $this->assertSame('rejected', $card['amendment']['status']);
        $this->assertSame('أريد الطلب كما هو', $card['amendment']['response_note']);
        $this->assertSame('new', $card['board_queue']);
        $this->assertTrue($card['can_propose_amendment']);

        $this->asPlace()->postJson("/api/v1/restaurant/orders/{$order->id}/accept", ['prep_minutes' => 15])->assertOk();
        $this->assertEquals(275, (float) $order->orderGroup->fresh()->total);
    }

    public function test_a_new_change_may_follow_a_rejected_one_but_not_a_pending_one(): void
    {
        $order = $this->order();
        $first = $this->propose($order, $this->standardChange($order))->json('data.id');

        $this->propose($order, $this->removeFries($order))->assertStatus(409)->assertJsonPath('error', 'amendment_pending');

        $this->reject($order->orderGroup, $first)->assertOk();
        $second = $this->propose($order, $this->removeFries($order))->assertCreated()->assertJsonPath('data.money_after.total', 225)->json('data.id');

        $this->asCustomer()->getJson("/api/v1/orders/{$order->order_group_id}/amendment")->assertJsonPath('data.id', $second);
        $this->accept($order->orderGroup, $second, 225)->assertOk();
    }

    public function test_the_place_cannot_answer_the_order_while_the_customer_is_answering_but_can_withdraw(): void
    {
        $order = $this->order();
        $id = $this->propose($order, $this->standardChange($order))->json('data.id');

        $this->asPlace()->postJson("/api/v1/restaurant/orders/{$order->id}/accept", ['prep_minutes' => 15])
            ->assertStatus(409)->assertJsonPath('error', 'amendment_pending');
        $this->asPlace()->postJson("/api/v1/restaurant/orders/{$order->id}/reject", ['reason_code' => 'item_unavailable'])
            ->assertStatus(409)->assertJsonPath('error', 'amendment_pending');

        $this->asPlace()->deleteJson("/api/v1/restaurant/orders/{$order->id}/amendments/{$id}")
            ->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->asPlace()->deleteJson("/api/v1/restaurant/orders/{$order->id}/amendments/{$id}")
            ->assertStatus(409)->assertJsonPath('error', 'amendment_closed');

        $this->accept($order->orderGroup, $id, 155)->assertStatus(409)->assertJsonPath('error', 'amendment_closed');
        $this->asPlace()->postJson("/api/v1/restaurant/orders/{$order->id}/accept", ['prep_minutes' => 15])->assertOk();
    }

    public function test_a_customer_cancelling_closes_the_change(): void
    {
        $order = $this->order();
        $id = $this->propose($order, $this->standardChange($order))->json('data.id');

        $this->asCustomer()->postJson("/api/v1/orders/{$order->order_group_id}/cancel", ['reason_code' => 'changed_mind'])->assertOk();

        $amendment = OrderAmendment::query()->find($id);
        $this->assertSame('withdrawn', $amendment->status->value);
        $this->assertSame('order_closed', $amendment->response_note);
        $this->assertSame('withdrawn', $order->fresh()->amendment_status);

        $this->accept($order->orderGroup, $id, 155)->assertStatus(409)->assertJsonPath('error', 'amendment_closed');
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_a_stranger_cannot_read_or_answer_the_change(): void
    {
        $order = $this->order();
        $id = $this->propose($order, $this->standardChange($order))->json('data.id');
        $stranger = $this->makeCustomer();

        Sanctum::actingAs($stranger);
        $this->withHeader('X-App', 'app')->getJson("/api/v1/orders/{$order->order_group_id}/amendment")->assertNotFound();
        $this->withHeader('X-App', 'app')->postJson("/api/v1/orders/{$order->order_group_id}/amendments/{$id}/accept", ['expected_total' => 155])->assertNotFound();

        // An amendment of another basket through this basket's URL.
        $mine = $this->order();
        $this->asCustomer()->postJson("/api/v1/orders/{$mine->order_group_id}/amendments/{$id}/accept", ['expected_total' => 155])->assertNotFound();
    }

    // ------------------------------------------------------------------ clocks, ring, board

    public function test_the_restaurant_timeout_is_paused_while_the_customer_answers_and_restarts_after(): void
    {
        $order = $this->order(); // accept window: 5 minutes
        $this->travel(2)->minutes();
        $id = $this->propose($order, $this->standardChange($order))->json('data.id');

        $this->travel(7)->minutes(); // 9 minutes since the order arrived
        Artisan::call('cr7:tick', ['--once' => true]);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);

        $this->reject($order->orderGroup, $id)->assertOk();

        $this->travel(4)->minutes();
        Artisan::call('cr7:tick', ['--once' => true]);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status, 'the window restarted when the change closed');

        $this->travel(2)->minutes();
        Artisan::call('cr7:tick', ['--once' => true]);
        $this->assertSame(OrderStatus::RestaurantTimeout, $order->fresh()->status);
    }

    public function test_proposing_stops_the_ring(): void
    {
        $this->place->owner->deviceTokens()->create([
            'token' => 'place-phone', 'platform' => 'android', 'app' => 'restaurant', 'device_id' => 'pp', 'capabilities' => ['ring'],
        ]);
        $order = $this->order();
        $this->assertNotNull($order->fresh()->ring_next_at);

        $this->propose($order, $this->standardChange($order))->assertCreated();

        $this->assertNull($order->fresh()->ring_next_at);
        $this->assertCount(1, $this->pushesOfType('place-phone', 'order.ring_stop'));

        $this->travel(5)->minutes();
        $this->assertSame(0, OrderDeadlines::ringAgain());
        $this->assertCount(1, $this->pushesOfType('place-phone', 'order.ring'));
    }

    public function test_the_board_moves_the_order_to_awaiting_customer_and_back(): void
    {
        $order = $this->order();
        $this->propose($order, $this->standardChange($order))->assertCreated();

        $ids = fn (string $queue) => collect($this->asPlace()->getJson("/api/v1/restaurant/orders?queue={$queue}")->json('data'))->pluck('id')->all();

        $this->assertNotContains($order->id, $ids('new'));
        $this->assertContains($order->id, $ids('awaiting_customer'));

        $counts = $this->asPlace()->getJson('/api/v1/restaurant/orders/new-count')->assertOk();
        $this->assertSame(0, $counts->json('data.new'));
        $this->assertSame(1, $counts->json('data.awaiting_customer'));

        $show = $this->asPlace()->getJson("/api/v1/restaurant/orders/{$order->id}")->assertOk();
        $show->assertJsonPath('data.amendment.status', 'pending')
            ->assertJsonPath('data.board_queue', 'awaiting_customer')
            ->assertJsonPath('data.can_propose_amendment', false)
            ->assertJsonPath('data.amendment.lines.1.changed', true)
            ->assertJsonMissingPath('data.latest_amendment');
    }

    // ------------------------------------------------------------------ payloads and pushes

    public function test_the_customer_is_pushed_through_the_web_path_and_the_detail_carries_the_change(): void
    {
        $this->customer->deviceTokens()->update(['token' => 'cust-phone']);
        $order = $this->order();
        $this->pretendThisIsAWebRequest();

        // Through the HTTP kernel, which terminates the app after the response — that is
        // where `afterResponse()` sends, with the closure serialised and parsed back.
        $this->propose($order, $this->standardChange($order))->assertCreated();
        (new \ReflectionProperty(app(), 'terminatingCallbacks'))->setValue(app(), []);

        $push = $this->pushesOfType('cust-phone', 'order.amendment_proposed');
        $this->assertCount(1, $push);
        $this->assertSame((string) $order->order_group_id, $push[0]['data']['group_id']);
        $this->assertNotEmpty($push[0]['data']['amendment_id']);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->customer->id, 'type' => 'order.amendment_proposed',
            'deep_link' => "cr7://order/{$order->order_group_id}/amendment",
        ]);

        $detail = $this->asCustomer()->getJson("/api/v1/orders/{$order->order_group_id}")->assertOk();
        $detail->assertJsonPath('data.pending_amendment.total_after', 155)
            ->assertJsonPath('data.sub_orders.0.amendment.status', 'pending');
        $this->assertSame(['pending'], collect($detail->json('data.sub_orders.0.timeline'))->pluck('status')->all(), 'the amendment rows stay off the customer timeline');

        $tracking = $this->asCustomer()->getJson("/api/v1/orders/{$order->order_group_id}/tracking")->assertOk();
        $tracking->assertJsonPath('data.pending_amendment.delta', -120)
            ->assertJsonPath('data.sub_orders.0.amendment.money_after.total', 155);
        $this->assertCount(1, $tracking->json('data.sub_orders.0.timeline'));

        // Answered: no pending change on the group, the sub-order keeps its outcome.
        $this->accept($order->orderGroup, OrderAmendment::query()->value('id'), 155)->assertOk();
        $this->asCustomer()->getJson("/api/v1/orders/{$order->order_group_id}")
            ->assertJsonPath('data.pending_amendment', null)
            ->assertJsonPath('data.sub_orders.0.amendment.status', 'accepted');
    }

    public function test_a_customer_with_no_change_gets_404_and_an_old_client_reads_the_detail_unchanged(): void
    {
        $order = $this->order();

        $this->asCustomer()->getJson("/api/v1/orders/{$order->order_group_id}/amendment")
            ->assertNotFound()->assertJsonPath('error', 'amendment_not_found');

        $this->asCustomer()->withHeader('X-App-Version', '1.0.23')->getJson("/api/v1/orders/{$order->order_group_id}")
            ->assertOk()
            ->assertJsonPath('data.pending_amendment', null)
            ->assertJsonPath('data.sub_orders.0.amendment', null)
            ->assertJsonPath('data.total', 275);
    }

    // ------------------------------------------------------------------ the admin switch

    public function test_the_admin_switch_takes_either_name_and_its_own_permission(): void
    {
        $this->place->update(['amendments_enabled' => false]);
        $url = "/api/v1/admin/restaurants/{$this->place->id}/amendments";

        $this->asAdmin($this->adminWith(['restaurants.view', 'restaurants.update']))->postJson($url, ['enabled' => true])->assertStatus(403);

        $admin = $this->adminWith(['restaurants.view', 'restaurants.toggle_amendments']);
        $this->asAdmin($admin)->postJson($url, ['enabled' => true])->assertOk()->assertJsonPath('data.amendments_enabled', true);
        $this->asAdmin($admin)->postJson($url, ['amendments_enabled' => false])->assertOk()->assertJsonPath('data.amendments_enabled', false);
        $this->asAdmin($admin)->postJson($url, [])->assertStatus(422);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'action' => 'restaurants.toggle_amendments']);

        // On `ADM-12`'s form too — guarded by the same grant.
        $editor = $this->adminWith(['restaurants.view', 'restaurants.update']);
        $this->asAdmin($editor)->patchJson("/api/v1/admin/restaurants/{$this->place->id}", ['amendments_enabled' => true])
            ->assertStatus(403)->assertJsonPath('error', 'amendments_toggle_not_permitted');
        $this->asAdmin($editor)->patchJson("/api/v1/admin/restaurants/{$this->place->id}", ['amendments_enabled' => false, 'name_ar' => 'كنج بروست الجديد'])
            ->assertOk();

        $this->asAdmin($this->adminWith(['restaurants.view', 'restaurants.update', 'restaurants.toggle_amendments']))
            ->patchJson("/api/v1/admin/restaurants/{$this->place->id}", ['amendments_enabled' => true])->assertOk();
        $this->assertTrue((bool) $this->place->fresh()->amendments_enabled);

        $this->asAdmin($admin)->getJson("/api/v1/admin/restaurants/{$this->place->id}")->assertOk()->assertJsonPath('data.amendments_enabled', true);
    }

    // ================================================================== helpers

    private function order(string $payment = 'cash'): Order
    {
        $group = $this->place([[$this->place, $this->burger, 2], [$this->place, $this->fries, 1]], $payment);

        return $group->orders()->firstOrFail();
    }

    /** @param  list<array{0: Restaurant, 1: Product, 2: int}>  $lines */
    private function place(array $lines, string $payment = 'cash', ?string $promo = null): OrderGroup
    {
        $this->customer->cart()->delete();
        $this->makeCart($this->customer, $lines);

        return app(CreateOrderAction::class)->execute(
            $this->customer,
            ['address_id' => $this->customer->default_address_id, 'payment_method' => $payment, 'promo_code' => $promo],
            'idem-'.uniqid(),
        );
    }

    private function onApp(User $customer, string $version, string $device = 'phone'): void
    {
        $customer->deviceTokens()->create([
            'token' => 'tok-'.$device.'-'.$customer->id,
            'platform' => 'android',
            'app' => 'customer',
            'device_id' => $device,
            'app_version' => $version,
            'last_used_at' => now()->addSeconds($customer->deviceTokens()->count()),
        ]);
    }

    private function itemOf(Order $order, Product $product)
    {
        return $order->items()->where('product_id', $product->id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function removeFries(Order $order): array
    {
        return ['prep_minutes' => 20, 'lines' => [['order_item_id' => $this->itemOf($order, $this->fries)->id, 'quantity' => 0]]];
    }

    /** One burger, no fries, a cola. @return array<string, mixed> */
    private function standardChange(Order $order): array
    {
        return [
            'prep_minutes' => 25,
            'restaurant_order_ref' => 'POS-7',
            'note' => 'البطاطس نفدت',
            'lines' => [
                ['order_item_id' => $this->itemOf($order, $this->burger)->id, 'quantity' => 1],
                ['order_item_id' => $this->itemOf($order, $this->fries)->id, 'quantity' => 0],
            ],
            'additions' => [['product_id' => $this->cola->id, 'quantity' => 1]],
        ];
    }

    private function propose(Order $order, array $body)
    {
        return $this->asPlace()->postJson("/api/v1/restaurant/orders/{$order->id}/amendments", $body);
    }

    private function accept(OrderGroup $group, int $id, float $total)
    {
        return $this->asCustomer()->postJson("/api/v1/orders/{$group->id}/amendments/{$id}/accept", ['expected_total' => $total]);
    }

    private function reject(OrderGroup $group, int $id, ?string $reason = null)
    {
        return $this->asCustomer()->postJson("/api/v1/orders/{$group->id}/amendments/{$id}/reject", ['reason' => $reason]);
    }

    private function asPlace(): static
    {
        Sanctum::actingAs($this->place->owner);

        return $this->withHeaders(['X-App' => 'restaurant', 'Accept-Language' => 'ar']);
    }

    private function asCustomer(): static
    {
        Sanctum::actingAs($this->customer);

        return $this->withHeaders(['X-App' => 'app', 'X-App-Version' => '1.0.24', 'Accept-Language' => 'ar']);
    }

    private function asAdmin(User $admin): static
    {
        Sanctum::actingAs($admin);

        return $this->withHeaders(['X-App' => 'admin']);
    }

    /** @param  list<string>  $permissions */
    private function adminWith(array $permissions): User
    {
        $role = Role::query()->create(['name' => 'r'.uniqid(), 'guard_name' => 'web', 'label_ar' => 'دور', 'label_en' => 'Role']);
        $role->syncPermissions(Permission::query()->whereIn('name', $permissions)->pluck('name')->all());

        $admin = User::query()->create(['name' => 'مشرف', 'phone' => '010'.random_int(10000000, 99999999), 'type' => 'admin', 'is_active' => true]);
        $admin->assignRole($role->name);
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        return $admin;
    }

    private function assertMoney(Order $order, float $subtotal, float $discount, float $delivery, float $platform, float $total): void
    {
        $this->assertEquals($subtotal, (float) $order->subtotal, 'subtotal');
        $this->assertEquals($discount, (float) $order->discount_share, 'discount_share');
        $this->assertEquals($delivery, (float) $order->delivery_fee_share, 'delivery_fee_share');
        $this->assertEquals($platform, (float) $order->platform_fee_share, 'platform_fee_share');
        $this->assertEquals($total, (float) $order->total, 'total');
    }

    private function assertGroupMoney(OrderGroup $group, float $subtotal, float $discount, float $delivery, float $total): void
    {
        $this->assertEquals($subtotal, (float) $group->subtotal, 'group subtotal');
        $this->assertEquals($discount, (float) $group->discount_amount, 'group discount');
        $this->assertEquals($delivery, (float) $group->delivery_fee, 'group delivery fee');
        $this->assertEquals($total, (float) $group->total, 'group total');
    }
}
