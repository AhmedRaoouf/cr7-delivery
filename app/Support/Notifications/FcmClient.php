<?php

namespace App\Support\Notifications;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * FCM HTTP v1, spoken directly.
 *
 * There is deliberately no `kreait/firebase-php` here. That package would pull
 * google/auth, google/cloud-core and a second Guzzle configuration onto a shared
 * host where `composer install` is already the slowest step of a deploy, to do
 * what these ninety lines do: sign a JWT with the service-account key, trade it
 * for an access token, and POST JSON. `openssl_sign` is core PHP and the HTTP
 * client is Laravel's own.
 *
 * Three properties this class guarantees, because the callers rely on all three:
 *
 * 1. **It never throws.** A push is not worth failing an order for. Everything
 *    returns a per-token result and logs; `send()` on a misconfigured install
 *    returns «not configured» for every message rather than exploding.
 * 2. **N tokens cost one round trip, not N.** FCM v1 retired the multicast
 *    endpoint, so a customer with a phone and a tablet, or three restaurant
 *    screens, would otherwise be three sequential HTTPS calls inside a request
 *    that is already doing a database write. `Http::pool()` issues them
 *    concurrently.
 * 3. **The access token is fetched at most once an hour.** Google issues it for
 *    3600 s; caching it for 55 minutes keeps a minute of slack for clock skew
 *    and takes the OAuth round trip off all but one send per hour.
 */
class FcmClient
{
    private const CACHE_KEY = 'fcm:access-token';

    /** Google issues 3600 s. Ask again at 3300 so a token is never used at its edge. */
    private const TOKEN_TTL_SECONDS = 3300;

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** Long enough for a slow mobile network at Google's end, short enough not to hold a request. */
    private const TIMEOUT_SECONDS = 8;

    /** `brand/base`. Tints the small icon and the app name in the shade. */
    private const BRAND_COLOR = '#FFC800';

    /**
     * Parsed once per instance, not once per process.
     *
     * This was a `static` local, which is the same thing everywhere except in a
     * long-lived process — a test run, Octane, a queue worker — where it would
     * outlive the config it was read from and quietly serve the first project's
     * key to every later request. The class is resolved per request, so an
     * instance property gives the same single parse with none of that.
     *
     * @var array<string, string>|null
     */
    private ?array $credentials = null;

    public function isConfigured(): bool
    {
        $path = config('services.firebase.credentials');

        return is_string($path) && $path !== '' && is_readable($path);
    }

    /**
     * @param  array<int, array{token: string, title: string, body: string, data?: array<string, string>, channel?: string}>  $messages
     * @return array<int, array{ok: bool, error: ?string, unregistered: bool}>
     */
    public function send(array $messages): array
    {
        if ($messages === []) {
            return [];
        }

        if (! $this->isConfigured()) {
            return array_map(
                fn () => ['ok' => false, 'error' => 'fcm_not_configured', 'unregistered' => false],
                $messages,
            );
        }

        $accessToken = $this->accessToken();
        $projectId = $this->credentials()['project_id'] ?? null;

        if ($accessToken === null || $projectId === null) {
            return array_map(
                fn () => ['ok' => false, 'error' => 'fcm_auth_failed', 'unregistered' => false],
                $messages,
            );
        }

        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $responses = Http::pool(fn ($pool) => array_map(
            fn (array $message) => $pool
                ->withToken($accessToken)
                ->timeout(self::TIMEOUT_SECONDS)
                ->post($url, ['message' => $this->envelope($message)]),
            $messages,
        ));

        return array_map($this->interpret(...), $responses);
    }

    /**
     * doc 11 — a captain's offer has to break through a silent phone, and every
     * other push must not. That is one Android channel id and one APNs
     * interruption level, not a louder title.
     *
     * `data` is all strings because FCM rejects a data payload with any other
     * scalar type in it, and a 400 on one message is indistinguishable at the
     * call site from a dead token.
     *
     * @param  array{token: string, title: string, body: string, data?: array<string, string>, channel?: string}  $message
     * @return array<string, mixed>
     */
    private function envelope(array $message): array
    {
        $isUrgent = ($message['channel'] ?? 'default') === 'offers';
        $tag = $message['data']['order_id'] ?? $message['data']['errand_id'] ?? null;

        /*
         * **The icon is named here, not left to the manifest.**
         *
         * `com.google.firebase.messaging.default_notification_icon` is read only when
         * the message omits `icon`, and only by the FCM SDK. The app draws its own
         * notification while it is in the foreground and takes the icon from
         * `AndroidInitializationSettings` instead — two code paths, two sources of
         * truth. A mismatch does not raise anything: Android masks a status-bar icon
         * to its alpha channel, so an opaque bitmap comes out as a solid **white
         * square** in the shade. Naming it makes the system-drawn notification agree
         * with the app-drawn one by construction.
         */
        $android = array_filter([
            'channel_id' => $isUrgent ? 'cr7_offers' : 'cr7_default',
            'sound' => $isUrgent ? 'offer' : 'default',
            'icon' => 'ic_notification',
            'color' => self::BRAND_COLOR,
            'notification_priority' => $isUrgent ? 'PRIORITY_MAX' : 'PRIORITY_DEFAULT',
            'default_vibrate_timings' => true,
            'default_light_settings' => true,
            // Shown in full on a locked screen. Nothing in this application's copy is
            // a secret, and a notification that cannot be read without unlocking is
            // one nobody acts on.
            'visibility' => 'PUBLIC',
            // A second push about the same order replaces the first rather than
            // stacking a column of them.
            'tag' => $tag,
            // Without it the tap opens the launcher with no payload and the deep link
            // is lost — the app routes on `data`, and this intent action is what
            // carries it through the system-drawn path.
            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
        ], fn ($value) => $value !== null);

        $aps = array_filter([
            'sound' => $isUrgent ? 'offer.caf' : 'default',
            'interruption-level' => $isUrgent ? 'time-sensitive' : 'active',
            'thread-id' => $tag,
            'mutable-content' => 1,
        ], fn ($value) => $value !== null);

        // FCM v1 validates the whole envelope and an explicit `null` is not always the
        // same to it as an absent field. Both blocks are filtered above, so a message
        // with no order id is a message with no `tag` rather than a 400 the call site
        // cannot tell apart from a dead token.
        return [
            'token' => $message['token'],
            'notification' => [
                'title' => $message['title'],
                'body' => $message['body'],
            ],
            'data' => array_map(
                fn ($value) => (string) $value,
                $message['data'] ?? [],
            ),
            'android' => [
                'priority' => $isUrgent ? 'HIGH' : 'NORMAL',
                'notification' => $android,
            ],
            'apns' => [
                'headers' => [
                    'apns-priority' => '10',
                    'apns-push-type' => 'alert',
                ],
                'payload' => ['aps' => $aps],
            ],
        ];
    }

    /**
     * @return array{ok: bool, error: ?string, unregistered: bool}
     */
    private function interpret(mixed $response): array
    {
        if ($response instanceof Throwable) {
            return ['ok' => false, 'error' => $this->trim($response->getMessage()), 'unregistered' => false];
        }

        if ($response->successful()) {
            return ['ok' => true, 'error' => null, 'unregistered' => false];
        }

        $status = $response->json('error.status');
        $message = $response->json('error.message') ?? 'http_'.$response->status();

        // The two ways FCM says «this device is gone»: the app was uninstalled,
        // or the token was rotated. Both mean the row should be deleted rather
        // than retried forever — see NotificationDispatcher.
        $unregistered = in_array($status, ['NOT_FOUND', 'UNREGISTERED'], true)
            || ($status === 'INVALID_ARGUMENT' && str_contains((string) $message, 'registration token'));

        return ['ok' => false, 'error' => $this->trim((string) $message), 'unregistered' => $unregistered];
    }

    private function trim(string $error): string
    {
        // `notification_deliveries.error` is a string column.
        return mb_substr($error, 0, 250);
    }

    private function accessToken(): ?string
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_string($cached)) {
            return $cached;
        }

        $token = $this->requestAccessToken();
        if ($token !== null) {
            Cache::put(self::CACHE_KEY, $token, self::TOKEN_TTL_SECONDS);
        }

        return $token;
    }

    private function requestAccessToken(): ?string
    {
        $credentials = $this->credentials();
        if ($credentials === null) {
            return null;
        }

        $jwt = $this->signedAssertion($credentials);
        if ($jwt === null) {
            return null;
        }

        try {
            $response = Http::asForm()
                ->timeout(self::TIMEOUT_SECONDS)
                ->post($credentials['token_uri'], [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);
        } catch (Throwable $e) {
            Log::warning('fcm token request failed', ['error' => $e->getMessage()]);

            return null;
        }

        $token = $response->json('access_token');
        if (! is_string($token)) {
            Log::warning('fcm token request rejected', [
                'status' => $response->status(),
                'error' => $response->json('error_description') ?? $response->json('error'),
            ]);

            return null;
        }

        return $token;
    }

    /** @param array<string, string> $credentials */
    private function signedAssertion(array $credentials): ?string
    {
        $now = time();

        $segments = [
            $this->base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)),
            $this->base64url(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => $credentials['token_uri'],
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR)),
        ];

        $input = implode('.', $segments);
        $signature = '';

        if (! openssl_sign($input, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            Log::warning('fcm assertion could not be signed — is the private key intact?');

            return null;
        }

        return $input.'.'.$this->base64url($signature);
    }

    private function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** @return array<string, string>|null */
    private function credentials(): ?array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        $path = config('services.firebase.credentials');
        if (! is_string($path) || ! is_readable($path)) {
            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            Log::warning('firebase credentials file is not valid JSON', ['path' => $path]);

            return null;
        }

        foreach (['client_email', 'private_key', 'token_uri', 'project_id'] as $key) {
            if (! isset($decoded[$key]) || ! is_string($decoded[$key])) {
                Log::warning('firebase credentials file is missing a field', ['field' => $key]);

                return null;
            }
        }

        return $this->credentials = $decoded;
    }
}
