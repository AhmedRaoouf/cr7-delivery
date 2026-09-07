<?php

namespace Tests\Feature;

use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Every `permission:` middleware names a permission that exists.
 *
 * `permission:orders.update_status` shipped on the scheduled-order release route. No
 * such permission is in the catalogue, nothing grants it, and Spatie's middleware
 * therefore refused **everybody** — including the super admin. The route answered
 * «ليس لديك الصلاحية» to the one person who has every one of them, and nothing in the
 * type system, the test suite or a code review would have said so.
 *
 * It was found by pressing the button on production. This is so the next one is found
 * here instead.
 */
class RoutePermissionCatalogueTest extends TestCase
{
    public function test_every_permission_middleware_names_a_real_permission(): void
    {
        $this->seed(PermissionsSeeder::class);

        $known = Permission::query()->pluck('name')->all();
        $unknown = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'permission:')) {
                    continue;
                }

                // `permission:a|b` is «either», which Spatie supports.
                foreach (explode('|', substr($middleware, strlen('permission:'))) as $name) {
                    $name = trim($name);

                    if ($name !== '' && ! in_array($name, $known, true)) {
                        $unknown[$name][] = $route->uri();
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $unknown,
            'these routes are gated on permissions nothing grants: '.json_encode(
                array_map(fn (array $uris) => array_values(array_unique($uris)), $unknown),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        );
    }
}
