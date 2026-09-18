<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Telescope::night();

        $this->hideSensitiveRequestDetails();

        // The stock filter keeps everything on `local` and only failures elsewhere.
        // That is the wrong shape for an end-to-end test run against the deployed
        // API, where the point is watching the calls that *worked* as much as the
        // ones that did not. TELESCOPE_RECORD_ALL opens it, and `telescope:prune`
        // in routes/console.php is what keeps that from filling the database.
        $recordAll = (bool) config('telescope.record_all', $this->app->environment('local'));

        Telescope::filter(function (IncomingEntry $entry) use ($recordAll) {
            return $recordAll ||
                   $entry->isReportableException() ||
                   $entry->isFailedRequest() ||
                   $entry->isFailedJob() ||
                   $entry->isScheduledTask() ||
                   $entry->hasMonitoredTag();
        });

        $this->tagByClient();
    }

    /**
     * Three different clients share one API, and the whole point of watching it
     * is being able to say which one made a call. Doc 08 §1 already requires an
     * `X-App` header on every request — `app` for the Flutter binary (customer
     * and captain both, D-45), `restaurant` and `admin` for the two Next.js
     * dashboards — so the header is turned into a Telescope tag and the entry
     * list becomes filterable by `app:admin`, `status:422`, and so on.
     *
     * The dashboards call the API from their own server, never from the
     * browser (D-69), so the tag records the dashboard, not the operator's
     * machine — which is what we want.
     */
    protected function tagByClient(): void
    {
        Telescope::tag(fn (IncomingEntry $entry) => self::clientTags($entry));
    }

    /**
     * The tags themselves — a named static so a test can assert them without
     * booting Telescope's recorder.
     *
     * @return array<int, string>
     */
    public static function clientTags(IncomingEntry $entry): array
    {
        if ($entry->type !== 'request') {
            return [];
        }

        $headers = $entry->content['headers'] ?? [];
        $status = $entry->content['response_status'] ?? 0;

        $tags = [
            'app:'.($headers['x-app'] ?? 'unknown'),
            'status:'.$status,
        ];

        if ($status >= 400) {
            $tags[] = 'failed';
        }

        // The captain and the customer are one binary, so `X-App` cannot
        // separate them. `X-Role` can — the app sends it once a session
        // knows which shell it is in.
        if (isset($headers['x-role'])) {
            $tags[] = 'role:'.$headers['x-role'];
        }

        if (isset($headers['x-platform'])) {
            $tags[] = 'platform:'.$headers['x-platform'];
        }

        if (isset($entry->content['user']['id'])) {
            $tags[] = 'user:'.$entry->content['user']['id'];
        }

        return $tags;
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        // A bearer token is a live credential whether or not the environment is
        // local, and the whole reason this panel exists is that somebody is
        // reading it. Redact in every environment, not only production.
        Telescope::hideRequestHeaders([
            'authorization',
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);

        Telescope::hideRequestParameters([
            '_token',
            'password',
            'password_confirmation',
            'current_password',
            'otp',
            'code',
            'fcm_token',
            // Batch 67 — the session's other half and the registration proof are
            // credentials exactly like a password.
            'refresh_token',
            'registration_token',
            'token',
        ]);

        /*
         * Batch 67 — response bodies are recorded too, and every sign-in answers its
         * bearer token and refresh token in the body. Measured on production 2026-09-18:
         * 43 of 3 375 recorded requests held a live access token and refresh token in
         * plain text — Sanctum stores only their hashes precisely so that a read of the
         * database is not a read of everybody's session, and Telescope undid that for
         * anyone with the panel token, a database dump or a backup. The same goes for a
         * test-mode code, an office-issued temporary password and a pickup code.
         */
        Telescope::hideResponseParameters([
            'data.token',
            'data.access_token',
            'data.refresh_token',
            'data.registration_token',
            'data.session.token',
            'data.session.refresh_token',
            'data.static_otp',
            'data.temp_password',
            'data.pickup_otp',
            'data.delivery_otp',
        ]);
    }

    /**
     * The Telescope gate.
     *
     * It is deliberately closed. Access is decided by `AuthorizeTelescope`, which
     * `config/telescope.php` puts on the routes in place of Telescope's own
     * `Authorize` middleware — because this application has no web session for a
     * `Gate::check($user, …)` to read. Every client holds a Sanctum bearer token
     * against `api/*` and nothing signs in to the `web` guard, so the stock gate
     * denies the operator too. Leaving it shut means a future change that puts
     * `Authorize::class` back does not silently open the panel.
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', fn (?User $user) => false);
    }
}
