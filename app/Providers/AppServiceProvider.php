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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
