<?php

namespace App\Providers;

use App\Models\Driver;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Maps\Geocoder;
use App\Services\Maps\MapboxGeocoder;
use App\Services\Maps\NullGeocoder;
use App\Services\Otp\LogOtpChannel;
use App\Services\Otp\OtpChannel;
use App\Services\Otp\SmsMisrChannel;
use App\Support\Otp;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

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
        \App\Models\Banner::class,
        \App\Models\City::class,
        \App\Models\Cuisine::class,
        \App\Models\DeliveryPricingRule::class,
        \App\Models\Driver::class,
        \App\Models\DriverDocument::class,
        \App\Models\Faq::class,
        \App\Models\MenuCategory::class,
        \App\Models\Product::class,
        \App\Models\ProductOption::class,
        \App\Models\ProductOptionGroup::class,
        \App\Models\PromoCode::class,
        \App\Models\Restaurant::class,
        \App\Models\Role::class,
        \App\Models\ServiceZone::class,
        \App\Models\StaticPage::class,
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
        $telescopeIsUsable = class_exists(\Laravel\Telescope\Telescope::class)
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

        // A token turns geocoding on; its absence turns it off. Nothing else
        // in the app has to know which of the two it is talking to.
        $this->app->singleton(Geocoder::class, fn () => filled(config('maps.mapbox.token'))
            ? new MapboxGeocoder()
            : new NullGeocoder());
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
        Product::observe(\App\Observers\ProductObserver::class);

        // Realtime. Every open board, map and tracking screen learns that an order
        // moved from here — see App\Observers\OrderObserver for why the model event
        // is the hook rather than the nineteen call sites that write `status`.
        Order::observe(\App\Observers\OrderObserver::class);

        // D-52 — «every admin action on a row writes an `audit_logs` row». Sixteen admin
        // controllers mutate these models and none of them said so; the model event is the
        // hook for the same reason it is above, and it covers the endpoint nobody has
        // written yet. Only an admin actor is recorded — see App\Observers\AuditableObserver.
        foreach (self::AUDITED as $model) {
            $model::observe(\App\Observers\AuditableObserver::class);
        }

        // Static OTP is correct on a staging server with no SMS gateway and is
        // a full account takeover on a public one. Say so on every boot rather
        // than letting it ride into production unnoticed.
        if (Otp::isStatic() && $this->app->environment('production')) {
            Log::warning('[OTP] STATIC MODE IS ON IN PRODUCTION — every phone signs in with the same code. Set OTP_STATIC_MODE=false.');
        }

        // And the other way round, which is the failure that looks like a
        // working server: static mode off while `LogOtpChannel` is still the
        // binding means every code is random and lands in storage/logs and
        // nowhere else. `POST /auth/otp/send` answers 200, the customer waits
        // for an SMS that was never sent, and nobody can sign in at all. Both
        // halves have to move together — bind a real channel, then flip the
        // switch.
        if (! Otp::isStatic() && $this->app->make(OtpChannel::class) instanceof LogOtpChannel) {
            Log::warning('[OTP] NOBODY CAN SIGN IN — static mode is off and the only OTP channel is the log. Bind a real SMS channel, or set OTP_STATIC_MODE=true while testing.');
        }
    }
}
