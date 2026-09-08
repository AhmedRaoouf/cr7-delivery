<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\StaticPage;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The one 403 in the API a client could not read.
 *
 * 27 `Store*Request` / `Update*Request` classes gate on a permission inside
 * `authorize()` rather than on the route. Laravel's handler runs
 * `prepareException()` before `renderViaCallbacks()`, and that rewrites a
 * status-less `AuthorizationException` into Symfony's
 * `AccessDeniedHttpException` — so the closure `bootstrap/app.php` registered
 * for `AuthorizationException` never matched. Every one of those refusals fell
 * through to the framework's renderer: no `success`, no `error`, an English
 * «This action is unauthorized.», and with `APP_DEBUG` on the server's own file
 * paths and a stack trace in the body.
 *
 * Route-level `permission:` middleware was never affected — it throws Spatie's
 * exception, which `prepareException` leaves alone. That is why the gap showed
 * only where the permission lives in the request.
 */
class FormRequestForbiddenShapeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class]);
    }

    public function test_a_form_request_refusal_answers_the_api_envelope(): void
    {
        Sanctum::actingAs($this->makeSupportAdmin());

        $response = $this->withHeader('X-App', 'admin')
            ->patchJson("/api/v1/admin/zones/{$this->zone->id}", ['delivery_fee' => 999]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'forbidden');

        // Nothing about the server's own filesystem belongs in a 403 body.
        $this->assertArrayNotHasKey('exception', $response->json());
        $this->assertArrayNotHasKey('trace', $response->json());
        $this->assertArrayNotHasKey('file', $response->json());
    }

    public function test_the_refusal_is_a_refusal_and_not_a_write(): void
    {
        Sanctum::actingAs($this->makeSupportAdmin());

        $this->withHeader('X-App', 'admin')
            ->patchJson("/api/v1/admin/zones/{$this->zone->id}", ['delivery_fee' => 999])
            ->assertStatus(403);

        $this->assertNotEquals(999, (float) $this->zone->refresh()->delivery_fee);
    }

    public function test_route_middleware_refusals_keep_their_own_shape(): void
    {
        Sanctum::actingAs($this->makeSupportAdmin());

        $this->withHeader('X-App', 'admin')
            ->getJson('/api/v1/admin/zones')
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden');
    }

    public function test_a_page_update_accepts_the_page_own_slug(): void
    {
        $page = StaticPage::query()->create([
            'slug' => 'terms',
            'title_ar' => 'الشروط والأحكام',
            'title_en' => 'Terms',
            'body_ar' => 'نص',
            'body_en' => 'Body',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->makeAdmin());

        // The slug is the route key. Round-tripping the row it came from was
        // refused with «slug مستخدم من قبل» on a slug that never changed.
        $this->withHeader('X-App', 'admin')
            ->patchJson("/api/v1/admin/pages/{$page->slug}", [
                'slug' => 'terms',
                'title_ar' => 'الشروط',
            ])
            ->assertOk();

        $this->assertSame('الشروط', $page->refresh()->title_ar);
    }

    public function test_a_page_cannot_take_another_page_slug(): void
    {
        StaticPage::query()->create([
            'slug' => 'terms',
            'title_ar' => 'الشروط',
            'title_en' => 'Terms',
            'body_ar' => 'نص',
            'body_en' => 'Body',
            'is_active' => true,
        ]);

        StaticPage::query()->create([
            'slug' => 'privacy',
            'title_ar' => 'الخصوصية',
            'title_en' => 'Privacy',
            'body_ar' => 'نص',
            'body_en' => 'Body',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->makeAdmin());

        $this->withHeader('X-App', 'admin')
            ->patchJson('/api/v1/admin/pages/terms', ['slug' => 'privacy'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');
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

    /** An admin with a real role that holds no `locations.*` permission. */
    private function makeSupportAdmin(): User
    {
        $admin = User::query()->create([
            'name' => 'Support',
            'phone' => '01000000077',
            'type' => 'admin',
            'is_active' => true,
        ]);

        $admin->assignRole('support');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        return $admin;
    }
}
