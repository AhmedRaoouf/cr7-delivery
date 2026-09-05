<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «الإيصالات» — the transfer receipts on an order.
 *
 * Operator, 2026-09-05: «في الأوردرات يبقى فيه حاجة اسمها الإيصالات … إيصال التحويل
 * وكده، وكذلك في الأدمين تبقى ظهراله كل حاجة».
 *
 * The receipts themselves were already being stored; what was missing was that anything
 * *showed* them. The rule they are shown under is doc 12 §5: the image lives on the
 * private disk and its path never leaves the server. Every payload carries a gated URL
 * instead, and there are two gates — the admin's, and the customer's own, which has to
 * check both that the order group is theirs and that the receipt belongs to that group's
 * payment. Either check alone lets a signed-in customer read someone else's transfer.
 */
class OrderReceiptsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class]);
        Storage::fake('local');
    }

    /** @return array{0: OrderGroup, 1: Order, 2: Payment, 3: User} */
    private function paidOrder(): array
    {
        $customer = $this->makeCustomer();
        $restaurant = $this->makeRestaurant('سندوتش عمر');

        $group = OrderGroup::query()->create([
            'group_number' => 'G'.random_int(100000, 999999),
            'idempotency_key' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'city_id' => $this->city->id,
            'service_zone_id' => $this->zone->id,
            'payment_method' => 'instapay',
            'rollup_status' => 'awaiting_payment',
            'subtotal' => 100,
            'delivery_fee' => 25,
            'total' => 125,
        ]);

        $order = Order::query()->create([
            'order_group_id' => $group->id,
            'order_number' => 'O'.random_int(100000, 999999),
            'idempotency_key' => (string) Str::uuid(),
            'group_sequence' => 1,
            'user_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'restaurant_name_snapshot' => $restaurant->name_ar,
            'city_id' => $this->city->id,
            'service_zone_id' => $this->zone->id,
            'status' => 'awaiting_payment',
            'payment_method' => 'instapay',
            'delivery_address_text' => 'شارع البحر',
            'delivery_latitude' => 31.3835,
            'delivery_longitude' => 31.4835,
            'subtotal' => 100,
            'delivery_fee_share' => 25,
            'total' => 125,
        ]);

        $payment = Payment::query()->create([
            'order_group_id' => $group->id,
            'user_id' => $customer->id,
            'method' => 'instapay',
            'status' => 'under_review',
            'amount' => 125,
            'gate_opened_at' => now(),
            'deadline_at' => now()->addMinutes(15),
        ]);

        Storage::disk('local')->put('payment-proofs/receipt.jpg', 'jpeg-bytes');

        $payment->proofs()->create([
            'image' => 'payment-proofs/receipt.jpg',
            'reference' => 'TRX-99114',
            'amount_claimed' => 125,
            'sender_number' => '01099450110',
            'status' => 'pending',
        ]);

        return [$group, $order, $payment, $customer];
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_the_admin_order_detail_carries_the_receipts(): void
    {
        [, $order] = $this->paidOrder();
        $this->admin();

        $response = $this->withHeaders(['X-App' => 'admin'])
            ->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertOk();

        $receipts = $response->json('data.order_group.receipts');

        $this->assertCount(1, $receipts);
        $this->assertSame('TRX-99114', $receipts[0]['reference']);
        $this->assertSame(125.0, (float) $receipts[0]['amount_claimed']);
        $this->assertSame('01099450110', $receipts[0]['sender_number']);
        $this->assertSame('pending', $receipts[0]['status']);
    }

    public function test_the_private_path_never_leaves_the_server(): void
    {
        [, $order] = $this->paidOrder();
        $this->admin();

        $body = $this->withHeaders(['X-App' => 'admin'])
            ->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertOk()
            ->getContent();

        // The disk path is guessable and the disk is private for a reason. What the
        // payload carries is a route that checks who is asking.
        $this->assertStringNotContainsString('payment-proofs/receipt.jpg', $body);
        $this->assertStringContainsString('/file', $body);
    }

    public function test_the_admin_gate_streams_the_image(): void
    {
        [, , $payment] = $this->paidOrder();
        $this->admin();

        $proof = $payment->proofs()->sole();

        $this->withHeaders(['X-App' => 'admin'])
            ->get("/api/v1/admin/payment-proofs/{$proof->id}/file")
            ->assertOk();
    }

    public function test_a_customer_sees_their_own_receipts_and_the_note_on_a_rejected_one(): void
    {
        [$group, , $payment, $customer] = $this->paidOrder();

        $payment->proofs()->sole()->update([
            'status' => 'rejected',
            'review_note' => 'الإيصال غير واضح',
            'reviewed_at' => now(),
        ]);

        Sanctum::actingAs($customer);

        $response = $this->withHeaders(['X-App' => 'app'])
            ->getJson("/api/v1/orders/{$group->id}/payment")
            ->assertOk();

        $receipts = $response->json('data.receipts');

        $this->assertCount(1, $receipts);
        // Re-sending a receipt without being told what was wrong with the first is how
        // a payment stalls until the deadline runs out.
        $this->assertSame('الإيصال غير واضح', $receipts[0]['review_note']);
        $this->assertStringContainsString("orders/{$group->id}/payment/proofs/", $receipts[0]['file_url']);
    }

    public function test_a_customer_cannot_read_someone_elses_receipt(): void
    {
        [, , $payment] = $this->paidOrder();
        $proof = $payment->proofs()->sole();

        // A second customer with their own order group. Passing their group id and the
        // first customer's receipt id is the attack the double check exists for.
        [$otherGroup, , , $otherCustomer] = $this->paidOrder();

        Sanctum::actingAs($otherCustomer);

        $this->withHeaders(['X-App' => 'app'])
            ->get("/api/v1/orders/{$otherGroup->id}/payment/proofs/{$proof->id}/file")
            ->assertNotFound();
    }
}
