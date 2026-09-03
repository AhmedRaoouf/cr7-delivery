<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthorizeTelescope;
use App\Providers\TelescopeServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Telescope\IncomingEntry;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Telescope now runs on the deployed API, which makes two things load-bearing:
 * who may read it, and whether an entry can be attributed to the client that
 * produced it.
 *
 * The panel prints request bodies — every address, phone number and order that
 * moved through the API. So the first six tests are about the door, and each of
 * them asserts **404**: 403 would confirm to a stranger that there is a panel
 * here at all.
 */
class TelescopeAccessTest extends TestCase
{
    private function pass(Request $request): Response
    {
        return (new AuthorizeTelescope)->handle($request, fn () => new Response('ok'));
    }

    private function panelRequest(string $uri = '/telescope', array $cookies = []): Request
    {
        return Request::create($uri, 'GET', [], $cookies);
    }

    public function test_it_refuses_when_no_token_is_configured(): void
    {
        // The dangerous default: TELESCOPE_ENABLED=true was copied to a server and
        // TELESCOPE_TOKEN was not. That must close the panel, not publish it.
        config(['telescope.auth_token' => '']);

        $this->expectException(NotFoundHttpException::class);
        $this->pass($this->panelRequest('/telescope?telescope_token=anything'));
    }

    public function test_it_refuses_a_request_with_no_credentials(): void
    {
        config(['telescope.auth_token' => 'the-secret']);

        $this->expectException(NotFoundHttpException::class);
        $this->pass($this->panelRequest());
    }

    public function test_it_refuses_a_wrong_token(): void
    {
        config(['telescope.auth_token' => 'the-secret']);

        $this->expectException(NotFoundHttpException::class);
        $this->pass($this->panelRequest('/telescope?telescope_token=not-the-secret'));
    }

    public function test_it_refuses_a_wrong_cookie(): void
    {
        config(['telescope.auth_token' => 'the-secret']);

        $this->expectException(NotFoundHttpException::class);
        $this->pass($this->panelRequest('/telescope', ['telescope_token' => 'stale']));
    }

    public function test_the_right_token_passes_and_is_remembered_in_a_cookie(): void
    {
        config(['telescope.auth_token' => 'the-secret']);

        $response = $this->pass($this->panelRequest('/telescope?telescope_token=the-secret'));

        $this->assertSame(200, $response->getStatusCode());

        $cookies = $response->headers->getCookies();
        $this->assertCount(1, $cookies);
        $this->assertSame('telescope_token', $cookies[0]->getName());
        $this->assertSame('the-secret', $cookies[0]->getValue());
        // Telescope's own XHR calls carry it; nothing in a page needs to read it.
        $this->assertTrue($cookies[0]->isHttpOnly());
    }

    public function test_the_cookie_alone_passes_without_re_issuing_it(): void
    {
        config(['telescope.auth_token' => 'the-secret']);

        $response = $this->pass($this->panelRequest('/telescope', ['telescope_token' => 'the-secret']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(0, $response->headers->getCookies());
    }

    /**
     * doc 08 §1 — `X-App` is `app` for the Flutter binary, `restaurant` and `admin`
     * for the two dashboards. D-45 makes the customer and the captain one binary, so
     * `X-App` alone cannot tell them apart and `X-Role` is what does.
     */
    public function test_a_request_entry_is_tagged_by_its_client(): void
    {
        $entry = IncomingEntry::make([
            'headers' => ['x-app' => 'app', 'x-role' => 'driver', 'x-platform' => 'ios'],
            'response_status' => 200,
            'user' => ['id' => 7],
        ])->type('request');

        $this->assertSame(
            ['app:app', 'status:200', 'role:driver', 'platform:ios', 'user:7'],
            TelescopeServiceProvider::clientTags($entry),
        );
    }

    public function test_a_failed_request_carries_the_failed_tag(): void
    {
        $entry = IncomingEntry::make([
            'headers' => ['x-app' => 'admin'],
            'response_status' => 422,
        ])->type('request');

        $tags = TelescopeServiceProvider::clientTags($entry);

        $this->assertContains('failed', $tags);
        $this->assertContains('status:422', $tags);
        $this->assertContains('app:admin', $tags);
    }

    public function test_a_client_that_sends_no_header_is_still_countable(): void
    {
        // curl, a health check, a scanner. `unknown` is a filterable value; a
        // missing tag is a row that falls out of every filter.
        $entry = IncomingEntry::make(['headers' => [], 'response_status' => 200])->type('request');

        $this->assertContains('app:unknown', TelescopeServiceProvider::clientTags($entry));
    }

    public function test_non_request_entries_are_left_alone(): void
    {
        $entry = IncomingEntry::make(['sql' => 'select 1'])->type('query');

        $this->assertSame([], TelescopeServiceProvider::clientTags($entry));
    }
}
