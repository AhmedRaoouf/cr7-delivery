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
use App\Http\Controllers\Api\Customer\AddressController;
use App\Http\Controllers\Api\Customer\CartController;
use App\Http\Controllers\Api\Customer\CheckoutController;
use App\Http\Controllers\Api\Customer\ComplaintController as CustomerComplaintController;
use App\Http\Controllers\Api\Customer\FavoriteController;
use App\Http\Controllers\Api\Customer\OrderController;
use App\Http\Controllers\Api\Customer\PaymentController;
use App\Http\Controllers\Api\Customer\ProductController as CustomerProductController;
use App\Http\Controllers\Api\Customer\PromoController;
use App\Http\Controllers\Api\Customer\RestaurantController as CustomerRestaurantController;
use App\Http\Controllers\Api\Customer\SearchController;
use App\Http\Controllers\Api\Driver\DocumentController as DriverOwnDocumentController;
use App\Http\Controllers\Api\Driver\DriverMeController;
use App\Http\Controllers\Api\Driver\EarningsController as DriverEarningsController;
use App\Http\Controllers\Api\Driver\OfferController as DriverOfferController;
use App\Http\Controllers\Api\Driver\OrderController as DriverOrderController;
use App\Http\Controllers\Api\Restaurant\CategoryController as RestaurantCategoryController;
use App\Http\Controllers\Api\Restaurant\ComplaintController as RestaurantComplaintController;
use App\Http\Controllers\Api\Restaurant\OrderController as RestaurantOrderController;
use App\Http\Controllers\Api\Restaurant\ProductController as RestaurantProductController;
use App\Http\Controllers\Api\Restaurant\ProductOptionController as RestaurantProductOptionController;
use App\Http\Controllers\Api\Restaurant\ProductOptionGroupController as RestaurantProductOptionGroupController;
use App\Http\Controllers\Api\Restaurant\RatingController as RestaurantRatingController;
use App\Http\Controllers\Api\Restaurant\ReportController as RestaurantReportController;
use App\Http\Controllers\Api\Restaurant\RestaurantMeController;
use App\Http\Controllers\Api\Restaurant\SettlementController as RestaurantSettlementController;
use App\Http\Controllers\Api\Shared\BannerController;
use App\Http\Controllers\Api\Shared\CityController as SharedCityController;
use App\Http\Controllers\Api\Shared\ConfigController;
use App\Http\Controllers\Api\Shared\CuisineController as SharedCuisineController;
use App\Http\Controllers\Api\Shared\DeviceController;
use App\Http\Controllers\Api\Shared\FaqController;
use App\Http\Controllers\Api\Shared\GeoController;
use App\Http\Controllers\Api\Shared\NotificationController;
use App\Http\Controllers\Api\Shared\PageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| doc 08 — API Contract. Section numbers below mirror the doc exactly.
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // §2 Public / shared — no auth.
    Route::get('/config', [ConfigController::class, 'index']);
    Route::get('/cities', [SharedCityController::class, 'index']);
    Route::get('/cities/{city}/zones', [SharedCityController::class, 'zones']);
    Route::post('/geo/resolve', [GeoController::class, 'resolve']);
    Route::get('/cuisines', [SharedCuisineController::class, 'index']);
    Route::get('/banners', [BannerController::class, 'index']);
    Route::get('/pages/{slug}', [PageController::class, 'show']);
    Route::get('/faqs', [FaqController::class, 'index']);
    Route::middleware(['auth:sanctum', 'client.type'])->group(function () {
        Route::post('/devices', [DeviceController::class, 'store']);
        Route::delete('/devices', [DeviceController::class, 'destroy']);
    });

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

        Route::middleware(['auth:sanctum', 'client.type'])->group(function () {
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
    | §4 Customer — auth:sanctum + type:customer. Every row is scoped to the
    | caller (doc 12 §5): user_id = auth.id, enforced inside the controller.
    |----------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'client.type', 'type:customer'])->group(function () {
        Route::get('/addresses', [AddressController::class, 'index']);
        Route::post('/addresses', [AddressController::class, 'store']);
        Route::patch('/addresses/{address}', [AddressController::class, 'update']);
        Route::delete('/addresses/{address}', [AddressController::class, 'destroy']);
        Route::post('/addresses/{address}/default', [AddressController::class, 'makeDefault']);

        Route::get('/favorites', [FavoriteController::class, 'index']);
        Route::post('/favorites', [FavoriteController::class, 'store']);
        Route::delete('/favorites', [FavoriteController::class, 'destroy']);

        Route::get('/cart', [CartController::class, 'index']);
        Route::post('/cart/items', [CartController::class, 'addItem']);
        Route::patch('/cart/items/{item}', [CartController::class, 'updateItem']);
        Route::delete('/cart/items/{item}', [CartController::class, 'removeItem']);
        Route::delete('/cart/restaurants/{restaurant}', [CartController::class, 'removeRestaurant']);
        Route::delete('/cart', [CartController::class, 'clear']);
        Route::post('/cart/validate', [CartController::class, 'validateCart']);

        Route::post('/checkout/quote', [CheckoutController::class, 'quote']);
        Route::post('/promo/validate', [PromoController::class, 'validate']);

        Route::post('/orders', [OrderController::class, 'store']);
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{orderGroup}', [OrderController::class, 'show']);
        Route::get('/orders/{orderGroup}/tracking', [OrderController::class, 'tracking']);
        Route::get('/orders/{orderGroup}/confirmation', [OrderController::class, 'confirmation']);
        Route::post('/orders/{orderGroup}/confirm', [OrderController::class, 'confirm']);
        Route::post('/orders/{orderGroup}/delivery-code/resend', [OrderController::class, 'resendDeliveryCode']);
        Route::post('/orders/{orderGroup}/cancel', [OrderController::class, 'cancel']);
        Route::post('/orders/{orderGroup}/reorder', [OrderController::class, 'reorder']);
        Route::post('/orders/{orderGroup}/ratings', [OrderController::class, 'ratings']);

        Route::get('/orders/{orderGroup}/payment', [PaymentController::class, 'show']);
        Route::post('/orders/{orderGroup}/payment/proof', [PaymentController::class, 'submitProof']);
        Route::post('/orders/{orderGroup}/payment/method', [PaymentController::class, 'switchToCash']);

        Route::get('/complaints', [CustomerComplaintController::class, 'index']);
        Route::post('/complaints', [CustomerComplaintController::class, 'store']);
        Route::get('/complaints/{complaint}', [CustomerComplaintController::class, 'show']);
        Route::post('/complaints/{complaint}/messages', [CustomerComplaintController::class, 'addMessage']);
    });

    Route::middleware(['auth:sanctum', 'client.type'])->group(function () {
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    });

    // Catalog browsing — public within the customer app, no auth required (matches doc 08 §4 grouping).
    Route::get('/restaurants', [CustomerRestaurantController::class, 'index']);
    Route::get('/restaurants/{restaurant}', [CustomerRestaurantController::class, 'show']);
    Route::get('/restaurants/{restaurant}/categories', [CustomerRestaurantController::class, 'categories']);
    Route::get('/restaurants/{restaurant}/products', [CustomerRestaurantController::class, 'products']);
    Route::get('/restaurants/{restaurant}/best-sellers', [CustomerRestaurantController::class, 'bestSellers']);
    Route::get('/restaurants/{restaurant}/reviews', [CustomerRestaurantController::class, 'reviews']);
    Route::get('/products/{product}', [CustomerProductController::class, 'show']);
    Route::get('/search', [SearchController::class, 'index']);

    /*
    |----------------------------------------------------------------------
    | §6 Restaurant — auth:sanctum + type:restaurant. Every row is scoped to
    | the caller's own restaurant (doc 12 §5 / §8): a restaurant only ever
    | sees its own slice of a group, never a sibling sub-order.
    |----------------------------------------------------------------------
    */
    Route::prefix('restaurant')->middleware(['auth:sanctum', 'client.type', 'type:restaurant'])->group(function () {
        Route::get('/me', [RestaurantMeController::class, 'show']);
        Route::patch('/me', [RestaurantMeController::class, 'update']);
        Route::post('/open-status', [RestaurantMeController::class, 'openStatus']);
        Route::get('/opening-hours', [RestaurantMeController::class, 'openingHours']);
        Route::put('/opening-hours', [RestaurantMeController::class, 'updateOpeningHours']);

        Route::get('/orders', [RestaurantOrderController::class, 'index']);
        Route::get('/orders/new-count', [RestaurantOrderController::class, 'newCount']);
        Route::get('/orders/{order}', [RestaurantOrderController::class, 'show']);
        Route::post('/orders/{order}/accept', [RestaurantOrderController::class, 'accept']);
        Route::post('/orders/{order}/reject', [RestaurantOrderController::class, 'reject']);
        Route::patch('/orders/{order}/order-ref', [RestaurantOrderController::class, 'updateOrderRef']);
        Route::post('/orders/{order}/start-preparing', [RestaurantOrderController::class, 'startPreparing']);
        Route::patch('/orders/{order}/prep-time', [RestaurantOrderController::class, 'extendPrepTime']);
        Route::post('/orders/{order}/ready', [RestaurantOrderController::class, 'ready']);
        Route::get('/orders/{order}/pickup-otp', [RestaurantOrderController::class, 'pickupOtp']);

        Route::get('/categories', [RestaurantCategoryController::class, 'index']);
        Route::post('/categories', [RestaurantCategoryController::class, 'store']);
        Route::patch('/categories/{category}', [RestaurantCategoryController::class, 'update']);
        Route::delete('/categories/{category}', [RestaurantCategoryController::class, 'destroy']);

        Route::get('/products', [RestaurantProductController::class, 'index']);
        Route::post('/products', [RestaurantProductController::class, 'store']);
        Route::get('/products/{product}', [RestaurantProductController::class, 'show']);
        Route::patch('/products/{product}', [RestaurantProductController::class, 'update']);
        Route::delete('/products/{product}', [RestaurantProductController::class, 'destroy']);
        Route::post('/products/{product}/availability', [RestaurantProductController::class, 'availability']);
        Route::post('/products/bulk-availability', [RestaurantProductController::class, 'bulkAvailability']);

        Route::get('/products/{product}/option-groups', [RestaurantProductOptionGroupController::class, 'index']);
        Route::post('/products/{product}/option-groups', [RestaurantProductOptionGroupController::class, 'store']);
        Route::patch('/products/{product}/option-groups/{optionGroup}', [RestaurantProductOptionGroupController::class, 'update']);
        Route::delete('/products/{product}/option-groups/{optionGroup}', [RestaurantProductOptionGroupController::class, 'destroy']);

        Route::get('/option-groups/{optionGroup}/options', [RestaurantProductOptionController::class, 'index']);
        Route::post('/option-groups/{optionGroup}/options', [RestaurantProductOptionController::class, 'store']);
        Route::patch('/option-groups/{optionGroup}/options/{option}', [RestaurantProductOptionController::class, 'update']);
        Route::delete('/option-groups/{optionGroup}/options/{option}', [RestaurantProductOptionController::class, 'destroy']);

        Route::get('/reports/summary', [RestaurantReportController::class, 'summary']);
        Route::get('/reports/sales', [RestaurantReportController::class, 'sales']);
        Route::get('/reports/top-products', [RestaurantReportController::class, 'topProducts']);

        Route::get('/settlements', [RestaurantSettlementController::class, 'index']);
        Route::get('/settlements/{settlement}', [RestaurantSettlementController::class, 'show']);

        Route::get('/ratings', [RestaurantRatingController::class, 'index']);
        Route::get('/notifications', [NotificationController::class, 'index']);

        Route::get('/complaints', [RestaurantComplaintController::class, 'index']);
        Route::post('/complaints', [RestaurantComplaintController::class, 'store']);
        Route::get('/complaints/unread-count', [RestaurantComplaintController::class, 'unreadCount']);
        Route::get('/complaints/{complaint}', [RestaurantComplaintController::class, 'show']);
        Route::post('/complaints/{complaint}/messages', [RestaurantComplaintController::class, 'addMessage']);
    });

    /*
    |----------------------------------------------------------------------
    | §5 Driver — auth:sanctum + type:driver. The database decides who gets
    | an order (doc 05 §4), never the app.
    |----------------------------------------------------------------------
    */
    Route::prefix('driver')->middleware(['auth:sanctum', 'client.type', 'type:driver'])->group(function () {
        Route::get('/me', [DriverMeController::class, 'show']);
        Route::post('/status', [DriverMeController::class, 'status']);
        Route::post('/location', [DriverMeController::class, 'location']);

        Route::get('/offers', [DriverOfferController::class, 'index']);
        Route::get('/offers/{orderId}', [DriverOfferController::class, 'show']);

        Route::post('/orders/{order}/accept', [DriverOrderController::class, 'accept']);
        Route::post('/orders/{order}/reject', [DriverOrderController::class, 'reject']);
        Route::get('/orders/active', [DriverOrderController::class, 'active']);
        Route::post('/orders/{order}/arrived-restaurant', [DriverOrderController::class, 'arrivedRestaurant']);
        Route::post('/orders/{order}/waiting', [DriverOrderController::class, 'waiting']);
        Route::post('/orders/{order}/pickup', [DriverOrderController::class, 'pickup']);
        Route::post('/orders/{order}/on-the-way', [DriverOrderController::class, 'onTheWay']);
        Route::post('/orders/{order}/arrived-customer', [DriverOrderController::class, 'arrivedCustomer']);
        Route::post('/orders/{order}/deliver', [DriverOrderController::class, 'deliver']);
        Route::post('/orders/{order}/cancel', [DriverOrderController::class, 'cancel']);
        Route::post('/orders/{order}/issue', [DriverOrderController::class, 'issue']);
        Route::get('/orders', [DriverOrderController::class, 'history']);

        Route::get('/earnings', [DriverEarningsController::class, 'earnings']);
        Route::get('/settlements', [DriverEarningsController::class, 'settlements']);
        Route::get('/stats', [DriverEarningsController::class, 'stats']);

        Route::get('/documents', [DriverOwnDocumentController::class, 'index']);
        Route::post('/documents', [DriverOwnDocumentController::class, 'store']);
    });

    /*
    |----------------------------------------------------------------------
    | §7 Admin — every route is auth:sanctum + a permission check (doc 12 §7.1).
    | Geographic scope (doc 12 §10 / doc 24 §1.2) is enforced inside each
    | controller via App\Support\AdminScope — out-of-scope resolves as 404.
    |----------------------------------------------------------------------
    */
    Route::prefix('admin')->middleware(['auth:sanctum', 'client.type', 'type:admin'])->group(function () {

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
