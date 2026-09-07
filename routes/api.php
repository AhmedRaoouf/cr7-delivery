<?php

use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\BannerController as AdminBannerController;
use App\Http\Controllers\Api\Admin\CaptainApplicationController as AdminCaptainApplicationController;
use App\Http\Controllers\Api\Admin\CityController;
use App\Http\Controllers\Api\Admin\ComplaintController as AdminComplaintController;
use App\Http\Controllers\Api\Admin\CuisineController;
use App\Http\Controllers\Api\Admin\CustomerController as AdminCustomerController;
use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\DeliveryIssueController as AdminDeliveryIssueController;
use App\Http\Controllers\Api\Admin\DeliveryPricingRuleController;
use App\Http\Controllers\Api\Admin\DirectOrderController as AdminDirectOrderController;
use App\Http\Controllers\Api\Admin\DriverController;
use App\Http\Controllers\Api\Admin\DriverDocumentController;
use App\Http\Controllers\Api\Admin\DriverFinanceController;
use App\Http\Controllers\Api\Admin\ErrandController as AdminErrandController;
use App\Http\Controllers\Api\Admin\FaqController as AdminFaqController;
use App\Http\Controllers\Api\Admin\MenuCategoryController;
use App\Http\Controllers\Api\Admin\NotificationBroadcastController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\Admin\OrderGroupController as AdminOrderGroupController;
use App\Http\Controllers\Api\Admin\PageController as AdminPageController;
use App\Http\Controllers\Api\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\Admin\PermissionController as AdminPermissionController;
use App\Http\Controllers\Api\Admin\ProductController;
use App\Http\Controllers\Api\Admin\ProductOptionController;
use App\Http\Controllers\Api\Admin\ProductOptionGroupController;
use App\Http\Controllers\Api\Admin\PromoCodeController;
use App\Http\Controllers\Api\Admin\RefundController as AdminRefundController;
use App\Http\Controllers\Api\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Api\Admin\RestaurantController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\ServiceZoneController;
use App\Http\Controllers\Api\Admin\SettingController as AdminSettingController;
use App\Http\Controllers\Api\Admin\SettlementController as AdminSettlementController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\CustomerAuthController;
use App\Http\Controllers\Api\Auth\PasswordAuthController;
use App\Http\Controllers\Api\Customer\AddressController;
use App\Http\Controllers\Api\Customer\CartController;
use App\Http\Controllers\Api\Customer\CheckoutController;
use App\Http\Controllers\Api\Customer\ComplaintController as CustomerComplaintController;
use App\Http\Controllers\Api\Customer\ErrandController as CustomerErrandController;
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
use App\Http\Controllers\Api\Driver\ErrandController as DriverErrandController;
use App\Http\Controllers\Api\Driver\OfferController as DriverOfferController;
use App\Http\Controllers\Api\Driver\OrderController as DriverOrderController;
use App\Http\Controllers\Api\Restaurant\CategoryController as RestaurantCategoryController;
use App\Http\Controllers\Api\Restaurant\ComplaintController as RestaurantComplaintController;
use App\Http\Controllers\Api\Restaurant\DirectOrderController as RestaurantDirectOrderController;
use App\Http\Controllers\Api\Restaurant\OrderController as RestaurantOrderController;
use App\Http\Controllers\Api\Restaurant\ProductController as RestaurantProductController;
use App\Http\Controllers\Api\Restaurant\ProductOptionController as RestaurantProductOptionController;
use App\Http\Controllers\Api\Restaurant\ProductOptionGroupController as RestaurantProductOptionGroupController;
use App\Http\Controllers\Api\Restaurant\RatingController as RestaurantRatingController;
use App\Http\Controllers\Api\Restaurant\ReportController as RestaurantReportController;
use App\Http\Controllers\Api\Restaurant\RestaurantMeController;
use App\Http\Controllers\Api\Restaurant\SettlementController as RestaurantSettlementController;
use App\Http\Controllers\Api\Shared\BannerController;
use App\Http\Controllers\Api\Shared\CaptainApplicationController;
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
    // Geocoding proxies. Throttled because every request behind them is a
    // billed one at the provider, and an unthrottled public route is a free
    // way for anyone to spend the account's monthly quota.
    Route::middleware('throttle:60,1')->group(function () {
        Route::get('/geo/reverse', [GeoController::class, 'reverse']);
        Route::get('/geo/search', [GeoController::class, 'search']);
    });
    Route::get('/cuisines', [SharedCuisineController::class, 'index']);
    Route::get('/banners', [BannerController::class, 'index']);
    Route::get('/pages/{slug}', [PageController::class, 'show']);
    Route::get('/faqs', [FaqController::class, 'index']);
    // APP-03 — public by necessity: an applicant has no account yet, and this creates
    // an application rather than one (D-45).
    Route::post('/captain-applications', [CaptainApplicationController::class, 'store']);
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
            Route::get('/me/notification-settings', [AuthController::class, 'notificationSettings']);
            Route::patch('/me/notification-settings', [AuthController::class, 'updateNotificationSettings']);
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
        Route::get('/orders/{orderGroup}/payment/proofs/{proof}/file', [PaymentController::class, 'proofFile']);
        Route::post('/orders/{orderGroup}/payment/method', [PaymentController::class, 'switchToCash']);

    });

    /*
    |----------------------------------------------------------------------
    | §4b Complaints — the same box for both halves of the one app.
    |
    | These four used to sit inside §4, behind `type:customer`. `DRV-32` sends a
    | captain to «تذاكري» and «تذكرة جديدة» on the very same screens, so every
    | captain who opened their own tickets was answered 403 by a screen the app
    | had just offered them. `user_role` on the row keeps the two apart;
    | `client.type` still refuses the dashboard binaries.
    |----------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'client.type', 'type:customer,driver'])->group(function () {
        Route::get('/complaints', [CustomerComplaintController::class, 'index']);
        Route::post('/complaints', [CustomerComplaintController::class, 'store']);
        Route::get('/complaints/{complaint}', [CustomerComplaintController::class, 'show']);
        Route::post('/complaints/{complaint}/messages', [CustomerComplaintController::class, 'addMessage']);
    });

    /*
    |----------------------------------------------------------------------
    | §4c Errands — «اطلب كابتن», doc 40. Customer side.
    |
    | A point-to-point job with no restaurant in it: the customer names both ends
    | and the fee, and every available captain sees it at once. Behind
    | `type:customer` — a captain requesting an errand from their own app is not
    | a case that exists, and the captain's half lives in §5.
    |----------------------------------------------------------------------
    */
    Route::prefix('customer')->middleware(['auth:sanctum', 'client.type', 'type:customer'])->group(function () {
        Route::post('/errands/quote', [CustomerErrandController::class, 'quote']);
        Route::post('/errands', [CustomerErrandController::class, 'store']);
        Route::get('/errands', [CustomerErrandController::class, 'index']);
        Route::get('/errands/{errand}', [CustomerErrandController::class, 'show']);
        Route::post('/errands/{errand}/cancel', [CustomerErrandController::class, 'cancel']);
    });

    // Directions. Behind auth, unlike the two geocoding proxies beside it: every
    // screen that draws a route — the customer's tracking map, the captain's active
    // job, the operator's fleet board — is already signed in, and a public route
    // request is the most expensive thing an anonymous caller could spend the
    // Mapbox quota on. Throttled on top of that, and cached per 110 m in
    // `MapboxRouter`.
    Route::middleware(['auth:sanctum', 'client.type', 'throttle:120,1'])
        ->get('/geo/route', [GeoController::class, 'route']);

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
    // Before /products/{product}: restoring a saved basket is one request, not one
    // per line.
    Route::get('/products', [CustomerProductController::class, 'index']);
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
        Route::get('/settlements/{settlement}/export', [RestaurantSettlementController::class, 'export']);

        Route::get('/ratings', [RestaurantRatingController::class, 'index']);
        Route::get('/notifications', [NotificationController::class, 'index']);

        Route::get('/complaints', [RestaurantComplaintController::class, 'index']);
        Route::post('/complaints', [RestaurantComplaintController::class, 'store']);
        Route::get('/complaints/unread-count', [RestaurantComplaintController::class, 'unreadCount']);
        Route::get('/complaints/{complaint}', [RestaurantComplaintController::class, 'show']);
        Route::post('/complaints/{complaint}/messages', [RestaurantComplaintController::class, 'addMessage']);

        // §6.2 Direct orders — the restaurant buys a delivery, not a marketplace order.
        Route::post('/direct-orders/quote', [RestaurantDirectOrderController::class, 'quote']);
        Route::post('/direct-orders', [RestaurantDirectOrderController::class, 'store']);
        Route::get('/direct-orders', [RestaurantDirectOrderController::class, 'index']);
        Route::get('/direct-orders/{order}', [RestaurantDirectOrderController::class, 'show']);
        Route::post('/direct-orders/{order}/ready', [RestaurantDirectOrderController::class, 'ready']);
        Route::post('/direct-orders/{order}/cancel', [RestaurantDirectOrderController::class, 'cancel']);
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

        // doc 40 — «اطلب كابتن». `board` is open to every captain on shift; the rest
        // answer 404 unless the errand is theirs.
        Route::get('/errands/board', [DriverErrandController::class, 'board']);
        Route::get('/errands/mine', [DriverErrandController::class, 'mine']);
        Route::get('/errands/{errand}', [DriverErrandController::class, 'show']);
        Route::post('/errands/{errand}/accept', [DriverErrandController::class, 'accept']);
        Route::post('/errands/{errand}/picked-up', [DriverErrandController::class, 'pickedUp']);
        Route::post('/errands/{errand}/deliver', [DriverErrandController::class, 'deliver']);
        Route::post('/errands/{errand}/purchases', [DriverErrandController::class, 'purchases']);
        Route::post('/errands/{errand}/cancel', [DriverErrandController::class, 'cancel']);

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
        // DRV-28 opens one delivered trip directly instead of resolving it out of the
        // list it came from. Declared after /orders/active so that stays reachable.
        Route::get('/orders/{order}', [DriverOrderController::class, 'show']);

        Route::get('/earnings', [DriverEarningsController::class, 'earnings']);
        Route::get('/earnings/{order}', [DriverEarningsController::class, 'earning']);
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
            // Before `/drivers/{driver}`, or `finance` binds as a captain id and 404s.
            Route::get('/finance/drivers', [DriverFinanceController::class, 'index']);
            Route::get('/drivers/{driver}', [DriverController::class, 'show']);
            Route::get('/drivers/{driver}/ledger', [DriverFinanceController::class, 'ledger']);
        });
        // D-71 — the office received cash from a captain and/or paid one. Same permission
        // as a manual balance adjustment, because that is exactly what it is.
        Route::post('/drivers/{driver}/settle', [DriverFinanceController::class, 'settle'])->middleware('permission:drivers.adjust_balance');
        Route::post('/drivers', [DriverController::class, 'store']);
        Route::patch('/drivers/{driver}', [DriverController::class, 'update']);
        Route::post('/drivers/{driver}/status', [DriverController::class, 'status'])->middleware('permission:drivers.toggle_status');
        Route::post('/drivers/{driver}/earning-rule', [DriverController::class, 'earningRule'])->middleware('permission:drivers.set_earning_rule');
        Route::post('/drivers/{driver}/transactions', [DriverController::class, 'transactions'])->middleware('permission:drivers.adjust_balance');
        Route::post('/drivers/{driver}/reset-password', [DriverController::class, 'resetPassword'])->middleware('permission:drivers.reset_password');
        Route::delete('/drivers/{driver}', [DriverController::class, 'destroy'])->middleware('permission:drivers.update');

        // Captain applications (ADM-30..ADM-32) — approving one is what creates the
        // account; APP-03 never does (D-45).
        Route::middleware('permission:driver_applications.view')->group(function () {
            Route::get('/captain-applications', [AdminCaptainApplicationController::class, 'index']);
            Route::get('/captain-applications/{application}', [AdminCaptainApplicationController::class, 'show']);
            // doc 12 §5 — the only way to see an applicant's ID or licence. Scoped, so a
            // regional admin cannot read a card from a city they do not run.
            Route::get('/captain-applications/{application}/files/{kind}', [AdminCaptainApplicationController::class, 'file']);
        });
        Route::post('/captain-applications/{application}/review', [AdminCaptainApplicationController::class, 'review'])
            ->middleware('permission:driver_applications.review');

        Route::middleware('permission:drivers.review_documents')->group(function () {
            Route::get('/drivers/{driver}/documents', [DriverDocumentController::class, 'index']);
            Route::post('/drivers/{driver}/documents', [DriverDocumentController::class, 'store']);
            Route::post('/driver-documents/{driverDocument}/review', [DriverDocumentController::class, 'review']);
            Route::get('/driver-documents/{driverDocument}/file', [DriverDocumentController::class, 'file']);
        });

        // Marketing — cuisines
        Route::get('/cuisines', [CuisineController::class, 'index']);
        Route::middleware('permission:content.manage')->group(function () {
            Route::post('/cuisines', [CuisineController::class, 'store']);
            Route::patch('/cuisines/{cuisine}', [CuisineController::class, 'update']);
            Route::delete('/cuisines/{cuisine}', [CuisineController::class, 'destroy']);
        });

        // Orders — doc 12 §3, the intervention console (doc 24 §3)
        Route::middleware('permission:orders.view')->group(function () {
            Route::get('/orders', [AdminOrderController::class, 'index']);
            Route::get('/orders/{order}', [AdminOrderController::class, 'show']);
            Route::get('/orders/{order}/attempts', [AdminOrderController::class, 'attempts']);
            Route::get('/orders/{order}/eligible-drivers', [AdminOrderController::class, 'eligibleDrivers']);
            Route::get('/orders/{order}/notes', [AdminOrderController::class, 'notes']);
        });
        Route::post('/orders/{order}/cancel', [AdminOrderController::class, 'cancel'])->middleware('permission:orders.cancel');
        Route::post('/orders/{order}/reassign-driver', [AdminOrderController::class, 'reassignDriver'])->middleware('permission:orders.reassign_driver');
        Route::post('/orders/{order}/unassign-driver', [AdminOrderController::class, 'unassignDriver'])->middleware('permission:orders.unassign_driver');
        Route::post('/orders/{order}/redispatch', [AdminOrderController::class, 'redispatch'])->middleware('permission:orders.redispatch');
        Route::post('/orders/{order}/force-pickup', [AdminOrderController::class, 'forcePickup'])->middleware('permission:orders.force_pickup');
        Route::post('/orders/{order}/force-status', [AdminOrderController::class, 'forceStatus'])->middleware('permission:orders.force_status');
        Route::post('/orders/{order}/renotify-restaurant', [AdminOrderController::class, 'renotifyRestaurant'])->middleware('permission:orders.renotify');
        Route::post('/orders/{order}/extend-prep', [AdminOrderController::class, 'extendPrep'])->middleware('permission:orders.extend_prep');
        Route::post('/orders/{order}/notes', [AdminOrderController::class, 'addNote'])->middleware('permission:orders.note');

        // doc 40 — «اطلب كابتن». Reuses the order permissions rather than minting a
        // parallel set: an operator trusted to reassign a food delivery is the same
        // operator who assigns an unclaimed errand, and a second catalogue would only
        // create a way for the two to disagree.
        Route::middleware('permission:orders.view')->group(function () {
            Route::get('/errands', [AdminErrandController::class, 'index']);
            Route::get('/errands/{errand}', [AdminErrandController::class, 'show']);
            Route::get('/errands/{errand}/eligible-drivers', [AdminErrandController::class, 'eligibleDrivers']);
        });
        Route::post('/errands/{errand}/assign', [AdminErrandController::class, 'assign'])->middleware('permission:orders.reassign_driver');
        Route::post('/errands/{errand}/cancel', [AdminErrandController::class, 'cancel'])->middleware('permission:orders.cancel');

        // Money
        Route::middleware('permission:payments.view')->group(function () {
            Route::get('/payments', [AdminPaymentController::class, 'index']);
            Route::get('/payments/{payment}', [AdminPaymentController::class, 'show']);
            Route::get('/payment-proofs/{proof}/file', [AdminPaymentController::class, 'proofFile']);
        });
        Route::middleware('permission:payments.verify')->group(function () {
            Route::post('/payments/{payment}/approve', [AdminPaymentController::class, 'approve']);
            Route::post('/payments/{payment}/reject', [AdminPaymentController::class, 'reject']);
        });

        Route::get('/refunds', [AdminRefundController::class, 'index'])->middleware('permission:refunds.view');
        Route::post('/refunds', [AdminRefundController::class, 'store'])->middleware('permission:refunds.create');
        Route::post('/refunds/{refund}/process', [AdminRefundController::class, 'process'])->middleware('permission:refunds.process');

        Route::get('/settlements', [AdminSettlementController::class, 'index'])->middleware('permission:settlements.view');
        Route::get('/settlements/{settlement}', [AdminSettlementController::class, 'show'])->middleware('permission:settlements.view');
        Route::get('/settlements/{settlement}/export', [AdminSettlementController::class, 'export'])->middleware('permission:settlements.view');
        Route::post('/settlements/generate', [AdminSettlementController::class, 'generate'])->middleware('permission:settlements.generate');
        Route::post('/settlements/{settlement}/pay', [AdminSettlementController::class, 'pay'])->middleware('permission:settlements.pay');

        // Complaints
        Route::middleware('permission:complaints.view')->group(function () {
            Route::get('/complaints', [AdminComplaintController::class, 'index']);
            // `sla` before `{complaint}`: routes match in order, so the wildcard would
            // otherwise swallow the literal and answer the SLA board with a 404 for a
            // complaint whose id is the word "sla".
            Route::get('/complaints/sla', [AdminComplaintController::class, 'sla']);
            Route::get('/complaints/{complaint}', [AdminComplaintController::class, 'show']);
        });
        Route::post('/complaints/{complaint}/messages', [AdminComplaintController::class, 'addMessage'])->middleware('permission:complaints.reply');
        Route::patch('/complaints/{complaint}', [AdminComplaintController::class, 'update'])->middleware('permission:complaints.update');
        Route::post('/complaints/{complaint}/assign', [AdminComplaintController::class, 'assign'])->middleware('permission:complaints.assign');
        Route::post('/complaints/{complaint}/escalate', [AdminComplaintController::class, 'escalate'])->middleware('permission:complaints.update');

        // Marketing — promo codes, banners, pages, faqs
        Route::get('/promo-codes', [PromoCodeController::class, 'index'])->middleware('permission:promos.view');
        Route::get('/promo-codes/{promoCode}', [PromoCodeController::class, 'show'])->middleware('permission:promos.view');
        Route::get('/promo-codes/{promoCode}/usages', [PromoCodeController::class, 'usages'])->middleware('permission:promos.view');
        Route::post('/promo-codes', [PromoCodeController::class, 'store']);
        Route::patch('/promo-codes/{promoCode}', [PromoCodeController::class, 'update']);
        Route::delete('/promo-codes/{promoCode}', [PromoCodeController::class, 'destroy'])->middleware('permission:promos.manage');

        Route::get('/banners', [AdminBannerController::class, 'index']);
        Route::get('/banners/{banner}', [AdminBannerController::class, 'show']);
        Route::post('/banners', [AdminBannerController::class, 'store']);
        Route::patch('/banners/{banner}', [AdminBannerController::class, 'update']);
        Route::delete('/banners/{banner}', [AdminBannerController::class, 'destroy'])->middleware('permission:banners.manage');

        Route::get('/pages', [AdminPageController::class, 'index']);
        Route::get('/pages/{slug}', [AdminPageController::class, 'show']);
        Route::post('/pages', [AdminPageController::class, 'store']);
        Route::patch('/pages/{slug}', [AdminPageController::class, 'update']);
        Route::delete('/pages/{slug}', [AdminPageController::class, 'destroy'])->middleware('permission:content.manage');

        Route::get('/faqs', [AdminFaqController::class, 'index']);
        Route::post('/faqs', [AdminFaqController::class, 'store']);
        Route::patch('/faqs/{faq}', [AdminFaqController::class, 'update']);
        Route::delete('/faqs/{faq}', [AdminFaqController::class, 'destroy'])->middleware('permission:content.manage');

        Route::post('/notifications/broadcast', [NotificationBroadcastController::class, 'store'])->middleware('permission:notifications.broadcast');

        // System — users, roles, permissions, settings, audit log (doc 08 §7 note: global-scope admins only)
        Route::middleware('permission:users.view')->group(function () {
            Route::get('/users', [AdminUserController::class, 'index']);
            Route::get('/users/{user}', [AdminUserController::class, 'show']);
        });
        Route::middleware('permission:users.invite')->group(function () {
            Route::post('/users', [AdminUserController::class, 'store']);
        });
        Route::middleware('permission:users.manage')->group(function () {
            Route::patch('/users/{user}', [AdminUserController::class, 'update']);
            Route::post('/users/{user}/reset-password', [AdminUserController::class, 'resetPassword']);
            Route::delete('/users/{user}', [AdminUserController::class, 'destroy']);
        });
        Route::post('/users/{user}/suspend', [AdminUserController::class, 'suspend'])->middleware('permission:users.suspend');
        Route::post('/users/{user}/restore', [AdminUserController::class, 'restore'])->middleware('permission:users.suspend');
        Route::post('/users/{user}/roles', [AdminUserController::class, 'updateRoles'])->middleware('permission:roles.manage');
        Route::post('/users/{user}/permissions', [AdminUserController::class, 'updatePermissions'])->middleware('permission:roles.manage');
        Route::post('/users/{user}/scope', [AdminUserController::class, 'updateScope'])->middleware('permission:scope.manage');

        Route::middleware('permission:roles.manage')->group(function () {
            Route::get('/roles', [RoleController::class, 'index']);
            Route::get('/roles/{role}', [RoleController::class, 'show']);
            Route::patch('/roles/{role}', [RoleController::class, 'update']);
            Route::delete('/roles/{role}', [RoleController::class, 'destroy']);
            Route::post('/roles/{role}/clone', [RoleController::class, 'clone']);
        });
        Route::post('/roles', [RoleController::class, 'store'])->middleware('permission:roles.create');
        Route::get('/permissions', [AdminPermissionController::class, 'index'])->middleware('permission:roles.manage');

        Route::middleware('permission:settings.view')->group(function () {
            Route::get('/settings', [AdminSettingController::class, 'index']);
        });
        Route::patch('/settings', [AdminSettingController::class, 'update'])->middleware('permission:settings.manage');

        Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view');

        // Dashboard & alerts
        Route::get('/dashboard/summary', [AdminDashboardController::class, 'summary']);
        Route::get('/dashboard/charts', [AdminDashboardController::class, 'charts']);
        Route::get('/dashboard/alerts', [AdminDashboardController::class, 'alerts']);
        Route::get('/dashboard/live-orders', [AdminDashboardController::class, 'liveOrders']);
        Route::get('/dashboard/open-alerts', [AdminDashboardController::class, 'openAlerts']);
        Route::get('/dashboard/live-drivers', [AdminDashboardController::class, 'liveDrivers'])->middleware('permission:drivers.view_location');

        // Customers
        Route::middleware('permission:customers.view')->group(function () {
            Route::get('/customers', [AdminCustomerController::class, 'index']);
            Route::get('/customers/{customer}', [AdminCustomerController::class, 'show']);
            Route::get('/customers/{customer}/orders', [AdminCustomerController::class, 'orders']);
            Route::get('/customers/{customer}/addresses', [AdminCustomerController::class, 'addresses']);
            Route::get('/customers/{customer}/complaints', [AdminCustomerController::class, 'complaints']);
            Route::get('/customers/{customer}/stats', [AdminCustomerController::class, 'stats']);
        });
        Route::post('/customers/{customer}/status', [AdminCustomerController::class, 'status'])->middleware('permission:customers.toggle_status');

        // Direct orders (cross-restaurant) + order intervention leftovers
        Route::get('/direct-orders', [AdminDirectOrderController::class, 'index'])->middleware('permission:direct_orders.view_all');
        Route::get('/restaurants/{restaurant}/direct-order-ratio', [AdminDirectOrderController::class, 'ratio'])->middleware('permission:direct_orders.view');
        Route::post('/orders/{order}/issues', [AdminOrderController::class, 'raiseIssue'])->middleware('permission:orders.raise_issue');
        Route::post('/order-groups/{orderGroup}/recalculate', [AdminOrderGroupController::class, 'recalculate'])->middleware('permission:orders.recalculate_group');

        Route::middleware('permission:orders.resolve_issue')->group(function () {
            Route::get('/delivery-issues', [AdminDeliveryIssueController::class, 'index']);
            Route::post('/delivery-issues/{deliveryIssue}/resolve', [AdminDeliveryIssueController::class, 'resolve']);
        });

        // Reports
        Route::get('/reports/sales', [AdminReportController::class, 'sales'])->middleware('permission:reports.sales');
        Route::get('/reports/restaurants', [AdminReportController::class, 'restaurants'])->middleware('permission:reports.restaurants');
        Route::get('/reports/products', [AdminReportController::class, 'products'])->middleware('permission:reports.products');
        Route::get('/reports/drivers', [AdminReportController::class, 'drivers'])->middleware('permission:reports.drivers');
        Route::get('/reports/zones', [AdminReportController::class, 'zones'])->middleware('permission:reports.zones');
        Route::get('/reports/promo-codes', [AdminReportController::class, 'promoCodes'])->middleware('permission:promos.view');
        Route::get('/reports/refunds', [AdminReportController::class, 'refunds'])->middleware('permission:refunds.view');
        Route::get('/reports/complaints', [AdminReportController::class, 'complaints'])->middleware('permission:complaints.view');
        Route::get('/reports/customers', [AdminReportController::class, 'customers'])->middleware('permission:reports.customers');
        Route::get('/reports/customers/cohorts', [AdminReportController::class, 'customerCohorts'])->middleware('permission:reports.customers');
        Route::get('/ops/health', [AdminReportController::class, 'opsHealth'])->middleware('permission:ops.health');
    });
});
