<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\Settlement;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `ADM-27` — «تم تسجيل الدفع بنجاح», and where the money actually went.
 *
 * doc 08 §7 names the request field `reference`; the column is
 * `payment_reference`. Spread straight into `update()` the key matched no
 * fillable attribute, so mass assignment dropped it without a word: every
 * settlement was marked paid with a null reference, and the audit log recorded
 * an intent the row never carried.
 */
class SettlementPaymentReferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class]);
    }

    private function settlement(): Settlement
    {
        return Settlement::create([
            'party_type' => 'restaurant',
            'party_id' => 1,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'gross_sales' => 1410,
            'refunds_total' => 0,
            'commission_total' => 141,
            'delivery_fees_total' => 65,
            'platform_fee_total' => 25,
            'cash_collected' => 1500,
            'net_amount' => 1269,
            'status' => 'draft',
        ]);
    }

    public function test_paying_a_settlement_stores_the_transfer_reference(): void
    {
        $settlement = $this->settlement();
        Sanctum::actingAs($this->makeAdmin());

        $this->withHeader('X-App', 'admin')
            ->postJson("/api/v1/admin/settlements/{$settlement->id}/pay", [
                'reference' => 'INSTAPAY-99120',
                'note' => 'تحويل بنكي',
            ])
            ->assertOk();

        $settlement->refresh();

        $this->assertSame('paid', $settlement->status->value);
        $this->assertSame('INSTAPAY-99120', $settlement->payment_reference);
        $this->assertSame('تحويل بنكي', $settlement->note);
        $this->assertNotNull($settlement->paid_at);
    }

    public function test_a_settlement_can_be_paid_without_a_reference(): void
    {
        $settlement = $this->settlement();
        Sanctum::actingAs($this->makeAdmin());

        $this->withHeader('X-App', 'admin')
            ->postJson("/api/v1/admin/settlements/{$settlement->id}/pay", [])
            ->assertOk();

        $this->assertSame('paid', $settlement->refresh()->status->value);
        $this->assertNull($settlement->payment_reference);
    }

    public function test_paying_twice_is_refused(): void
    {
        $settlement = $this->settlement();
        Sanctum::actingAs($this->makeAdmin());

        $this->withHeader('X-App', 'admin')
            ->postJson("/api/v1/admin/settlements/{$settlement->id}/pay", ['reference' => 'A'])
            ->assertOk();

        $this->withHeader('X-App', 'admin')
            ->postJson("/api/v1/admin/settlements/{$settlement->id}/pay", ['reference' => 'B'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_transition');

        // The first reference stands. A refused second attempt must not have
        // half-written itself over the record of the payment that happened.
        $this->assertSame('A', $settlement->refresh()->payment_reference);
    }

    private function makeAdmin(): User
    {
        $admin = User::query()->create([
            'name' => 'Admin',
            'phone' => '01000000099',
            'type' => 'admin',
            'is_active' => true,
        ]);

        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        return $admin;
    }
}
