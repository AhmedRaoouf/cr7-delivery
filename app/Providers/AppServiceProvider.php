<?php

namespace App\Providers;

use App\Models\Driver;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Otp\LogOtpChannel;
use App\Services\Otp\OtpChannel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OtpChannel::class, LogOtpChannel::class);
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
    }
}
