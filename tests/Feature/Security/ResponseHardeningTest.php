<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Batch 67 — what every answer from this origin says about itself, measured on
 * production before the change: `x-powered-by: PHP/8.3.33`, `access-control-allow-origin:
 * *`, none of the four standard security headers, a cookie-setting Laravel welcome page
 * printing «Laravel v11.56.0 (PHP v8.3.33)» at `/`, and `/sanctum/csrf-cookie` minting a
 * session nobody uses.
 */
class ResponseHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_api_answer_carries_the_security_headers(): void
    {
        config(['security.hsts.enabled' => true]);

        $response = $this->getJson('/api/v1/config')->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeader('Cross-Origin-Resource-Policy', 'same-site');
        $response->assertHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
        $this->assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
        $response->assertHeaderMissing('X-Powered-By');
    }

    public function test_an_answer_no_route_produced_carries_them_too(): void
    {
        $response = $this->getJson('/api/v1/nothing-lives-here')->assertNotFound();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $this->assertSame('not_found', $response->json('error'));
    }

    public function test_hsts_is_off_where_it_would_lock_a_developer_out(): void
    {
        config(['security.hsts.enabled' => false]);

        $this->getJson('/api/v1/config')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_cors_admits_the_two_dashboards_and_nobody_else(): void
    {
        $evil = $this->call('OPTIONS', '/api/v1/auth/login', server: [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);
        $this->assertNull($evil->headers->get('Access-Control-Allow-Origin'));

        $simple = $this->call('GET', '/api/v1/config', server: ['HTTP_ORIGIN' => 'https://evil.example']);
        $this->assertNull($simple->headers->get('Access-Control-Allow-Origin'));

        $dashboard = $this->call('OPTIONS', '/api/v1/auth/login', server: [
            'HTTP_ORIGIN' => 'https://admin.cr7-delivery.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        $this->assertSame('https://admin.cr7-delivery.com', $dashboard->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('true', $dashboard->headers->get('Access-Control-Allow-Credentials'));
    }

    public function test_the_root_is_quiet_and_sets_no_cookie(): void
    {
        $response = $this->get('/')->assertOk();

        $this->assertStringNotContainsString('Laravel v', (string) $response->getContent());
        $this->assertStringNotContainsString('PHP v', (string) $response->getContent());
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_the_public_web_routes_start_no_session(): void
    {
        $this->get('/.well-known/apple-app-site-association')->assertOk();
        $this->assertSame([], $this->get('/.well-known/apple-app-site-association')->headers->getCookies());

        // Sanctum's SPA cookie endpoint: no client of this API holds a web session (D-69).
        $this->get('/sanctum/csrf-cookie')->assertNotFound();
    }

    public function test_a_page_can_never_be_a_whole_table(): void
    {
        $this->getJson('/api/v1/restaurants?per_page=1000000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 200);

        $this->getJson('/api/v1/restaurants?per_page=-5')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1);
    }
}
