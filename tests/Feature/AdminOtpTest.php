<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\OtpBlock;
use App\Models\SmsSend;
use App\Models\User;
use App\Support\OtpGuard;
use App\Support\Settings;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Concerns\FakesOtpChannel;
use Tests\TestCase;

/**
 * Batch 68 — `GET /admin/otp/usage`, `GET|POST /admin/otp/blocks` and
 * `DELETE /admin/otp/blocks/{id}`: what verification messages cost today, and the numbers
 * the platform has stopped sending to. `settings.view` reads, `settings.manage` writes,
 * global admins only, and every write is an `audit_logs` row.
 */
class AdminOtpTest extends TestCase
{
    use FakesOtpChannel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class]);
        $this->fakeOtpChannel();
        $this->travelTo(Carbon::parse('2026-09-18 06:00:00'));
        $this->withHeaders(['Accept-Language' => 'ar']);
    }

    /** @param  list<string>|null  $permissions  null = super admin */
    private function actingAsAdmin(?array $permissions = null, bool $global = true): User
    {
        $admin = User::factory()->create(['type' => 'admin']);

        if ($permissions === null) {
            $admin->assignRole('super_admin');
        } else {
            $role = Role::findOrCreate('otp_role_'.count($permissions), 'web');
            $role->syncPermissions($permissions);
            $admin->assignRole($role);
        }

        if ($global) {
            $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        } else {
            $city = City::query()->create([
                'name_ar' => 'جمصة', 'name_en' => 'Gamasa', 'center_lat' => 31.4388, 'center_lng' => 31.5442,
                'pricing_type' => 'fixed_zone', 'default_min_order' => 50, 'is_active' => true,
            ]);
            $admin->adminScopes()->create(['scope_type' => AdminScopeType::City, 'city_id' => $city->id]);
        }

        Sanctum::actingAs($admin);
        $this->withHeader('X-App', 'admin');

        return $admin;
    }

    private function sent(string $phone, string $kind, string $purpose = 'login'): void
    {
        SmsSend::query()->create(['phone' => $phone, 'purpose' => $purpose, 'kind' => $kind, 'ip' => '10.0.0.1']);
    }

    public function test_usage_counts_today_the_hour_the_blocks_and_the_busiest_numbers(): void
    {
        // Yesterday on the business clock — not today's.
        $this->travelTo(Carbon::parse('2026-09-17 20:00:00'));
        $this->sent('01011100001', 'real');

        $this->travelTo(Carbon::parse('2026-09-18 03:00:00'));
        $this->sent('01011100001', 'real', 'reset');

        $this->travelTo(Carbon::parse('2026-09-18 06:00:00'));
        $this->sent('01011100001', 'real');
        $this->sent('01011100001', 'real', 'register');
        $this->sent('01011100002', 'shadow', 'reset');
        $this->sent('01011100002', 'shadow', 'reset');
        $this->sent('01011100003', 'test');
        Settings::set('otp.daily_budget', 300, 'otp');
        $block = OtpGuard::block('01011100002', OtpBlock::REASON_DAILY_CAP, now()->addHours(24));

        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/otp/usage')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => [
                    'today' => ['sent' => 3, 'budget' => 300, 'shadow' => 2],
                    'hour' => ['sent' => 2, 'budget' => 100],
                    'blocked_now' => 1,
                    'by_purpose' => [
                        'login' => 2,
                        'register' => 1,
                        'reset' => 3,
                        'verify' => 0,
                        'address_phone' => 0,
                        'password_change' => 0,
                    ],
                    'top_numbers' => [
                        ['phone' => '01011100001', 'sent_today' => 3, 'blocked_until' => null],
                        ['phone' => '01011100002', 'sent_today' => 2, 'blocked_until' => $block->blocked_until->utc()->toIso8601String()],
                        ['phone' => '01011100003', 'sent_today' => 1, 'blocked_until' => null],
                    ],
                ],
            ]);
    }

    public function test_the_block_list_is_paged_searchable_and_active_unless_asked(): void
    {
        $admin = $this->actingAsAdmin();

        OtpGuard::block('01011100001', OtpBlock::REASON_DAILY_CAP, now()->addHours(24));
        OtpGuard::block('01011100002', OtpBlock::REASON_BURNED_CODES, now()->addHours(24));
        OtpGuard::block('01022200003', OtpBlock::REASON_ADMIN, now()->addHours(2), 'اتصالات مزعجة', $admin);
        $ended = OtpGuard::block('01011100004', OtpBlock::REASON_DAILY_CAP, now()->subHour());

        $page = $this->getJson('/api/v1/admin/otp/blocks?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonCount(2, 'data');

        $first = $page->json('data.0');
        $this->assertSame('01022200003', $first['phone']);
        $this->assertSame('admin', $first['reason']);
        $this->assertSame('اتصالات مزعجة', $first['note']);
        $this->assertSame(['id' => $admin->id, 'name' => $admin->name], $first['created_by']);
        $this->assertSame(['id', 'phone', 'reason', 'blocked_until', 'note', 'created_at', 'created_by', 'lifted_at'], array_keys($first));

        foreach (['+20 102', '0020102', '0102', '٠١٠٢٢٢٠٠٠٠٣'] as $typed) {
            $this->getJson('/api/v1/admin/otp/blocks?'.http_build_query(['filter' => ['search' => $typed]]))
                ->assertOk()
                ->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.phone', '01022200003');
        }

        $all = $this->getJson('/api/v1/admin/otp/blocks?filter[status]=all&per_page=50')->assertOk();
        $this->assertSame(4, $all->json('meta.total'));
        $this->assertContains($ended->id, array_column($all->json('data'), 'id'));
    }

    public function test_an_operator_blocks_a_number_by_hand_and_it_is_audited(): void
    {
        $admin = $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/otp/blocks', ['phone' => '+20 101 110 0001', 'hours' => 2, 'note' => 'رسائل متكررة'])
            ->assertStatus(201)
            ->assertJsonPath('data.phone', '01011100001')
            ->assertJsonPath('data.reason', 'admin')
            ->assertJsonPath('data.note', 'رسائل متكررة')
            ->assertJsonPath('data.blocked_until', now()->addHours(2)->utc()->toIso8601String())
            ->assertJsonPath('data.created_by.id', $admin->id);

        $audit = AuditLog::query()->where('action', 'otp.block')->sole();
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame($response->json('data.id'), $audit->auditable_id);

        // The number is refused from now on, send and check alike.
        $this->withHeader('X-App', 'app')
            ->postJson('/api/v1/auth/otp/send', ['phone' => '01011100001'])
            ->assertStatus(423)
            ->assertJsonPath('error', 'otp_phone_blocked')
            ->assertJsonPath('meta.retry_after', 7200);

        // A second block replaces the first rather than stacking under it.
        $this->withHeader('X-App', 'admin')
            ->postJson('/api/v1/admin/otp/blocks', ['phone' => '01011100001', 'hours' => 1, 'reason' => 'abuse'])
            ->assertStatus(201);
        $this->assertSame(1, OtpBlock::query()->active()->where('phone', '01011100001')->count());
        $this->assertSame('abuse', OtpBlock::query()->active()->where('phone', '01011100001')->value('reason'));
    }

    public function test_a_manual_block_is_validated(): void
    {
        $this->actingAsAdmin();

        foreach ([
            ['phone' => '01011100001', 'hours' => 0],
            ['phone' => '01011100001', 'hours' => 721],
            ['phone' => '01011100001'],
            ['phone' => '12345', 'hours' => 2],
            ['phone' => '01011100001', 'hours' => 2, 'reason' => 'daily_cap'],
        ] as $body) {
            $this->postJson('/api/v1/admin/otp/blocks', $body)->assertStatus(422)->assertJsonPath('error', 'validation_failed');
        }

        $this->assertSame(0, OtpBlock::query()->count());
    }

    public function test_unblocking_lifts_every_block_on_the_number_and_starts_its_counts_over(): void
    {
        $admin = $this->actingAsAdmin();
        $this->otpTestMode();
        Settings::set('otp.max_per_day', 2, 'otp');

        $send = fn () => $this->withHeader('X-App', 'app')->postJson('/api/v1/auth/otp/send', ['phone' => '01011100001']);

        $send()->assertOk();
        $this->travel(60)->seconds();
        $send()->assertOk()->assertJsonPath('data.resend_in', 120);
        $this->travel(120)->seconds();
        $send()->assertStatus(423)->assertJsonPath('error', 'otp_phone_blocked');

        $block = OtpBlock::query()->sole();

        $this->withHeader('X-App', 'admin')
            ->deleteJson("/api/v1/admin/otp/blocks/{$block->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $block->id)
            ->assertJsonPath('data.lifted_at', now()->utc()->toIso8601String());

        $this->assertNotNull($block->fresh()->lifted_at);
        $this->assertSame($admin->id, $block->fresh()->lifted_by);
        $this->assertSame(1, AuditLog::query()->where('action', 'otp.unblock')->count());

        // Cleared, not just unblocked: no daily count to block it again at once, and the
        // escalating wait is back at its first step.
        $send()->assertOk()->assertJsonPath('data.resend_in', 60);

        // Idempotent — and a block that already ended still starts the counts over.
        $this->withHeader('X-App', 'admin')->deleteJson("/api/v1/admin/otp/blocks/{$block->id}")->assertOk();
        $this->withHeader('X-App', 'admin')->deleteJson('/api/v1/admin/otp/blocks/999999')->assertNotFound();
    }

    public function test_reading_needs_settings_view_writing_needs_settings_manage_and_both_need_global_scope(): void
    {
        $block = OtpGuard::block('01011100001', OtpBlock::REASON_DAILY_CAP, now()->addDay());

        // No settings permission at all.
        $this->actingAsAdmin(['orders.view']);
        $this->getJson('/api/v1/admin/otp/usage')->assertForbidden();
        $this->getJson('/api/v1/admin/otp/blocks')->assertForbidden();

        // Reads, but may not write.
        $this->actingAsAdmin(['settings.view']);
        $this->getJson('/api/v1/admin/otp/usage')->assertOk();
        $this->getJson('/api/v1/admin/otp/blocks')->assertOk();
        $this->postJson('/api/v1/admin/otp/blocks', ['phone' => '01011100002', 'hours' => 1])->assertForbidden();
        $this->deleteJson("/api/v1/admin/otp/blocks/{$block->id}")->assertForbidden();

        // Every permission, but a city's scope: the routes do not exist for them.
        $this->actingAsAdmin(null, global: false);
        $this->getJson('/api/v1/admin/otp/usage')->assertNotFound();
        $this->postJson('/api/v1/admin/otp/blocks', ['phone' => '01011100002', 'hours' => 1])->assertNotFound();
        $this->deleteJson("/api/v1/admin/otp/blocks/{$block->id}")->assertNotFound();

        // Not an admin at all.
        Sanctum::actingAs(User::factory()->create(['type' => 'customer']));
        $this->withHeader('X-App', 'app')->getJson('/api/v1/admin/otp/usage')->assertForbidden();

        $this->assertNull($block->fresh()->lifted_at);
        $this->assertSame(0, AuditLog::query()->whereIn('action', ['otp.block', 'otp.unblock'])->count());
    }
}
