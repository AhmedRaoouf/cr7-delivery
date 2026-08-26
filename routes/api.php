<?php

use App\Http\Controllers\Api\Admin\CityController;
use App\Http\Controllers\Api\Admin\DriverController;
use App\Http\Controllers\Api\Admin\RestaurantController;
use App\Http\Controllers\Api\Admin\ServiceZoneController;
use App\Http\Controllers\Api\Auth\CustomerAuthController;
use App\Http\Controllers\Api\Auth\PasswordAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Customer — phone + OTP (FR-AUTH-01/02/03), no password ever.
    Route::prefix('auth')->group(function () {
        Route::post('/send-otp', [CustomerAuthController::class, 'sendOtp']);
        Route::post('/verify-otp', [CustomerAuthController::class, 'verifyOtp']);
        Route::post('/logout', [CustomerAuthController::class, 'logout'])->middleware('auth:sanctum');
        Route::post('/logout-all', [CustomerAuthController::class, 'logoutAll'])->middleware('auth:sanctum');
    });

    // Driver / Restaurant / Admin — Admin-created accounts, phone|email + password (FR-AUTH-04).
    Route::prefix('driver/auth')->group(function () {
        Route::post('/login', [PasswordAuthController::class, 'loginDriver']);
        Route::post('/logout', [PasswordAuthController::class, 'logout'])->middleware('auth:sanctum');
    });

    Route::prefix('restaurant/auth')->group(function () {
        Route::post('/login', [PasswordAuthController::class, 'loginRestaurant']);
        Route::post('/logout', [PasswordAuthController::class, 'logout'])->middleware('auth:sanctum');
    });

    Route::prefix('admin/auth')->group(function () {
        Route::post('/login', [PasswordAuthController::class, 'loginAdmin']);
        Route::post('/logout', [PasswordAuthController::class, 'logout'])->middleware('auth:sanctum');
    });

    // Admin dashboard — doc 12 §7: every route is auth:sanctum + a permission check.
    Route::prefix('admin')->middleware('auth:sanctum')->group(function () {
        Route::get('/cities', [CityController::class, 'index'])->middleware('permission:locations.view');
        Route::post('/cities', [CityController::class, 'store']);

        Route::get('/zones', [ServiceZoneController::class, 'index'])->middleware('permission:locations.view');
        Route::post('/zones', [ServiceZoneController::class, 'store']);

        Route::get('/restaurants', [RestaurantController::class, 'index'])->middleware('permission:restaurants.view');
        Route::post('/restaurants', [RestaurantController::class, 'store']);

        Route::get('/drivers', [DriverController::class, 'index'])->middleware('permission:drivers.view');
        Route::post('/drivers', [DriverController::class, 'store']);
    });
});
