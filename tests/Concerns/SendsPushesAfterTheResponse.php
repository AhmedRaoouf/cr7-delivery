<?php

namespace Tests\Concerns;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The web path a push takes, for tests — the same three moves `PlaceRingTest` makes.
 *
 * Over HTTP a push leaves through `dispatch($send)->afterResponse()`, which serialises the
 * closure's **source** and parses it back on the other side; a console test calls the
 * closure in place and never makes that trip. On 2026-09-14 a closure written as a
 * ternary's branch passed ‪699‬ tests and silenced every place push in production. Every
 * new or changed push path gets one test through here.
 */
trait SendsPushesAfterTheResponse
{
    /** What an HTTP request sees: `runningInConsole()` false, so the push is deferred. */
    protected function pretendThisIsAWebRequest(): void
    {
        (new \ReflectionProperty(app(), 'isRunningInConsole'))->setValue(app(), false);
        $this->assertFalse(app()->runningInConsole());
    }

    /**
     * The server runs one app per request; a test runs one app for everything, and its
     * `terminate()` keeps the callbacks it ran — the next request would send them again.
     */
    protected function sendWhatWaitsForTheResponse(): void
    {
        app()->terminate();
        (new \ReflectionProperty(app(), 'terminatingCallbacks'))->setValue(app(), []);
    }

    protected function configureFirebase(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        $path = storage_path('framework/testing/service-account.json');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, json_encode([
            'type' => 'service_account',
            'project_id' => 'cr7-test',
            'client_email' => 'test@cr7-test.iam.gserviceaccount.com',
            'private_key' => $pem,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR));

        config(['services.firebase.credentials' => $path]);
    }

    protected function fakeFcm(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/cr7-test/messages/1']),
        ]);
        Cache::forget('fcm:access-token');
    }

    /** @return list<array<string, mixed>> every FCM message sent to this token */
    protected function messagesTo(string $token): array
    {
        return Http::recorded()
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $request) => str_contains($request->url(), 'fcm.googleapis.com'))
            ->map(fn (Request $request) => $request->data()['message'] ?? [])
            ->filter(fn (array $message) => ($message['token'] ?? null) === $token)
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> the messages of this `type` sent to this token */
    protected function pushesOfType(string $token, string $type): array
    {
        return array_values(array_filter($this->messagesTo($token), fn (array $m) => ($m['data']['type'] ?? null) === $type));
    }
}
