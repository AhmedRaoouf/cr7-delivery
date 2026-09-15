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
use App\Models\StaticPage;
use App\Models\User;
use App\Observers\AuditableObserver;
use App\Observers\OrderObserver;
use App\Observers\ProductObserver;
use App\Services\Maps\ChainGeocoder;
use App\Services\Maps\Geocoder;
use App\Services\Maps\LocalGazetteerGeocoder;
use App\Services\Maps\MapboxGeocoder;
use App\Services\Maps\MapboxRouter;
use App\Services\Maps\NullGeocoder;
use App\Services\Maps\NullRouter;
use App\Services\Maps\OsmGeocoder;
use App\Services\Maps\Router;
use App\Services\Otp\LogOtpChannel;
use App\Services\Otp\OtpChannel;
use App\Services\Otp\SmsMisrChannel;
use App\Support\ApiResponse;
use App\Support\Auth\Sessions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Events\TokenAuthenticated;
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
        $this->app->singleton(Geocoder::class, function (): Geocoder {
            $chain = array_values(array_filter([
                new LocalGazetteerGeocoder,
                filled(config('maps.mapbox.token')) ? new MapboxGeocoder : null,
                new OsmGeocoder,
            ], fn (?Geocoder $g) => $g?->isConfigured() === true));

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
         * D-120 — the session.
         *
         * A legacy token (no `expires_at`) lives while it is used rather than for ever;
         * Sanctum's own verdict is passed in and can only be narrowed. And an access
         * token's first request is copied onto its refresh row, which is how a refresh
         * token presented twice is told apart from a response that never arrived — see
         * {@see Sessions}, where both live so the rules are read in one place.
         */
        Sanctum::authenticateAccessTokensUsing([Sessions::class, 'accepts']);
        Event::listen(TokenAuthenticated::class, [Sessions::class, 'noteFirstUse']);

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
