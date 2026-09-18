<?php

namespace App\Providers;

use App\Models\Banner;
use App\Models\City;
use App\Models\Cuisine;
use App\Models\DeliveryPricingRule;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\Faq;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\ServiceZone;
use App\Models\Setting;
use App\Models\StaticPage;
use App\Models\User;
use App\Observers\AuditableObserver;
use App\Observers\OrderObserver;
use App\Observers\ProductObserver;
use App\Observers\SettingObserver;
use App\Services\Maps\ChainGeocoder;
use App\Services\Maps\Geocoder;
use App\Services\Maps\LocalGazetteerGeocoder;
use App\Services\Maps\MapboxGeocoder;
use App\Services\Maps\MapboxRouter;
use App\Services\Maps\NullGeocoder;
use App\Services\Maps\NullRouter;
use App\Services\Maps\OsmGeocoder;
use App\Services\Maps\PlatformPlaceGeocoder;
use App\Services\Maps\Router;
use App\Services\Otp\LogOtpChannel;
use App\Services\Otp\OtpChannel;
use App\Services\Otp\SmsMisrChannel;
use App\Support\ApiResponse;
use App\Support\Auth\Sessions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Laravel\Telescope\Telescope;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Every route parameter in `routes/api.php` that names a database id.
     *
     * `slug` is the only wildcard left unconstrained, because it is the only one that is
     * not a number.
     */
    private const NUMERIC_ROUTE_PARAMS = [
        'address', 'application', 'banner', 'category', 'city', 'complaint', 'cuisine',
        'customer', 'deliveryIssue', 'driver', 'driverDocument', 'faq', 'item',
        'menuCategory', 'notification', 'option', 'optionGroup', 'order', 'orderGroup',
        'orderId', 'payment', 'pricingRule', 'product', 'promoCode', 'proof', 'refund',
        'restaurant', 'role', 'settlement', 'user', 'zone',
    ];

    /**
     * The models an admin can reach through the admin API, D-52's «whose rows».
     *
     * `User` is deliberately absent: `UserController` already records its own rows, and a
     * second observer entry would double every one of them. `Order`, `Complaint`,
     * `Settlement`, `Refund` and `Payment` are absent for the same reason — their admin
     * actions are verbs («force this status», «reassign this captain») whose meaning is
     * lost if they are logged as a column diff, so they stay with `AuditLogger::record`.
     */
    private const AUDITED = [
        Banner::class,
        City::class,
        Cuisine::class,
        DeliveryPricingRule::class,
        Driver::class,
        DriverDocument::class,
        Faq::class,
        MenuCategory::class,
        Product::class,
        ProductOption::class,
        ProductOptionGroup::class,
        PromoCode::class,
        Restaurant::class,
        Role::class,
        ServiceZone::class,
        StaticPage::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope is registered by hand rather than by package discovery
        // (`dont-discover` in composer.json), so the decision is one readable
        // `if` rather than a side effect of which packages happen to be
        // installed. Three conditions, all of which must hold:
        //
        //   * the class is present — `composer install --no-dev` on a host that
        //     does not want it must not fatal on a missing provider;
        //   * `telescope.enabled` — TELESCOPE_ENABLED=false turns it off on a
        //     live deployment without a redeploy;
        //   * a token is set outside `local` — Telescope is a full read of every
        //     request body the API receives, and `AuthorizeTelescope` answers 404
        //     without one, so booting it would only cost memory.
        $telescopeIsUsable = class_exists(Telescope::class)
            && config('telescope.enabled') === true
            && ($this->app->environment('local') || config('telescope.auth_token') !== '');

        if ($telescopeIsUsable) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }

        // Flip with OTP_CHANNEL=sms_misr in .env once the account is live — no code change needed.
        $this->app->bind(OtpChannel::class, match (config('services.otp_channel')) {
            'sms_misr' => SmsMisrChannel::class,
            default => LogOtpChannel::class,
        });

        // D-84 — Mapbox first when there is a token, OpenStreetMap's own
        // Nominatim behind it always, and silence only if the operator has
        // turned that off too. Nothing else in the app has to know which one
        // answered.
        //
        // The fallback is a chain rather than a choice because a token is a
        // preference, not a dependency: a paid provider that is down, throttled
        // or refusing the query must not take geocoding down with it while a
        // keyless one sits configured and unasked. See {@see ChainGeocoder}.
        // The local address book comes **first**, and that ordering is the fix
        // for «مش دايمًا ألاقي جمصة الشاطئ».
        //
        // Measured on production 2026-09-12: Mapbox answers a street for every
        // pin in Gamasa and `neighborhood: null` for every one of them. The
        // chain stops at the first non-empty line, so that street-only answer
        // won, the screen had no area to print, and it fell back to the
        // **delivery zone** — of which the only circle covering the town is
        // «الشاطئ». The beach is two rows of buildings; the app was naming the
        // whole town after it.
        //
        // Nominatim answers «منطقة آمون» for the same point. It sat second,
        // configured, and was never asked. Swapping the two would only move the
        // gap to whatever Nominatim is thin on, so neither ordering is the
        // answer: for the launch city we hold better data than either API
        // exposes, and {@see LocalGazetteerGeocoder} returns null the moment a
        // pin leaves its bbox.
        //
        // D-127 — the platform's own shops come before all of it. `GET
        // /geo/places` has published them by name since 2026-09-07 and had zero
        // callers in any repo, so a customer typing «سندوتش عمر» was asking
        // Mapbox about a Gamasa sandwich shop. No tile provider will ever answer
        // that for this town; these are our own rows. See
        // {@see PlatformPlaceGeocoder}.
        $this->app->singleton(Geocoder::class, function (): Geocoder {
            // `configured()` rather than `isConfigured()`: the local tables answer
            // it with a query, and a query that throws here would be a ‪500‬ on
            // every geocoding route before any source had been asked anything.
            $chain = array_values(array_filter([
                new PlatformPlaceGeocoder,
                new LocalGazetteerGeocoder,
                filled(config('maps.mapbox.token')) ? new MapboxGeocoder : null,
                new OsmGeocoder,
            ], fn (?Geocoder $g) => $g !== null && ChainGeocoder::configured($g)));

            return $chain === [] ? new NullGeocoder : new ChainGeocoder($chain);
        });

        // The same token, and the same rule: configured means real roads, absent
        // means the caller draws the straight line it always drew.
        $this->app->singleton(Router::class, fn () => filled(config('maps.mapbox.token'))
            ? new MapboxRouter
            : new NullRouter);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Every route id is a number, and a request that says otherwise is a 404.
         *
         * `GET /driver/offers/current` returned **500** on the live API. There is no such
         * route; `/driver/offers/{orderId}` matched, `current` was handed to an action
         * typed `int $orderId`, and PHP raised a TypeError. Model-bound parameters were
         * already safe — implicit binding looks the key up and answers 404 — but the three
         * that resolve by hand (`orderId`, `application`, `item`) had nothing in front of
         * them, so a typo in a client reached the controller and came back as a server
         * error. A 500 is a page an operator escalates; a 404 is one they read.
         *
         * Declared here rather than as `->whereNumber()` on each line so a route added
         * later inherits it instead of having to remember. `slug` is deliberately absent
         * — `/pages/{slug}` is the one parameter that is meant to be words.
         */
        foreach (self::NUMERIC_ROUTE_PARAMS as $parameter) {
            Route::pattern($parameter, '[0-9]+');
        }

        // Short, stable morph type strings instead of fragile FQCNs (doc 03 §8: "restaurant|product", "restaurant|driver").
        Relation::enforceMorphMap([
            'restaurant' => Restaurant::class,
            'product' => Product::class,
            'driver' => Driver::class,
            'order' => Order::class,
            'user' => User::class,
        ]);

        // D-60 — restaurants.price_band is derived from the menu, so it is recomputed
        // whenever a row that feeds the median moves.
        Product::observe(ProductObserver::class);

        // Realtime. Every open board, map and tracking screen learns that an order
        // moved from here — see App\Observers\OrderObserver for why the model event
        // is the hook rather than the nineteen call sites that write `status`.
        Order::observe(OrderObserver::class);

        // batch 65 — «مش بتغير». A settings row that changes by any path at all clears
        // its own `rememberForever` memo; the model event is the hook for the same reason
        // it is above, and it covers the seeder, `tinker` and the controller nobody has
        // written yet. See App\Observers\SettingObserver.
        Setting::observe(SettingObserver::class);

        // D-52 — «every admin action on a row writes an `audit_logs` row». Sixteen admin
        // controllers mutate these models and none of them said so; the model event is the
        // hook for the same reason it is above, and it covers the endpoint nobody has
        // written yet. Only an admin actor is recorded — see App\Observers\AuditableObserver.
        foreach (self::AUDITED as $model) {
            $model::observe(AuditableObserver::class);
        }

        /*
         * D-106 — a per-IP ceiling on every route that sends an SMS, beside the per-phone
         * one in `SendOtpAction`. A gateway that costs money per message is a gateway
         * somebody can spend: the per-phone cap alone lets one caller walk a list of
         * numbers at five messages each. One bucket for all of them — `otp/send`,
         * `password/forgot`, `password/change/send-code` and `addresses/phone/send-code`
         * — so a caller cannot get twenty from each.
         *
         * The static-mode warnings that used to sit here are gone with `OTP_STATIC_MODE`.
         * `otp.mode` is a setting, and reading the database on every boot would also run
         * during `migrate` on a database that has no settings table yet; the same two
         * warnings are logged by `SendOtpAction` on the send they concern.
         */
        RateLimiter::for('otp-send', fn (Request $request) => Limit::perHour(20)
            ->by((string) $request->ip())
            ->response(fn (Request $request, array $headers) => ApiResponse::error(
                'otp_send_rate_limited',
                'محاولات كثيرة جدًا، حاول لاحقًا',
                429,
                meta: ['retry_after' => (int) ($headers['Retry-After'] ?? 3600)],
            )->withHeaders($headers)));

        /*
         * D-127 — one bucket per geocoding route, not one between them.
         *
         * `/geo/reverse`, `/geo/search` and `/geo/expand-link` shared a single
         * `throttle:60,1` keyed by IP. The address picker's ‪700‬ ms reverse debounce can
         * emit ~‪85‬ reverse lookups a minute on its own, so a customer who dragged the
         * pin spent the whole allowance and the **search** box then answered ‪429‬ —
         * shown on the screen as «لا توجد نتائج». The street was under the pin.
         *
         * Two buckets each, and both must pass. The per-token one is the real limit and
         * is sized for what one screen can produce; the per-IP one is a ceiling on
         * abuse and is deliberately wide, because Egyptian mobile networks put whole
         * towns behind one address and a tight per-IP bucket is an outage for all of
         * them. An unauthenticated caller — `CUS-04` runs before anyone has signed in —
         * has only the IP bucket, so it cannot be tight enough to bite a real customer.
         *
         * Every one of them refuses in the house envelope with its own error code. A
         * ‪429‬ the client cannot recognise is a ‪429‬ the client shows as «no results».
         */
        $refusedSearch = fn (string $code) => fn (Request $request, array $headers) => ApiResponse::error(
            $code,
            'البحث سريع جدًا، انتظر لحظة',
            429,
            meta: ['retry_after' => (int) ($headers['Retry-After'] ?? 60)],
        )->withHeaders($headers);

        // Keyed on the bearer token itself, not on `$request->user()`: these routes
        // carry no `auth:sanctum`, so there is no resolved user to key on, and looking
        // one up per request would put a token query in front of every map pan. A
        // forged token only buys a new per-caller bucket; the per-IP ceiling still holds.
        $byCaller = static function (Request $request, string $bucket): string {
            $token = (string) $request->bearerToken();

            return $bucket.':'.($token === '' ? 'ip:'.$request->ip() : 'token:'.hash('sha256', $token));
        };

        RateLimiter::for('geo-reverse', function (Request $request) use ($refusedSearch, $byCaller) {
            $refused = $refusedSearch('geo_reverse_rate_limited');

            return [
                Limit::perMinute(240)->by($byCaller($request, 'geo-rev'))->response($refused),
                Limit::perMinute(900)->by('geo-rev-ip:'.$request->ip())->response($refused),
            ];
        });

        RateLimiter::for('geo-search', function (Request $request) use ($refusedSearch, $byCaller) {
            $refused = $refusedSearch('geo_search_rate_limited');

            // A person types; they do not type ninety distinct queries a minute. The
            // client debounces on top of this, and every answer is cached server-side.
            return [
                Limit::perMinute(90)->by($byCaller($request, 'geo-search'))->response($refused),
                Limit::perMinute(400)->by('geo-search-ip:'.$request->ip())->response($refused),
            ];
        });

        RateLimiter::for('geo-expand', function (Request $request) use ($byCaller) {
            $refused = fn (Request $request, array $headers) => ApiResponse::error(
                'geo_expand_rate_limited',
                'محاولات كثيرة جدًا، حاول لاحقًا',
                429,
                meta: ['retry_after' => (int) ($headers['Retry-After'] ?? 60)],
            )->withHeaders($headers);

            // Pasting a link is a deliberate act, and this is the only route that makes
            // the server fetch a URL somebody else chose.
            return [
                Limit::perMinute(20)->by($byCaller($request, 'geo-expand'))->response($refused),
                Limit::perMinute(60)->by('geo-expand-ip:'.$request->ip())->response($refused),
            ];
        });

        /*
         * Batch 67 — the one password endpoint every captain, place and admin signs in
         * through had no throttle at all.
         *
         * Three buckets, and all must pass:
         *
         *   * this number from this address — 5 a minute. A person mistyping gets five
         *     tries; a script gets five guesses a minute.
         *   * this number from anywhere — 30 an hour, so a password list spread over many
         *     addresses still cannot grind one account. The cost is that someone who
         *     knows a phone number can make that account wait out the hour; a lockout is
         *     recoverable, a guessed admin password is not.
         *   * this address — 60 a minute. Deliberately wide: both dashboards sign every
         *     place and every operator in from their BFF's one server address.
         *
         * The number is normalised the way `LoginRequest` normalises it, or `+20 10…`
         * and `010…` would be two buckets for one account.
         */
        $loginRefused = fn (Request $request, array $headers) => ApiResponse::error(
            'login_rate_limited',
            'محاولات دخول كثيرة جدًا، حاول بعد قليل',
            429,
            meta: ['retry_after' => (int) ($headers['Retry-After'] ?? 60)],
        )->withHeaders($headers);

        $loginKey = static function (Request $request): string {
            $login = $request->input('login');

            return hash('sha256', is_string($login) ? \App\Support\PhoneNumber::normaliseLogin($login) : '');
        };

        RateLimiter::for('auth-login', fn (Request $request) => [
            Limit::perMinute(5)->by('login:'.$loginKey($request).'|'.$request->ip())->response($loginRefused),
            Limit::perHour(30)->by('login-account:'.$loginKey($request))->response($loginRefused),
            Limit::perMinute(60)->by('login-ip:'.$request->ip())->response($loginRefused),
        ]);

        /*
         * Batch 67 — `/auth/otp/verify` and `/auth/password/reset` carry a six-digit code.
         * `otp.max_attempts` caps guesses per issued code; this caps the endpoint itself
         * per number and per address, so a code cannot be guessed at network speed across
         * re-sends, and the difference between «wrong» and «expired» cannot be polled.
         */
        RateLimiter::for('otp-verify', function (Request $request) {
            $refused = fn (Request $request, array $headers) => ApiResponse::error(
                'otp_verify_rate_limited',
                'محاولات كثيرة جدًا، حاول لاحقًا',
                429,
                meta: ['retry_after' => (int) ($headers['Retry-After'] ?? 60)],
            )->withHeaders($headers);

            $phone = $request->input('phone', $request->input('login'));
            $phone = is_string($phone) ? \App\Support\PhoneNumber::normaliseLogin($phone) : '';

            return [
                Limit::perMinute(10)->by('otp-verify:'.hash('sha256', $phone).'|'.$request->ip())->response($refused),
                Limit::perHour(40)->by('otp-verify-phone:'.hash('sha256', $phone))->response($refused),
                Limit::perMinute(60)->by('otp-verify-ip:'.$request->ip())->response($refused),
            ];
        });

        // Batch 67 — the current password is checked on every change; a stolen session
        // must not be able to grind it out. Keyed on the account.
        RateLimiter::for('password-change', fn (Request $request) => Limit::perMinute(6)
            ->by('password-change:'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
            ->response(fn (Request $request, array $headers) => ApiResponse::error(
                'rate_limited',
                'محاولات كثيرة جدًا، حاول بعد قليل',
                429,
                meta: ['retry_after' => (int) ($headers['Retry-After'] ?? 60)],
            )->withHeaders($headers)));

        // Batch 67 — the public captain application (doc 61 retired the screen): a
        // handful an hour per address, three a day per phone number.
        RateLimiter::for('captain-application', function (Request $request) {
            $phone = $request->input('phone');
            $phone = is_string($phone) ? \App\Support\PhoneNumber::normaliseLogin($phone) : '';

            return [
                Limit::perHour(5)->by('captain-application-ip:'.$request->ip()),
                Limit::perDay(3)->by('captain-application-phone:'.hash('sha256', $phone)),
            ];
        });

        /*
         * D-120 — the session. A legacy token (no `expires_at`) lives while it is used
         * rather than for ever; Sanctum's own verdict is passed in and can only be
         * narrowed. The rule lives in {@see Sessions::accepts()}.
         */
        Sanctum::authenticateAccessTokensUsing([Sessions::class, 'accepts']);

        /*
         * `POST /auth/refresh` is public, so it is throttled — but not per IP alone. Both
         * dashboards reach the API from their BFF's one server address, so a per-IP ceiling
         * of ‪30‬ would be a ceiling on every place and every operator together, and the
         * place whose renewal is refused is signed out. So: ‪30‬ a minute for one token from
         * one address, which is far beyond anything a client racing itself sends, and ‪300‬
         * a minute for the address as a whole, which still stops a sprayer.
         */
        //
        // Refused in the envelope with its own code, never `session_expired`: a client that
        // is told to wait must wait and try again, not sign the person out.
        RateLimiter::for('auth-refresh', function (Request $request) {
            $token = $request->input('refresh_token');
            $token = is_string($token) ? $token : '';

            $refused = fn (Request $request, array $headers) => ApiResponse::error(
                'refresh_rate_limited',
                'محاولات كثيرة جدًا، حاول لاحقًا',
                429,
                meta: ['retry_after' => (int) ($headers['Retry-After'] ?? 60)],
            )->withHeaders($headers);

            return [
                Limit::perMinute(30)->by('refresh-token:'.$request->ip().'|'.hash('sha256', $token))->response($refused),
                Limit::perMinute(300)->by('refresh-ip:'.$request->ip())->response($refused),
            ];
        });

        // What cannot work at all with the log as the channel, whatever the panel says:
        // an admin's reset code and every password-change code are real SMS by rule
        // (D-107). Config only — no database at boot.
        if ($this->app->environment('production') && config('services.otp_channel') !== 'sms_misr') {
            Log::warning('[OTP] OTP_CHANNEL is the log in production — no SMS leaves this server, and an admin cannot change a password by SMS. Use `php artisan cr7:reset-password` until SMS Misr is bound.');
        }
    }
}
