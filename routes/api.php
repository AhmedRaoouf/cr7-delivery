<?php

use App\Http\Controllers\Api\Admin\CityController;
use App\Http\Controllers\Api\Admin\CuisineController;
use App\Http\Controllers\Api\Admin\DeliveryPricingRuleController;
use App\Http\Controllers\Api\Admin\DriverController;
use App\Http\Controllers\Api\Admin\DriverDocumentController;
use App\Http\Controllers\Api\Admin\MenuCategoryController;
use App\Http\Controllers\Api\Admin\ProductController;
use App\Http\Controllers\Api\Admin\ProductOptionController;
use App\Http\Controllers\Api\Admin\ProductOptionGroupController;
use App\Http\Controllers\Api\Admin\RestaurantController;
use App\Http\Controllers\Api\Admin\ServiceZoneController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\CustomerAuthController;
use App\Http\Controllers\Api\Auth\PasswordAuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| doc 08 — API Contract. Section numbers below mirror the doc exactly.
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // §3 Auth
    Route::prefix('auth')->group(function () {
        // customer: phone + OTP (FR-AUTH-01/02/03), no password ever.
        Route::post('/otp/send', [CustomerAuthController::class, 'sendOtp']);
        Route::post('/otp/verify', [CustomerAuthController::class, 'verifyOtp']);

        // driver / restaurant / admin — Admin-created accounts, phone|email + password (FR-AUTH-04).
        // X-App decides the expected users.type (doc 12 §1.1).
        Route::post('/login', [PasswordAuthController::class, 'login']);

        Route::post('/password/forgot', [AuthController::class, 'forgotPassword']);
        Route::post('/password/reset', [AuthController::class, 'resetPassword']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::patch('/me', [AuthController::class, 'updateMe']);
            Route::post('/password/change', [AuthController::class, 'changePassword']);
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/logout-all', [AuthController::class, 'logoutAll']);
            Route::delete('/account', [AuthController::class, 'deleteAccount']);
        });
    });

    /*
    |----------------------------------------------------------------------
    | §7 Admin — every route is auth:sanctum + a permission check (doc 12 §7.1).
    | Geographic scope (doc 12 §10 / doc 24 §1.2) is enforced inside each
    | controller via App\Support\AdminScope — out-of-scope resolves as 404.
    |----------------------------------------------------------------------
    */
    Route::prefix('admin')->middleware('auth:sanctum')->group(function () {

        // Locations & pricing
        Route::middleware('permission:locations.view')->group(function () {
            Route::get('/cities', [CityController::class, 'index']);
            Route::get('/cities/{city}', [CityController::class, 'show']);
            Route::get('/zones', [ServiceZoneController::class, 'index']);
            Route::get('/zones/{zone}', [ServiceZoneController::class, 'show']);
            Route::get('/pricing-rules', [DeliveryPricingRuleController::class, 'index']);
            Route::get('/pricing-rules/{pricingRule}', [DeliveryPricingRuleController::class, 'show']);
        });
        Route::post('/cities', [CityController::class, 'store']);
        Route::patch('/cities/{city}', [CityController::class, 'update']);
        Route::middleware('permission:locations.manage_cities')->group(function () {
            Route::post('/cities/{city}/pricing-type', [CityController::class, 'pricingType']);
            Route::delete('/cities/{city}', [CityController::class, 'destroy']);
        });
        Route::post('/zones', [ServiceZoneController::class, 'store']);
        Route::patch('/zones/{zone}', [ServiceZoneController::class, 'update']);
        Route::delete('/zones/{zone}', [ServiceZoneController::class, 'destroy'])->middleware('permission:locations.manage_zones');
        Route::post('/pricing-rules', [DeliveryPricingRuleController::class, 'store']);
        Route::patch('/pricing-rules/{pricingRule}', [DeliveryPricingRuleController::class, 'update']);
        Route::middleware('permission:locations.manage_pricing')->group(function () {
            Route::delete('/pricing-rules/{pricingRule}', [DeliveryPricingRuleController::class, 'destroy']);
            Route::post('/pricing-rules/preview', [DeliveryPricingRuleController::class, 'preview']);
        });

        // Restaurants / catalog
        Route::middleware('permission:restaurants.view')->group(function () {
            Route::get('/restaurants', [RestaurantController::class, 'index']);
            Route::get('/restaurants/{restaurant}', [RestaurantController::class, 'show']);
        });
        Route::post('/restaurants', [RestaurantController::class, 'store']);
        Route::patch('/restaurants/{restaurant}', [RestaurantController::class, 'update']);
        Route::post('/restaurants/{restaurant}/status', [RestaurantController::class, 'status'])->middleware('permission:restaurants.toggle_status');
        Route::post('/restaurants/{restaurant}/commission', [RestaurantController::class, 'commission'])->middleware('permission:restaurants.set_commission');
        Route::post('/restaurants/{restaurant}/zones', [RestaurantController::class, 'zones'])->middleware('permission:restaurants.update');
        Route::post('/restaurants/{restaurant}/reset-password', [RestaurantController::class, 'resetPassword'])->middleware('permission:restaurants.reset_password');
        Route::post('/restaurants/{restaurant}/direct-orders', [RestaurantController::class, 'directOrders'])->middleware('permission:restaurants.toggle_direct_orders');
        Route::delete('/restaurants/{restaurant}', [RestaurantController::class, 'destroy'])->middleware('permission:restaurants.update');

        Route::middleware('permission:restaurants.manage_menu')->group(function () {
            Route::get('/restaurants/{restaurant}/categories', [MenuCategoryController::class, 'index']);
            Route::post('/restaurants/{restaurant}/categories', [MenuCategoryController::class, 'store']);
            Route::patch('/restaurants/{restaurant}/categories/{menuCategory}', [MenuCategoryController::class, 'update']);
            Route::delete('/restaurants/{restaurant}/categories/{menuCategory}', [MenuCategoryController::class, 'destroy']);
        });

        Route::middleware('permission:products.view')->group(function () {
            Route::get('/restaurants/{restaurant}/products', [ProductController::class, 'index']);
            Route::get('/restaurants/{restaurant}/products/{product}', [ProductController::class, 'show']);
        });
        Route::post('/restaurants/{restaurant}/products', [ProductController::class, 'store'])->middleware('permission:products.create');
        Route::patch('/restaurants/{restaurant}/products/{product}', [ProductController::class, 'update'])->middleware('permission:products.update');
        Route::post('/restaurants/{restaurant}/products/{product}/availability', [ProductController::class, 'toggleAvailability'])->middleware('permission:products.toggle_availability');
        Route::delete('/restaurants/{restaurant}/products/{product}', [ProductController::class, 'destroy'])->middleware('permission:products.delete');

        Route::middleware('permission:products.update')->group(function () {
            Route::get('/products/{product}/option-groups', [ProductOptionGroupController::class, 'index']);
            Route::post('/products/{product}/option-groups', [ProductOptionGroupController::class, 'store']);
            Route::patch('/products/{product}/option-groups/{optionGroup}', [ProductOptionGroupController::class, 'update']);
            Route::delete('/products/{product}/option-groups/{optionGroup}', [ProductOptionGroupController::class, 'destroy']);

            Route::post('/option-groups/{optionGroup}/options', [ProductOptionController::class, 'store']);
            Route::patch('/option-groups/{optionGroup}/options/{option}', [ProductOptionController::class, 'update']);
            Route::delete('/option-groups/{optionGroup}/options/{option}', [ProductOptionController::class, 'destroy']);
        });

        // Drivers
        Route::middleware('permission:drivers.view')->group(function () {
            Route::get('/drivers', [DriverController::class, 'index']);
            Route::get('/drivers/{driver}', [DriverController::class, 'show']);
        });
        Route::post('/drivers', [DriverController::class, 'store']);
        Route::patch('/drivers/{driver}', [DriverController::class, 'update']);
        Route::post('/drivers/{driver}/status', [DriverController::class, 'status'])->middleware('permission:drivers.toggle_status');
        Route::post('/drivers/{driver}/earning-rule', [DriverController::class, 'earningRule'])->middleware('permission:drivers.set_earning_rule');
        Route::post('/drivers/{driver}/transactions', [DriverController::class, 'transactions'])->middleware('permission:drivers.adjust_balance');
        Route::post('/drivers/{driver}/reset-password', [DriverController::class, 'resetPassword'])->middleware('permission:drivers.reset_password');
        Route::delete('/drivers/{driver}', [DriverController::class, 'destroy'])->middleware('permission:drivers.update');

        Route::middleware('permission:drivers.review_documents')->group(function () {
            Route::get('/drivers/{driver}/documents', [DriverDocumentController::class, 'index']);
            Route::post('/drivers/{driver}/documents', [DriverDocumentController::class, 'store']);
            Route::post('/driver-documents/{driverDocument}/review', [DriverDocumentController::class, 'review']);
        });

        // Marketing — cuisines
        Route::get('/cuisines', [CuisineController::class, 'index']);
        Route::middleware('permission:content.manage')->group(function () {
            Route::post('/cuisines', [CuisineController::class, 'store']);
            Route::patch('/cuisines/{cuisine}', [CuisineController::class, 'update']);
            Route::delete('/cuisines/{cuisine}', [CuisineController::class, 'destroy']);
        });
    });
});
