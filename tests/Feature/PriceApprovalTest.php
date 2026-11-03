<?php

namespace Tests\Feature;

use App\Actions\Customer\CreateOrderAction;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Models\ProductPriceRequest;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsListFixtures;
use Tests\TestCase;

/**
 * doc 99 §1 — D-169: «لا تظهر الأسعار الجديدة للعملاء إلا بعد اعتماد الإدارة».
 *
 * A place's change to an existing dish's price, discount or option price is filed as a
 * request; every customer read keeps the live price until an admin approves it, and the
 * approval is one locked Eloquent write.
 */
class PriceApprovalTest extends TestCase
{
    use BuildsListFixtures;
    use RefreshDatabase;

    private Restaurant $place;

    private Product $dish;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpListWorld();

        $this->place = $this->makeRestaurant('سندوتش عمر');
        $this->dish = $this->makeProduct($this->place, 'شاورما فراخ', 100);
        $this->admin = $this->globalAdmin();
    }

    private function asPlace(): static
    {
        return $this->actingAsPlace($this->place)->withHeader('Accept-Language', 'ar');
    }

    private function patchDish(array $body)
    {
        return $this->asPlace()->patchJson('/api/v1/restaurant/products/'.$this->dish->id, $body);
    }

    // ------------------------------------------------------------- the place files

    public function test_a_place_price_change_waits_and_every_customer_read_keeps_the_live_price(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $this->makeCart($customer, [[$this->place, $this->dish, 2]]);

        $this->patchDish(['price' => 130, 'name_ar' => 'شاورما فراخ سوبر', 'price_note' => 'زاد سعر الفراخ'])
            ->assertOk()
            ->assertJsonPath('meta.price_request', 'created')
            ->assertJsonPath('message', 'أُرسل السعر الجديد إلى الإدارة للمراجعة، وسيظهر للعملاء بعد الاعتماد')
            // Every other field saved; the price did not.
            ->assertJsonPath('data.name_ar', 'شاورما فراخ سوبر')
            ->assertJsonPath('data.price', '100.00')
            ->assertJsonPath('data.pending_price_request.requested_price', 130)
            ->assertJsonPath('data.pending_price_request.requested_discount_price', null)
            ->assertJsonPath('data.pending_price_request.clears_discount', false)
            ->assertJsonPath('data.pending_price_request.note', 'زاد سعر الفراخ')
            ->assertJsonPath('data.last_price_decision', null)
            ->assertJsonMissingPath('data.open_price_request');

        $this->assertSame('100.00', (string) $this->dish->fresh()->price);
        $this->assertSame(1, ProductPriceRequest::query()->where('status', 'pending')->count());

        // List, detail, search-free menu, cart, quote and checkout: still 100.
        $this->getJson('/api/v1/restaurants/'.$this->place->id.'/products')
            ->assertOk()->assertJsonPath('data.0.price', 100);
        $this->getJson('/api/v1/products/'.$this->dish->id)
            ->assertOk()->assertJsonPath('data.price', 100);

        Sanctum::actingAs($customer);
        $this->withHeader('X-App', 'app')->getJson('/api/v1/cart')
            ->assertOk()->assertJsonPath('data.groups.0.items.0.unit_price', 100);
        $this->withHeader('X-App', 'app')
            ->postJson('/api/v1/checkout/quote', ['address_id' => $address->id, 'payment_method' => 'cash'])
            ->assertOk()->assertJsonPath('data.subtotal', 200);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid(),
        );
        $this->assertSame('200.00', (string) $group->subtotal);
    }

    public function test_the_office_is_told_once_and_the_place_reads_its_requests(): void
    {
        $this->patchDish(['price' => 130])->assertOk();
        $this->patchDish(['price' => 140])->assertOk();

        // One push to the office for the dish, not one per edit.
        $this->assertSame(1, Notification::query()->where('user_id', $this->admin->id)->where('type', 'price_request.new_admin')->count());
        $push = Notification::query()->where('type', 'price_request.new_admin')->first();
        $this->assertSame('سعر جديد بانتظار الموافقة', $push->payload['title_ar']);
        $this->assertStringContainsString('سندوتش عمر', $push->payload['body_ar']);
        $this->assertStringStartsWith('/price-requests/', $push->deep_link);

        $this->asPlace()->getJson('/api/v1/restaurant/price-requests?filter[status]=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.requested_price', 140)
            ->assertJsonPath('data.0.current_price', 100);

        $this->asPlace()->getJson('/api/v1/restaurant/price-requests')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->asPlace()->getJson('/api/v1/restaurant/price-requests?filter[status]=nonsense')
            ->assertStatus(422);
    }

    public function test_the_live_price_sent_back_is_not_a_change(): void
    {
        // An older place app re-saves the whole form with the live price in it.
        $this->patchDish(['price' => 100, 'name_ar' => 'اسم جديد'])
            ->assertOk()
            ->assertJsonMissingPath('meta.price_request')
            ->assertJsonPath('message', 'تم تحديث المنتج بنجاح');

        $this->assertSame(0, ProductPriceRequest::query()->count());

        // …and it does not cancel a pending request either.
        $this->patchDish(['price' => 120])->assertOk();
        $this->patchDish(['price' => 100, 'name_ar' => 'اسم ثالث'])->assertOk();

        $this->assertSame(120.0, (float) ProductPriceRequest::query()->where('status', 'pending')->value('requested_price'));
    }

    public function test_a_second_change_supersedes_and_merges_option_changes(): void
    {
        $group = ProductOptionGroup::query()->create([
            'product_id' => $this->dish->id, 'name_ar' => 'الإضافات', 'name_en' => 'Extras',
            'selection_type' => 'multiple', 'price_mode' => 'add', 'is_required' => false, 'min_select' => 0, 'max_select' => 2,
        ]);
        $cheese = $group->options()->create(['name_ar' => 'جبنة', 'name_en' => 'Cheese', 'price_delta' => 10]);
        $sauce = $group->options()->create(['name_ar' => 'صوص', 'name_en' => 'Sauce', 'price_delta' => 5]);

        $this->asPlace()->patchJson("/api/v1/restaurant/option-groups/{$group->id}/options/{$cheese->id}", ['price_delta' => 15, 'name_ar' => 'جبنة رومي'])
            ->assertOk()
            ->assertJsonPath('meta.price_request', 'created')
            ->assertJsonPath('data.name_ar', 'جبنة رومي')
            ->assertJsonPath('data.price_delta', '10.00')
            ->assertJsonPath('data.pending_price_request.option_changes.0.requested_price_delta', 15);

        $this->asPlace()->patchJson("/api/v1/restaurant/option-groups/{$group->id}/options/{$sauce->id}", ['price_delta' => 7])->assertOk();
        $this->patchDish(['price' => 110])->assertOk();
        // The same option again: the newer value wins.
        $this->asPlace()->patchJson("/api/v1/restaurant/option-groups/{$group->id}/options/{$cheese->id}", ['price_delta' => 18])->assertOk();

        $this->assertSame(1, ProductPriceRequest::query()->where('status', 'pending')->count());
        $this->assertSame(3, ProductPriceRequest::query()->where('status', 'superseded')->count());

        $pending = ProductPriceRequest::query()->where('status', 'pending')->first();
        $this->assertSame(110.0, (float) $pending->requested_price);
        $changes = collect($pending->option_changes)->keyBy('option_id');
        $this->assertSame(18.0, (float) $changes[$cheese->id]['requested_price_delta']);
        $this->assertSame(7.0, (float) $changes[$sauce->id]['requested_price_delta']);
        $this->assertSame('الإضافات', $changes[$cheese->id]['group_name_ar']);
        $this->assertSame($pending->id, ProductPriceRequest::query()->where('status', 'superseded')->latest('id')->value('superseded_by'));

        // Live untouched until approved.
        $this->assertSame('10.00', (string) $cheese->fresh()->price_delta);

        $this->actingAsAdmin($this->admin)
            ->postJson("/api/v1/admin/price-requests/{$pending->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame('110.00', (string) $this->dish->fresh()->price);
        $this->assertSame('18.00', (string) $cheese->fresh()->price_delta);
        $this->assertSame('7.00', (string) $sauce->fresh()->price_delta);
    }

    public function test_a_new_option_and_a_new_dish_go_live_at_once(): void
    {
        $group = ProductOptionGroup::query()->create([
            'product_id' => $this->dish->id, 'name_ar' => 'الحجم', 'name_en' => 'Size',
            'selection_type' => 'single', 'price_mode' => 'add', 'is_required' => false, 'min_select' => 0, 'max_select' => 1,
        ]);

        $this->asPlace()->postJson("/api/v1/restaurant/option-groups/{$group->id}/options", ['name_ar' => 'كبير', 'name_en' => 'Large', 'price_delta' => 20])
            ->assertCreated()
            ->assertJsonPath('data.price_delta', '20.00');

        $category = $this->dish->menuCategory;
        $this->asPlace()->postJson('/api/v1/restaurant/products', [
            'menu_category_id' => $category->id, 'name_ar' => 'طبق جديد', 'name_en' => 'New', 'price' => 75,
        ])->assertCreated()->assertJsonPath('data.price', '75.00')->assertJsonPath('data.pending_price_request', null);

        $this->assertSame(0, ProductPriceRequest::query()->count());
    }

    // ------------------------------------------------------- discount coherence

    public function test_a_discount_is_checked_against_the_price_it_will_stand_beside(): void
    {
        $this->dish->forceFill(['discount_price' => 80])->save();

        // Lowering the price under the live discount, without touching the discount.
        $this->patchDish(['price' => 70])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['discount_price']);

        // A discount-only edit used to fail `lt:price` against nothing; now it is
        // compared with the live price, and filed.
        $this->patchDish(['discount_price' => 90])
            ->assertOk()
            ->assertJsonPath('data.pending_price_request.requested_discount_price', 90);

        $this->patchDish(['discount_price' => 120])->assertStatus(422);

        // Clearing the discount is a request too.
        $this->patchDish(['discount_price' => null, 'price' => 70])
            ->assertOk()
            ->assertJsonPath('data.pending_price_request.clears_discount', true)
            ->assertJsonPath('data.pending_price_request.requested_price', 70);

        $id = ProductPriceRequest::query()->where('status', 'pending')->value('id');
        $this->actingAsAdmin($this->admin)->getJson("/api/v1/admin/price-requests/{$id}")
            ->assertOk()
            ->assertJsonPath('data.current_price', 100)
            ->assertJsonPath('data.current_discount_price', 80)
            ->assertJsonPath('data.live_discount_price', 80)
            // 70 effective against 80 effective.
            ->assertJsonPath('data.delta', -10);

        $this->actingAsAdmin($this->admin)->postJson("/api/v1/admin/price-requests/{$id}/approve")->assertOk();

        $fresh = $this->dish->fresh();
        $this->assertSame('70.00', (string) $fresh->price);
        $this->assertNull($fresh->discount_price);
    }

    // --------------------------------------------------------- the office answers

    public function test_approve_moves_the_price_everywhere_bumps_the_cache_and_is_audited_as_the_admin(): void
    {
        $etag = $this->getJson('/api/v1/restaurants/'.$this->place->id.'/products')->assertOk()->headers->get('ETag');

        $this->patchDish(['price' => 130])->assertOk();
        $id = ProductPriceRequest::query()->value('id');

        // Filing changed nothing a customer reads.
        $this->assertSame($etag, $this->getJson('/api/v1/restaurants/'.$this->place->id.'/products')->headers->get('ETag'));

        $this->actingAsAdmin($this->admin)
            ->postJson("/api/v1/admin/price-requests/{$id}/approve", ['note' => 'تمام'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.review_note', 'تمام')
            ->assertJsonPath('data.reviewed_by.id', $this->admin->id)
            ->assertJsonPath('data.live_price', 130);

        $this->assertSame('130.00', (string) $this->dish->fresh()->price);

        $after = $this->getJson('/api/v1/restaurants/'.$this->place->id.'/products')->assertOk();
        $after->assertJsonPath('data.0.price', 130);
        $this->assertNotSame($etag, $after->headers->get('ETag'));
        $this->getJson('/api/v1/products/'.$this->dish->id)->assertJsonPath('data.price', 130);

        $audit = AuditLog::query()->where('action', 'products.updated')->where('auditable_id', $this->dish->id)->latest('id')->first();
        $this->assertNotNull($audit, 'the approval wrote no audit row');
        $this->assertSame($this->admin->id, $audit->user_id);

        // The place is told, and its product payload shows the decision.
        $push = Notification::query()->where('user_id', $this->place->user_id)->where('type', 'price_request.approved')->first();
        $this->assertNotNull($push);
        $this->assertSame('تم اعتماد السعر الجديد', $push->payload['title_ar']);

        $this->asPlace()->getJson('/api/v1/restaurant/products/'.$this->dish->id)
            ->assertOk()
            ->assertJsonPath('data.pending_price_request', null)
            ->assertJsonPath('data.last_price_decision.status', 'approved');
    }

    public function test_a_double_approve_is_refused_and_writes_once(): void
    {
        $this->patchDish(['price' => 130])->assertOk();
        $request = ProductPriceRequest::query()->first();

        $this->actingAsAdmin($this->admin)->postJson("/api/v1/admin/price-requests/{$request->id}/approve")->assertOk();

        // A second operator on a stale screen.
        $this->actingAsAdmin($this->admin)
            ->postJson("/api/v1/admin/price-requests/{$request->id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('error', 'price_request_closed');
        $this->actingAsAdmin($this->admin)
            ->postJson("/api/v1/admin/price-requests/{$request->id}/reject", ['reason' => 'متأخر'])
            ->assertStatus(409)
            ->assertJsonPath('error', 'price_request_closed');

        $this->assertSame(1, AuditLog::query()->where('action', 'products.updated')->where('auditable_id', $this->dish->id)->count());
        $this->assertSame(1, Notification::query()->where('type', 'price_request.approved')->count());
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_reject_needs_a_reason_keeps_the_price_and_tells_the_place(): void
    {
        $this->patchDish(['price' => 130])->assertOk();
        $id = ProductPriceRequest::query()->value('id');

        $this->actingAsAdmin($this->admin)->postJson("/api/v1/admin/price-requests/{$id}/reject", [])
            ->assertStatus(422)->assertJsonValidationErrors(['reason']);

        $this->actingAsAdmin($this->admin)
            ->postJson("/api/v1/admin/price-requests/{$id}/reject", ['reason' => 'السعر أعلى من السوق'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.review_note', 'السعر أعلى من السوق');

        $this->assertSame('100.00', (string) $this->dish->fresh()->price);

        $push = Notification::query()->where('user_id', $this->place->user_id)->where('type', 'price_request.rejected')->first();
        $this->assertNotNull($push);
        $this->assertStringContainsString('السعر أعلى من السوق', $push->payload['body_ar']);

        $this->asPlace()->getJson('/api/v1/restaurant/products/'.$this->dish->id)
            ->assertJsonPath('data.last_price_decision.status', 'rejected')
            ->assertJsonPath('data.last_price_decision.review_note', 'السعر أعلى من السوق');
    }

    public function test_a_place_withdraws_only_its_own_pending_request(): void
    {
        $this->patchDish(['price' => 130])->assertOk();
        $id = ProductPriceRequest::query()->value('id');

        $other = $this->makeRestaurant('ناصر القياس');
        $this->actingAsPlace($other)->deleteJson("/api/v1/restaurant/price-requests/{$id}")->assertNotFound();

        $this->asPlace()->deleteJson("/api/v1/restaurant/price-requests/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'withdrawn');

        $this->asPlace()->deleteJson("/api/v1/restaurant/price-requests/{$id}")
            ->assertStatus(409)
            ->assertJsonPath('error', 'price_request_closed');

        $this->actingAsAdmin($this->admin)->postJson("/api/v1/admin/price-requests/{$id}/approve")->assertStatus(409);
        $this->assertSame('100.00', (string) $this->dish->fresh()->price);

        // A withdrawn request is history, not deleted, and a new change files afresh.
        $this->patchDish(['price' => 125])->assertOk()->assertJsonPath('meta.price_request', 'created');
        $this->assertSame(2, ProductPriceRequest::query()->count());
    }

    public function test_an_admin_edit_is_never_gated(): void
    {
        $this->actingAsAdmin($this->admin)
            ->patchJson("/api/v1/admin/restaurants/{$this->place->id}/products/{$this->dish->id}", ['price' => 150])
            ->assertOk();

        $this->assertSame('150.00', (string) $this->dish->fresh()->price);
        $this->assertSame(0, ProductPriceRequest::query()->count());
    }

    public function test_switched_off_the_place_writes_the_price_directly(): void
    {
        Settings::set('catalog.price_approval_enabled', false, 'catalog');

        $this->patchDish(['price' => 130])
            ->assertOk()
            ->assertJsonMissingPath('meta.price_request')
            ->assertJsonPath('data.price', '130.00');

        $this->assertSame('130.00', (string) $this->dish->fresh()->price);
        $this->assertSame(0, ProductPriceRequest::query()->count());
    }

    // ------------------------------------------------------------------ the queue

    public function test_the_queue_follows_the_admin_list_contract_and_the_scope(): void
    {
        $cityAdmin = $this->cityAdmin($this->otherCity);

        $this->patchDish(['price' => 130])->assertOk();
        $id = ProductPriceRequest::query()->value('id');

        $far = $this->makeRestaurant('مطعم المنصورة');
        $far->forceFill(['city_id' => $this->otherCity->id, 'service_zone_id' => $this->otherZone->id])->save();
        $farDish = $this->makeProduct($far, 'بيتزا', 90);
        $this->actingAsPlace($far)->patchJson('/api/v1/restaurant/products/'.$farDish->id, ['price' => 95])->assertOk();
        $farId = ProductPriceRequest::query()->where('product_id', $farDish->id)->value('id');

        $all = $this->actingAsAdmin($this->admin)->getJson('/api/v1/admin/price-requests')->assertOk();
        $all->assertJsonPath('meta.total', 2);
        $row = collect($all->json('data'))->firstWhere('id', $id);
        $this->assertSame('شاورما فراخ', $row['product']['name']);
        $this->assertSame('سندوتش عمر', $row['restaurant']['name']);
        $this->assertSame(30, (int) $row['delta']);

        $this->actingAsAdmin($this->admin)->getJson('/api/v1/admin/price-requests?'.http_build_query(['filter' => ['search' => 'بيتزا']]))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $farId);
        $this->actingAsAdmin($this->admin)->getJson('/api/v1/admin/price-requests?filter[restaurant_id]='.$this->place->id)
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAsAdmin($this->admin)->getJson('/api/v1/admin/price-requests?filter[status]=approved')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAsAdmin($this->admin)->getJson('/api/v1/admin/price-requests?filter[status]=bogus')->assertStatus(422);
        $this->actingAsAdmin($this->admin)->getJson('/api/v1/admin/price-requests?per_page=500')
            ->assertOk()->assertJsonPath('meta.per_page', 100);

        // A city operator sees only their city: the Gamasa request is a 404 to them.
        $this->actingAsAdmin($cityAdmin)->getJson('/api/v1/admin/price-requests')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $farId);
        $this->actingAsAdmin($cityAdmin)->getJson("/api/v1/admin/price-requests/{$id}")->assertNotFound();
        $this->actingAsAdmin($cityAdmin)->postJson("/api/v1/admin/price-requests/{$id}/approve")->assertNotFound();
        $this->assertSame('pending', ProductPriceRequest::query()->find($id)->status);

        // Each operator was told about the requests in their scope only.
        $this->assertSame(2, Notification::query()->where('user_id', $this->admin->id)->where('type', 'price_request.new_admin')->count());
        $told = Notification::query()->where('user_id', $cityAdmin->id)->where('type', 'price_request.new_admin')->get();
        $this->assertCount(1, $told);
        $this->assertSame('/price-requests/'.$farId, $told->first()->deep_link);
    }

    public function test_an_admin_without_the_permission_is_refused(): void
    {
        $this->patchDish(['price' => 130])->assertOk();
        $id = ProductPriceRequest::query()->value('id');

        $viewer = User::query()->create(['name' => 'مشاهد', 'phone' => '01000000077', 'type' => 'admin', 'is_active' => true]);
        $viewer->assignRole('viewer');
        $viewer->adminScopes()->create(['scope_type' => \App\Enums\AdminScopeType::Global]);

        $this->actingAsAdmin($viewer)->getJson('/api/v1/admin/price-requests')->assertForbidden();
        $this->actingAsAdmin($viewer)->postJson("/api/v1/admin/price-requests/{$id}/approve")->assertForbidden();

        // The dock and the summary do not offer it either.
        $this->actingAsAdmin($viewer)->getJson('/api/v1/admin/dashboard/open-alerts')
            ->assertOk()->assertJsonPath('data.counts.price_review', 0);
        $this->assertSame(0, Notification::query()->where('user_id', $viewer->id)->count());
    }

    public function test_the_dock_and_the_summary_count_waiting_prices(): void
    {
        $this->patchDish(['price' => 130])->assertOk();
        $id = ProductPriceRequest::query()->value('id');

        $this->actingAsAdmin($this->admin)->getJson('/api/v1/admin/dashboard/summary')
            ->assertOk()->assertJsonPath('data.pending_price_requests', 1);

        $dock = $this->actingAsAdmin($this->admin)->getJson('/api/v1/admin/dashboard/open-alerts')->assertOk();
        $dock->assertJsonPath('data.counts.price_review', 1);
        $item = collect($dock->json('data.items'))->firstWhere('kind', 'price_review');
        $this->assertSame($id, $item['id']);
        $this->assertSame('/price-requests/'.$id, $item['href']);
        $this->assertSame(130, (int) $item['amount']);

        $this->actingAsAdmin($this->admin)->getJson('/api/v1/admin/dashboard/alerts')
            ->assertOk()->assertJsonPath('data.price_reviews', 1);

        $this->actingAsAdmin($this->admin)->postJson('/api/v1/admin/dashboard/open-alerts/dismiss', ['kind' => 'price_review', 'id' => $id])->assertOk();
        $this->actingAsAdmin($this->admin)->getJson('/api/v1/admin/dashboard/open-alerts')->assertJsonPath('data.counts.price_review', 0);
    }

    public function test_the_database_holds_one_pending_request_per_dish(): void
    {
        $this->patchDish(['price' => 130])->assertOk();
        $row = ProductPriceRequest::query()->first();

        $this->expectException(\Illuminate\Database\QueryException::class);

        ProductPriceRequest::query()->create([
            'product_id' => $this->dish->id, 'restaurant_id' => $this->place->id, 'status' => 'pending',
            'current_price' => 100, 'requested_price' => 140,
        ]);

        $this->assertSame($row->id, ProductPriceRequest::query()->where('status', 'pending')->value('id'));
    }

    public function test_an_unknown_option_of_another_dish_is_a_404(): void
    {
        $otherDish = $this->makeProduct($this->makeRestaurant('مكان آخر'), 'طبق', 50);
        $group = ProductOptionGroup::query()->create([
            'product_id' => $otherDish->id, 'name_ar' => 'الحجم', 'name_en' => 'Size',
            'selection_type' => 'single', 'price_mode' => 'add', 'is_required' => false, 'min_select' => 0, 'max_select' => 1,
        ]);
        $option = ProductOption::query()->create(['product_option_group_id' => $group->id, 'name_ar' => 'كبير', 'name_en' => 'L', 'price_delta' => 5]);

        $this->asPlace()->patchJson("/api/v1/restaurant/option-groups/{$group->id}/options/{$option->id}", ['price_delta' => 50])->assertNotFound();
        $this->asPlace()->patchJson('/api/v1/restaurant/products/'.$otherDish->id, ['price' => 1])->assertNotFound();
        $this->assertSame(0, ProductPriceRequest::query()->count());
    }
}
