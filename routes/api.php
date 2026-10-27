<?php

use App\Http\Controllers\Api\Admin\AccountModerationController;
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
use App\Http\Controllers\Api\Admin\HomePopupController as AdminHomePopupController;
use App\Http\Controllers\Api\Admin\LookupController;
use App\Http\Controllers\Api\Admin\MediaController;
use App\Http\Controllers\Api\Admin\MediaTagController;
use App\Http\Controllers\Api\Admin\MenuCategoryController;
use App\Http\Controllers\Api\Admin\NotificationBroadcastController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\Admin\OrderGroupController as AdminOrderGroupController;
use App\Http\Controllers\Api\Admin\OtpController as AdminOtpController;
use App\Http\Controllers\Api\Admin\PageController as AdminPageController;
use App\Http\Controllers\Api\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\Admin\PermissionController as AdminPermissionController;
use App\Http\Controllers\Api\Admin\PlaceGroupController;
use App\Http\Controllers\Api\Admin\PlaceOrderController;
use App\Http\Controllers\Api\Admin\ProductController;
use App\Http\Controllers\Api\Admin\ProductGalleryController;
use App\Http\Controllers\Api\Admin\ProductOptionController;
use App\Http\Controllers\Api\Admin\ProductOptionGroupController;
use App\Http\Controllers\Api\Admin\PromoCodeController;
use App\Http\Controllers\Api\Admin\RefundController as AdminRefundController;
use App\Http\Controllers\Api\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Api\Admin\ReportExportController as AdminReportExportController;
use App\Http\Controllers\Api\Admin\RestaurantController;
use App\Http\Controllers\Api\Admin\RestaurantFinanceController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\ScheduledOrderController as AdminScheduledOrderController;
use App\Http\Controllers\Api\Admin\ServiceZoneController;
use App\Http\Controllers\Api\Admin\SettingController as AdminSettingController;
use App\Http\Controllers\Api\Admin\SettlementController as AdminSettlementController;
use App\Http\Controllers\Api\Admin\StoreCategoryController as AdminStoreCategoryController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\CustomerAuthController;
use App\Http\Controllers\Api\Auth\PasswordAuthController;
use App\Http\Controllers\Api\Auth\SessionController;
use App\Http\Controllers\Api\Customer\AddressController;
use App\Http\Controllers\Api\Customer\AddressPhoneController;
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
use App\Http\Controllers\Api\Customer\ScheduledOrderController as CustomerScheduledOrderController;
use App\Http\Controllers\Api\Customer\SearchController;
use App\Http\Controllers\Api\Driver\DocumentController as DriverOwnDocumentController;
use App\Http\Controllers\Api\Driver\DriverMeController;
use App\Http\Controllers\Api\Driver\EarningsController as DriverEarningsController;
use App\Http\Controllers\Api\Driver\ErrandController as DriverErrandController;
use App\Http\Controllers\Api\Driver\OfferController as DriverOfferController;
use App\Http\Controllers\Api\Driver\OrderController as DriverOrderController;
use App\Http\Controllers\Api\Driver\TripController as DriverTripController;
use App\Http\Controllers\Api\Restaurant\CategoryController as RestaurantCategoryController;
use App\Http\Controllers\Api\Restaurant\ComplaintController as RestaurantComplaintController;
use App\Http\Controllers\Api\Restaurant\DirectOrderController as RestaurantDirectOrderController;
use App\Http\Controllers\Api\Restaurant\MediaPickerController as RestaurantMediaPickerController;
use App\Http\Controllers\Api\Restaurant\OrderController as RestaurantOrderController;
use App\Http\Controllers\Api\Restaurant\ProductController as RestaurantProductController;
use App\Http\Controllers\Api\Restaurant\ProductGalleryController as RestaurantProductGalleryController;
use App\Http\Controllers\Api\Restaurant\ProductOptionController as RestaurantProductOptionController;
use App\Http\Controllers\Api\Restaurant\ProductOptionGroupController as RestaurantProductOptionGroupController;
use App\Http\Controllers\Api\Restaurant\RatingController as RestaurantRatingController;
use App\Http\Controllers\Api\Restaurant\ReportController as RestaurantReportController;
use App\Http\Controllers\Api\Restaurant\RestaurantMeController;
use App\Http\Controllers\Api\Restaurant\ScheduledOrderController as RestaurantScheduledOrderController;
use App\Http\Controllers\Api\Restaurant\SettlementController as RestaurantSettlementController;
use App\Http\Controllers\Api\Shared\BannerController;
use App\Http\Controllers\Api\Shared\CaptainApplicationController;
use App\Http\Controllers\Api\Shared\CityController as SharedCityController;
use App\Http\Controllers\Api\Shared\ConfigController;
use App\Http\Controllers\Api\Shared\CuisineController as SharedCuisineController;
use App\Http\Controllers\Api\Restaurant\CuisineController as RestaurantCuisineController;
use App\Http\Controllers\Api\Shared\DeviceController;
use App\Http\Controllers\Api\Shared\FaqController;
use App\Http\Controllers\Api\Shared\GeoController;
use App\Http\Controllers\Api\Shared\HomePopupController as SharedHomePopupController;
use App\Http\Controllers\Api\Shared\NotificationController;
use App\Http\Controllers\Api\Shared\PageController;
use App\Http\Controllers\Api\Shared\StoreCategoryController as SharedStoreCategoryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| doc 08 — API Contract. Section numbers below mirror the doc exactly.
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // §2 Public / shared — no auth.
    // Batch 68 — `http.cache:{group},{ttl},{max-age}[,viewer]` on the reads the customer
    // home opens on: stored server-side per version, `ETag` + `304`, `Cache-Control`
    // (ADMIN-LISTS.md §5, `CachePublicResponse`). Every signed-in group below carries
    // `throttle.account`, one bucket per account sized by its type (§6).
    Route::get('/config', [ConfigController::class, 'index'])->middleware('http.cache:config,60,30,viewer');
    // Every priced area, flat, in one call. This is what the app reads now:
    // the two below are the city-first pair it replaced, kept because older
    // installs are still asking for them (operator ruling 2026-09-12 — «هي
    // مناطق على طول»). The old pair also cost one request **per city**.
    Route::get('/areas', [GeoController::class, 'areas'])->middleware('http.cache:catalog,300,60');
    Route::get('/cities', [SharedCityController::class, 'index'])->middleware('http.cache:catalog,300,60');
    Route::get('/cities/{city}/zones', [SharedCityController::class, 'zones'])->middleware('http.cache:catalog,300,60');
    Route::post('/geo/resolve', [GeoController::class, 'resolve'])->middleware('throttle:120,1');
    // Our own shops, by name, near a point — what the raster tiles under them will
    // never know. Public for the same reason `/store-categories` is: the app browses
    // before it signs in, and this is the same catalogue narrowed to a pin and a name.
    // Throttled generously — it is one indexed query behind a ten-minute cache.
    Route::middleware('throttle:120,1')->get('/geo/places', [GeoController::class, 'places']);
    /*
     * Geocoding proxies. Throttled because every request behind them is a billed
     * one at the provider, and an unthrottled public route is a free way for
     * anyone to spend the account's monthly quota.
     *
     * D-127 — **one bucket each**, not one bucket between them. All three shared
     * a single `throttle:60,1` keyed by IP, and the address picker's ‪700‬ ms
     * reverse debounce alone can spend ~‪85‬ of those sixty in a minute: the
     * customer dragged the pin, the reverse lookups took the whole allowance,
     * and the search box then answered ‪429‬ — which the client showed as «لا
     * توجد نتائج», so the screen said the street did not exist while the street
     * was under the pin. Every customer behind one carrier NAT shared that
     * bucket with every other. The limits are defined in `AppServiceProvider`,
     * each with its own error code so a ‪429‬ is never silent again.
     */
    Route::middleware('throttle:geo-reverse')->get('/geo/reverse', [GeoController::class, 'reverse']);
    Route::middleware('throttle:geo-search')->get('/geo/search', [GeoController::class, 'search']);
    // Follows a shortened maps link to the URL that carries the coordinates. Not a
    // provider call, and the tightest of the three: it is the one route here that
    // makes the server fetch a URL a user chose, and the host allowlist inside is
    // what keeps that from being an open proxy.
    Route::middleware('throttle:geo-expand')->get('/geo/expand-link', [GeoController::class, 'expandLink']);
    Route::get('/cuisines', [SharedCuisineController::class, 'index'])->middleware('http.cache:catalog,300,60');
    // The home screen's top strip. Public — the app browses before it signs in.
    Route::get('/store-categories', [SharedStoreCategoryController::class, 'index'])->middleware('http.cache:catalog,300,60');
    Route::get('/banners', [BannerController::class, 'index'])->middleware('http.cache:catalog,60,30,viewer');
    // «العرض المنبثق» — the card drawn over the home screen the moment it opens. Public
    // for the same reason the strip above is: it is shown before anyone signs in.
    Route::get('/home-popups', [SharedHomePopupController::class, 'index'])->middleware('http.cache:catalog,60,30,viewer');
    Route::get('/pages/{slug}', [PageController::class, 'show'])->middleware('http.cache:catalog,300,60');
    Route::get('/faqs', [FaqController::class, 'index'])->middleware('http.cache:catalog,300,60');
    // APP-03 — public by necessity: an applicant has no account yet, and this creates
    // an application rather than one (D-45).
    // Batch 67 — public, unauthenticated, and it stores up to three 5 MB files per
    // request on the private disk. The app stopped offering it (doc 61); throttled
    // hard rather than removed, for an old build still in somebody's pocket.
    Route::post('/captain-applications', [CaptainApplicationController::class, 'store'])->middleware('throttle:captain-application');
    Route::middleware(['auth:sanctum', 'client.type', 'account.active', 'password.fresh', 'throttle.account'])->group(function () {
        Route::post('/devices', [DeviceController::class, 'store']);
        Route::delete('/devices', [DeviceController::class, 'destroy']);
    });

    // §3 Auth
    Route::prefix('auth')->group(function () {
        // customer: phone + OTP (FR-AUTH-01/02/03), no password ever.
        // Batch 68 — no route throttle on any of the four routes that send a code: every
        // per-number, per-address, per-install and platform count is `OtpGuard`'s and
        // `OtpBudget`'s, counted from the `sms_sends` ledger **after** the phone was
        // validated — a mistyped number costs nobody any of their allowance, and a cache
        // clear hands nobody a fresh one.
        Route::post('/otp/send', [CustomerAuthController::class, 'sendOtp']);
        Route::post('/otp/verify', [CustomerAuthController::class, 'verifyOtp'])->middleware('throttle:otp-verify');
        // «إنشاء حساب» — the second half of registration: the token `otp/verify` handed
        // back, plus a name and a password. Throttled per IP because it is the one
        // unauthenticated route that creates a row in `users`.
        Route::post('/register', [CustomerAuthController::class, 'register'])->middleware('throttle:10,1');

        /*
         * «المتابعة باستخدام Apple / Google» is **gone**, operator ruling 2026-09-11:
         * «شيل تسجيل الدخول بالايميل و google و apple». The two routes that verified a
         * provider's id_token and attached the identity are deleted rather than hidden —
         * a door nobody can see is still a door. The `social_accounts` table and its
         * migration stay: dropping a table to remove a feature loses the record of who
         * ever used it, and nothing reads it now.
         */

        // driver / restaurant / admin — Admin-created accounts, **phone** + password
        // (FR-AUTH-04). No email for anyone: D-107's admin email was removed by the
        // operator on 2026-09-17. X-App decides the expected users.type (doc 12 §1.1).
        // Batch 67 — there was no throttle on the one password endpoint every staff
        // account signs in through. See the `auth-login` limiter.
        Route::post('/login', [PasswordAuthController::class, 'login'])->middleware('throttle:auth-login');

        Route::post('/password/forgot', [AuthController::class, 'forgotPassword']);
        Route::post('/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:otp-verify');

        // D-120 — renewing a session. Public: the access token it replaces has usually
        // expired by the time it is called, so `auth:sanctum` would refuse the one
        // request that can fix that. Throttled per token and per IP (`auth-refresh` in
        // `AppServiceProvider`) — per IP alone would throttle every dashboard user
        // together, since the BFF calls from one server address.
        Route::post('/refresh', [SessionController::class, 'refresh'])->middleware('throttle:auth-refresh');

        Route::middleware(['auth:sanctum', 'client.type', 'account.active', 'password.fresh'])->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::patch('/me', [AuthController::class, 'updateMe']);
            Route::get('/me/notification-settings', [AuthController::class, 'notificationSettings']);
            Route::patch('/me/notification-settings', [AuthController::class, 'updateNotificationSettings']);
            // D-107 — the SMS code an admin's password change needs. Real SMS, always.
            Route::post('/password/change/send-code', [AuthController::class, 'sendPasswordChangeCode']);
            Route::post('/password/change', [AuthController::class, 'changePassword'])->middleware('throttle:password-change');
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/logout-all', [AuthController::class, 'logoutAll']);
            // D-120 — a legacy token (no `expires_at`) exchanged for a session that renews.
            Route::post('/session/upgrade', [SessionController::class, 'upgrade']);
            Route::delete('/account', [AuthController::class, 'deleteAccount']);
        });
    });

    /*
    |----------------------------------------------------------------------
    | §4 Customer — auth:sanctum + type:customer. Every row is scoped to the
    | caller (doc 12 §5): user_id = auth.id, enforced inside the controller.
    |----------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'client.type', 'account.active', 'password.fresh', 'type:customer', 'throttle.account'])->group(function () {
        Route::get('/addresses', [AddressController::class, 'index']);
        Route::post('/addresses', [AddressController::class, 'store']);
        // D-112 — proving a second number for an address. Declared before the
        // `{address}` routes; they could not match `phone` anyway (ids are numeric).
        Route::post('/addresses/phone/send-code', [AddressPhoneController::class, 'sendCode']);
        // Batch 68 — it checks a six-digit code like the other four verify routes, and was
        // the one with no throttle in front of it.
        Route::post('/addresses/phone/verify', [AddressPhoneController::class, 'verify'])->middleware('throttle:otp-verify');
        Route::patch('/addresses/{address}', [AddressController::class, 'update']);
        Route::delete('/addresses/{address}', [AddressController::class, 'destroy']);
        Route::post('/addresses/{address}/default', [AddressController::class, 'makeDefault']);

        Route::get('/favorites', [FavoriteController::class, 'index']);
        Route::post('/favorites', [FavoriteController::class, 'store']);
        // What the device collected before there was an account to put it on.
        Route::post('/favorites/sync', [FavoriteController::class, 'sync']);
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

        // «اطلب في وقت لاحق» — a basket with a time on it. Not an order until `cr7:tick`
        // releases it, which is why it lives on its own path rather than as a flag here.
        Route::get('/scheduled-orders', [CustomerScheduledOrderController::class, 'index']);
        Route::post('/scheduled-orders', [CustomerScheduledOrderController::class, 'store']);
        Route::get('/scheduled-orders/{scheduledOrder}', [CustomerScheduledOrderController::class, 'show']);
        Route::delete('/scheduled-orders/{scheduledOrder}', [CustomerScheduledOrderController::class, 'destroy']);
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
    Route::middleware(['auth:sanctum', 'client.type', 'account.active', 'password.fresh', 'type:customer,driver', 'throttle.account'])->group(function () {
        Route::get('/complaints', [CustomerComplaintController::class, 'index']);
        Route::post('/complaints', [CustomerComplaintController::class, 'store'])->middleware('throttle:20,1');
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
    Route::prefix('customer')->middleware(['auth:sanctum', 'client.type', 'account.active', 'password.fresh', 'type:customer', 'throttle.account'])->group(function () {
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
    Route::middleware(['auth:sanctum', 'client.type', 'account.active', 'password.fresh', 'throttle:120,1'])
        ->get('/geo/route', [GeoController::class, 'route']);

    Route::middleware(['auth:sanctum', 'client.type', 'account.active', 'password.fresh', 'throttle.account'])->group(function () {
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    });

    // Catalog browsing — public within the customer app, no auth required (matches doc 08 §4 grouping).
    Route::get('/restaurants', [CustomerRestaurantController::class, 'index'])->middleware('http.cache:catalog,60,30');
    Route::get('/restaurants/{restaurant}', [CustomerRestaurantController::class, 'show'])->middleware('http.cache:catalog,60,30');
    Route::get('/restaurants/{restaurant}/categories', [CustomerRestaurantController::class, 'categories'])->middleware('http.cache:catalog,60,30');
    Route::get('/restaurants/{restaurant}/products', [CustomerRestaurantController::class, 'products'])->middleware('http.cache:catalog,60,30');
    Route::get('/restaurants/{restaurant}/best-sellers', [CustomerRestaurantController::class, 'bestSellers'])->middleware('http.cache:catalog,60,30');
    Route::get('/restaurants/{restaurant}/reviews', [CustomerRestaurantController::class, 'reviews']);
    // Before /products/{product}: restoring a saved basket is one request, not one
    // per line.
    Route::get('/products', [CustomerProductController::class, 'index']);
    Route::get('/products/{product}', [CustomerProductController::class, 'show'])->middleware('http.cache:catalog,60,30');
    // Tapping a cuisine lists dishes, not kitchens — «يجيبلي المنتجات على طول، مش
    // مطاعم». Public like the rest of the catalogue, and gated on nothing: the
    // browse path runs before the customer signs in.
    Route::get('/cuisines/{cuisine}/products', [CustomerProductController::class, 'byCuisine'])->middleware('http.cache:catalog,60,30');
    Route::get('/search', [SearchController::class, 'index'])->middleware('throttle.account:search');

    /*
    |----------------------------------------------------------------------
    | §6 Restaurant — auth:sanctum + type:restaurant. Every row is scoped to
    | the caller's own restaurant (doc 12 §5 / §8): a restaurant only ever
    | sees its own slice of a group, never a sibling sub-order.
    |----------------------------------------------------------------------
    */
    Route::prefix('restaurant')->middleware(['auth:sanctum', 'client.type', 'account.active', 'password.fresh', 'type:restaurant', 'throttle.account'])->group(function () {
        Route::get('/me', [RestaurantMeController::class, 'show']);
        Route::patch('/me', [RestaurantMeController::class, 'update']);
        // doc 71 — the pictures this place may choose from, for one image field.
        Route::get('/media/picker', [RestaurantMediaPickerController::class, 'index']);
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
        // Doc 77 — PUTs, so `order` never collides with `{category}`'s PATCH/DELETE.
        Route::put('/categories/order', [RestaurantCategoryController::class, 'order']);
        Route::put('/categories/{category}/products/order', [RestaurantCategoryController::class, 'productsOrder']);
        Route::post('/categories', [RestaurantCategoryController::class, 'store']);
        Route::patch('/categories/{category}', [RestaurantCategoryController::class, 'update']);
        Route::delete('/categories/{category}', [RestaurantCategoryController::class, 'destroy']);

        // Doc 82 — the cuisines a dish is filed under, and «تصنيف جديد» from the form.
        Route::get('/cuisines', [RestaurantCuisineController::class, 'index']);
        Route::post('/cuisines', [RestaurantCuisineController::class, 'store']);

        Route::get('/products', [RestaurantProductController::class, 'index']);
        Route::post('/products', [RestaurantProductController::class, 'store']);
        Route::get('/products/{product}', [RestaurantProductController::class, 'show']);
        Route::patch('/products/{product}', [RestaurantProductController::class, 'update']);
        Route::delete('/products/{product}', [RestaurantProductController::class, 'destroy']);
        Route::post('/products/{product}/availability', [RestaurantProductController::class, 'availability']);
        Route::post('/products/bulk-availability', [RestaurantProductController::class, 'bulkAvailability']);
        Route::post('/products/bulk-delete', [RestaurantProductController::class, 'bulkDestroy']);

        // Batch 72 — one picture at a time. `gallery/order` is a PUT and `gallery/{ref}` a
        // DELETE, so neither shadows the other.
        Route::post('/products/{product}/gallery', [RestaurantProductGalleryController::class, 'store']);
        Route::put('/products/{product}/gallery/order', [RestaurantProductGalleryController::class, 'order']);
        Route::delete('/products/{product}/gallery/{ref}', [RestaurantProductGalleryController::class, 'destroy']);

        Route::get('/products/{product}/option-groups', [RestaurantProductOptionGroupController::class, 'index']);
        // doc 76 §2.4 — a PUT, so it never collides with `{optionGroup}`'s PATCH/DELETE.
        Route::put('/products/{product}/option-groups/order', [RestaurantProductOptionGroupController::class, 'order']);
        Route::post('/products/{product}/option-groups', [RestaurantProductOptionGroupController::class, 'store']);
        Route::patch('/products/{product}/option-groups/{optionGroup}', [RestaurantProductOptionGroupController::class, 'update']);
        Route::delete('/products/{product}/option-groups/{optionGroup}', [RestaurantProductOptionGroupController::class, 'destroy']);

        Route::get('/option-groups/{optionGroup}/options', [RestaurantProductOptionController::class, 'index']);
        Route::put('/option-groups/{optionGroup}/options/order', [RestaurantProductOptionController::class, 'order']);
        Route::post('/option-groups/{optionGroup}/options', [RestaurantProductOptionController::class, 'store']);
        Route::patch('/option-groups/{optionGroup}/options/{option}', [RestaurantProductOptionController::class, 'update']);
        Route::delete('/option-groups/{optionGroup}/options/{option}', [RestaurantProductOptionController::class, 'destroy']);

        // Read-only: a scheduled basket has nothing to accept yet.
        Route::get('/scheduled-orders', [RestaurantScheduledOrderController::class, 'index']);

        Route::get('/reports/summary', [RestaurantReportController::class, 'summary']);
        Route::get('/reports/sales', [RestaurantReportController::class, 'sales']);
        Route::get('/reports/top-products', [RestaurantReportController::class, 'topProducts']);
        Route::get('/reports/export', [RestaurantReportController::class, 'export']);
        // Declared before the `{...}`-free `/reports/export` would ever shadow it — it
        // would not, but the pair reads as one thing and belongs together.
        Route::get('/reports/top-products/export', [RestaurantReportController::class, 'topProductsExport']);

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
    Route::prefix('driver')->middleware(['auth:sanctum', 'client.type', 'account.active', 'password.fresh', 'type:driver', 'throttle.account'])->group(function () {
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
        Route::post('/errands/{errand}/decline', [DriverErrandController::class, 'decline']);
        Route::post('/errands/{errand}/picked-up', [DriverErrandController::class, 'pickedUp']);
        Route::post('/errands/{errand}/deliver', [DriverErrandController::class, 'deliver']);
        Route::post('/errands/{errand}/purchases', [DriverErrandController::class, 'purchases']);
        Route::post('/errands/{errand}/cancel', [DriverErrandController::class, 'cancel']);

        // doc 63 §3.3 — the whole trip; `/orders/active` stays and answers its focus.
        Route::get('/trip', [DriverTripController::class, 'show']);

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
        // Before `/earnings/{order}`, or the binder reads «unsettled» as an order id.
        Route::get('/earnings/unsettled', [DriverEarningsController::class, 'unsettled']);
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
    Route::prefix('admin')->middleware(['auth:sanctum', 'client.type', 'account.active', 'password.fresh', 'type:admin', 'throttle.account'])->group(function () {

        // Batch 68 — the filter pickers (ADMIN-LISTS.md §1.27): id + name, scoped like the
        // list each one narrows, open to every admin.
        Route::get('/lookups/{kind}', [LookupController::class, 'show'])->whereIn('kind', LookupController::KINDS);

        // doc 71 — the media library. The picker is open to every admin (it is how an admin who
        // may only edit products picks a picture for one); the library itself answers to
        // `media.view` / `media.manage`, and the writes to a global scope — one shared pool.
        Route::get('/media/picker', [MediaController::class, 'picker']);
        Route::middleware('permission:media.view')->group(function () {
            Route::get('/media', [MediaController::class, 'index']);
            Route::get('/media/meta', [MediaController::class, 'meta']);
            Route::get('/media/available', [MediaController::class, 'available']);
            Route::get('/media/tags', [MediaTagController::class, 'index']);
            Route::get('/media/{media}', [MediaController::class, 'show'])->whereNumber('media');
            Route::get('/place-groups', [PlaceGroupController::class, 'index']);
            Route::get('/place-groups/{placeGroup}', [PlaceGroupController::class, 'show']);
        });
        Route::middleware(['permission:media.manage', 'admin.global'])->group(function () {
            Route::post('/media', [MediaController::class, 'store']);
            Route::post('/media/available', [MediaController::class, 'addAvailable']);
            Route::post('/media/tags', [MediaTagController::class, 'store']);
            Route::patch('/media/tags/{tag}', [MediaTagController::class, 'update']);
            Route::delete('/media/tags/{tag}', [MediaTagController::class, 'destroy']);
            Route::patch('/media/{media}', [MediaController::class, 'update'])->whereNumber('media');
            Route::delete('/media/{media}', [MediaController::class, 'destroy'])->whereNumber('media');
            Route::post('/media/{media}/restore', [MediaController::class, 'restore'])->whereNumber('media');
            Route::post('/place-groups', [PlaceGroupController::class, 'store']);
            Route::patch('/place-groups/{placeGroup}', [PlaceGroupController::class, 'update']);
            Route::put('/place-groups/{placeGroup}/places', [PlaceGroupController::class, 'syncPlaces']);
            Route::delete('/place-groups/{placeGroup}', [PlaceGroupController::class, 'destroy']);
        });

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
        // Doc 87 — «ترتيب الأماكن». One list for the platform, so global operators only.
        Route::get('/places/order', [PlaceOrderController::class, 'show'])
            ->middleware(['permission:restaurants.view', 'admin.global']);
        Route::put('/places/order', [PlaceOrderController::class, 'update'])
            ->middleware(['permission:restaurants.update', 'admin.global']);
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

        // Batch 67 — `scopeBindings()`: the child in the path must belong to the parent in
        // the path, or it is a 404. Without it `PATCH /restaurants/1/products/999` edited
        // product 999 wherever it lived — the restaurant id in the URL was decoration,
        // and a scope check on it could be stepped around with any in-scope parent.
        // Doc 77 — reading a place's sections needs only `products.view`: `ADM-13` builds
        // its category column, filter and the product form's picker from it, and a
        // look-only admin got an empty list and a form that could not be saved.
        Route::get('/restaurants/{restaurant}/menu/export', \App\Http\Controllers\Api\Admin\MenuExportController::class)->middleware('permission:products.view');
        Route::get('/restaurants/{restaurant}/categories', [MenuCategoryController::class, 'index'])->middleware('permission:products.view')->scopeBindings();

        Route::middleware('permission:restaurants.manage_menu')->scopeBindings()->group(function () {
            Route::put('/restaurants/{restaurant}/categories/order', [MenuCategoryController::class, 'order']);
            Route::post('/restaurants/{restaurant}/categories', [MenuCategoryController::class, 'store']);
            Route::patch('/restaurants/{restaurant}/categories/{menuCategory}', [MenuCategoryController::class, 'update']);
            Route::delete('/restaurants/{restaurant}/categories/{menuCategory}', [MenuCategoryController::class, 'destroy']);
        });

        Route::middleware('permission:products.view')->scopeBindings()->group(function () {
            Route::get('/restaurants/{restaurant}/products', [ProductController::class, 'index']);
            Route::get('/restaurants/{restaurant}/products/{product}', [ProductController::class, 'show']);
        });
        Route::post('/restaurants/{restaurant}/products', [ProductController::class, 'store'])->middleware('permission:products.create');
        Route::patch('/restaurants/{restaurant}/products/{product}', [ProductController::class, 'update'])->middleware('permission:products.update')->scopeBindings();
        Route::post('/restaurants/{restaurant}/products/{product}/availability', [ProductController::class, 'toggleAvailability'])->middleware('permission:products.toggle_availability')->scopeBindings();
        Route::delete('/restaurants/{restaurant}/products/{product}', [ProductController::class, 'destroy'])->middleware('permission:products.delete')->scopeBindings();
        // Doc 77 — many dishes at once, and the dishes inside one section in order.
        Route::post('/restaurants/{restaurant}/products/bulk-delete', [ProductController::class, 'bulkDestroy'])->middleware('permission:products.delete');
        Route::post('/restaurants/{restaurant}/products/bulk-availability', [ProductController::class, 'bulkAvailability'])->middleware('permission:products.toggle_availability');
        Route::put('/restaurants/{restaurant}/categories/{menuCategory}/products/order', [MenuCategoryController::class, 'productsOrder'])->middleware('permission:products.update')->scopeBindings();

        // Batch 72 — the per-picture gallery API, the admin mirror of the restaurant's.
        Route::middleware('permission:products.update')->scopeBindings()->group(function () {
            Route::post('/restaurants/{restaurant}/products/{product}/gallery', [ProductGalleryController::class, 'store']);
            Route::put('/restaurants/{restaurant}/products/{product}/gallery/order', [ProductGalleryController::class, 'order']);
            Route::delete('/restaurants/{restaurant}/products/{product}/gallery/{ref}', [ProductGalleryController::class, 'destroy']);
        });

        // doc 76 §2.8 — reading a product's options needs only `products.view`, so an
        // admin who may look but not change still sees the whole dish.
        Route::middleware('permission:products.view')->scopeBindings()->group(function () {
            Route::get('/products/{product}/option-groups', [ProductOptionGroupController::class, 'index']);
            Route::get('/option-groups/{optionGroup}/options', [ProductOptionController::class, 'index']);
        });

        Route::middleware('permission:products.update')->scopeBindings()->group(function () {
            Route::put('/products/{product}/option-groups/order', [ProductOptionGroupController::class, 'order']);
            Route::post('/products/{product}/option-groups', [ProductOptionGroupController::class, 'store']);
            Route::patch('/products/{product}/option-groups/{optionGroup}', [ProductOptionGroupController::class, 'update']);
            Route::delete('/products/{product}/option-groups/{optionGroup}', [ProductOptionGroupController::class, 'destroy']);

            Route::put('/option-groups/{optionGroup}/options/order', [ProductOptionController::class, 'order']);
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
            // doc 62 §4.3 — the rows no statement has closed, grouped the way the office
            // settles them: cash orders beside online ones.
            Route::get('/drivers/{driver}/unsettled', [DriverFinanceController::class, 'unsettled']);
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

        // The home screen's top level — «مطاعم / صيدليات / كافيهات / سوبرماركت / خضروات»,
        // and whatever the operator adds after them.
        Route::get('/store-categories', [AdminStoreCategoryController::class, 'index']);
        Route::get('/store-categories/{storeCategory}', [AdminStoreCategoryController::class, 'show']);
        Route::middleware('permission:content.manage')->group(function () {
            Route::post('/store-categories', [AdminStoreCategoryController::class, 'store']);
            // Both verbs. The card art arrives as multipart and PHP does not populate
            // `$_FILES` for a PATCH body, so the dashboard posts with `_method=PATCH`
            // and a client that can send a real PATCH (no file) still works.
            Route::match(['post', 'patch'], '/store-categories/{storeCategory}', [AdminStoreCategoryController::class, 'update']);
            Route::post('/store-categories-reorder', [AdminStoreCategoryController::class, 'reorder']);
            Route::delete('/store-categories/{storeCategory}', [AdminStoreCategoryController::class, 'destroy']);
        });

        // Orders — doc 12 §3, the intervention console (doc 24 §3)
        Route::middleware('permission:orders.view')->group(function () {
            Route::get('/orders', [AdminOrderController::class, 'index']);
            // **Before** `/orders/{order}`, or the binder reads «export» as an order id
            // and answers 404. Same trap as `/reports/top-products/export` above.
            // It is the list, not a report, so it carries `orders.view` — the grant that
            // already draws the table on `ADM-04`.
            Route::get('/orders/export', [AdminReportExportController::class, 'export'])->defaults('report', 'orders');
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
            Route::get('/scheduled-orders', [AdminScheduledOrderController::class, 'index']);
            Route::get('/scheduled-orders/{scheduledOrder}', [AdminScheduledOrderController::class, 'show']);

            Route::get('/errands', [AdminErrandController::class, 'index']);
            Route::get('/errands/{errand}', [AdminErrandController::class, 'show']);
            Route::get('/errands/{errand}/eligible-drivers', [AdminErrandController::class, 'eligibleDrivers']);
        });
        // `orders.force_status`, not `orders.update_status` — the latter does not exist
        // in the catalogue, and `permission:` on a name nothing grants denies everybody.
        // Found by pressing the button on production, which is the only way a route
        // gated on a typo is ever found.
        Route::post('/scheduled-orders/{scheduledOrder}/release', [AdminScheduledOrderController::class, 'release'])->middleware('permission:orders.force_status');
        Route::post('/scheduled-orders/{scheduledOrder}/cancel', [AdminScheduledOrderController::class, 'cancel'])->middleware('permission:orders.cancel');

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

        // doc 62 §4.4 — what each place is owed and no statement has claimed yet.
        Route::get('/finance/restaurants', [RestaurantFinanceController::class, 'index'])->middleware('permission:settlements.view');
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
        Route::get('/promo-codes/suggest', [PromoCodeController::class, 'suggest'])->middleware('permission:promos.view');
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

        // «العرض المنبثق» — the home screen's popup card. Same permission as the banners
        // beside it: it is the same job (an offer, its artwork and where it points), and
        // a `permission:home_popups.manage` that nothing seeds would refuse everybody
        // including the super admin — see `RoutePermissionCatalogueTest`.
        Route::get('/home-popups', [AdminHomePopupController::class, 'index']);
        Route::get('/home-popups/{homePopup}', [AdminHomePopupController::class, 'show']);
        Route::middleware('permission:banners.manage')->group(function () {
            Route::post('/home-popups', [AdminHomePopupController::class, 'store']);
            // Both verbs. The artwork arrives as multipart and PHP does not populate
            // `$_FILES` for a PATCH body, so the dashboard posts with `_method=PATCH`
            // and a client that can send a real PATCH (no file) still works.
            Route::match(['post', 'patch'], '/home-popups/{homePopup}', [AdminHomePopupController::class, 'update']);
            Route::post('/home-popups-reorder', [AdminHomePopupController::class, 'reorder']);
            Route::delete('/home-popups/{homePopup}', [AdminHomePopupController::class, 'destroy']);
        });

        Route::get('/pages', [AdminPageController::class, 'index']);
        Route::get('/pages/{slug}', [AdminPageController::class, 'show']);
        Route::post('/pages', [AdminPageController::class, 'store']);
        Route::patch('/pages/{slug}', [AdminPageController::class, 'update']);
        Route::delete('/pages/{slug}', [AdminPageController::class, 'destroy'])->middleware('permission:content.manage');

        Route::get('/faqs', [AdminFaqController::class, 'index']);
        Route::post('/faqs', [AdminFaqController::class, 'store']);
        Route::patch('/faqs/{faq}', [AdminFaqController::class, 'update']);
        Route::delete('/faqs/{faq}', [AdminFaqController::class, 'destroy'])->middleware('permission:content.manage');

        Route::post('/notifications/broadcast', [NotificationBroadcastController::class, 'store'])->middleware(['permission:notifications.broadcast', 'admin.global']);

        // System — users, roles, permissions, settings, audit log (doc 08 §7 note: global-scope admins only)
        // Batch 67 — doc 12 §10.1: users, roles, settings and the audit log are
        // `global` only, whatever the role grants. `admin.global` answers 404 to a
        // scoped admin, after the permission check.
        Route::middleware(['permission:users.view', 'admin.global'])->group(function () {
            Route::get('/users', [AdminUserController::class, 'index']);
            Route::get('/users/{user}', [AdminUserController::class, 'show']);
        });
        Route::middleware(['permission:users.invite', 'admin.global'])->group(function () {
            Route::post('/users', [AdminUserController::class, 'store']);
        });
        Route::middleware(['permission:users.manage', 'admin.global'])->group(function () {
            Route::patch('/users/{user}', [AdminUserController::class, 'update']);
            Route::post('/users/{user}/reset-password', [AdminUserController::class, 'resetPassword']);
            Route::delete('/users/{user}', [AdminUserController::class, 'destroy']);
        });
        /*
         * D-73 — the same four verbs against a customer, a captain or a restaurant
         * owner. `AdminUserController`'s versions 404 on anything but an admin, which
         * is why an operator had no way to act on the account a complaint was about.
         */
        Route::middleware('permission:users.suspend')->group(function () {
            Route::post('/accounts/{user}/block', [AccountModerationController::class, 'block']);
            Route::post('/accounts/{user}/unblock', [AccountModerationController::class, 'unblock']);
        });
        Route::middleware('permission:users.manage')->group(function () {
            Route::post('/accounts/{user}/reset', [AccountModerationController::class, 'reset']);
            Route::delete('/accounts/{user}', [AccountModerationController::class, 'destroy']);
        });

        Route::post('/users/{user}/suspend', [AdminUserController::class, 'suspend'])->middleware(['permission:users.suspend', 'admin.global']);
        Route::post('/users/{user}/restore', [AdminUserController::class, 'restore'])->middleware(['permission:users.suspend', 'admin.global']);
        Route::post('/users/{user}/roles', [AdminUserController::class, 'updateRoles'])->middleware(['permission:roles.manage', 'admin.global']);
        Route::post('/users/{user}/permissions', [AdminUserController::class, 'updatePermissions'])->middleware(['permission:roles.manage', 'admin.global']);
        Route::post('/users/{user}/scope', [AdminUserController::class, 'updateScope'])->middleware(['permission:scope.manage', 'admin.global']);

        Route::middleware(['permission:roles.manage', 'admin.global'])->group(function () {
            Route::get('/roles', [RoleController::class, 'index']);
            Route::get('/roles/{role}', [RoleController::class, 'show']);
            Route::patch('/roles/{role}', [RoleController::class, 'update']);
            Route::delete('/roles/{role}', [RoleController::class, 'destroy']);
            Route::post('/roles/{role}/clone', [RoleController::class, 'clone']);
        });
        Route::post('/roles', [RoleController::class, 'store'])->middleware(['permission:roles.create', 'admin.global']);
        Route::get('/permissions', [AdminPermissionController::class, 'index'])->middleware(['permission:roles.manage', 'admin.global']);

        Route::middleware(['permission:settings.view', 'admin.global'])->group(function () {
            Route::get('/settings', [AdminSettingController::class, 'index']);
            // D-113 — the declared panel both clients render. Before `/settings/{…}`
            // could ever exist, and it does not.
            Route::get('/settings/schema', [AdminSettingController::class, 'schema']);
            Route::get('/settings/sms-status', [AdminSettingController::class, 'smsStatus']);
        });
        Route::patch('/settings', [AdminSettingController::class, 'update'])->middleware(['permission:settings.manage', 'admin.global']);

        // Batch 68 — verification messages: what they cost today, and the numbers the
        // platform has stopped sending to. The settings permissions, global only — the
        // budget is a setting, and these are anybody's phone numbers.
        Route::middleware(['permission:settings.view', 'admin.global'])->group(function () {
            Route::get('/otp/usage', [AdminOtpController::class, 'usage']);
            Route::get('/otp/blocks', [AdminOtpController::class, 'index']);
        });
        Route::middleware(['permission:settings.manage', 'admin.global'])->group(function () {
            Route::post('/otp/blocks', [AdminOtpController::class, 'store']);
            Route::delete('/otp/blocks/{block}', [AdminOtpController::class, 'destroy'])->whereNumber('block');
        });

        Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware(['permission:audit.view', 'admin.global']);

        // Dashboard & alerts
        Route::get('/dashboard/summary', [AdminDashboardController::class, 'summary']);
        Route::get('/dashboard/charts', [AdminDashboardController::class, 'charts']);
        Route::get('/dashboard/alerts', [AdminDashboardController::class, 'alerts']);
        Route::get('/dashboard/live-orders', [AdminDashboardController::class, 'liveOrders']);
        Route::get('/dashboard/open-alerts', [AdminDashboardController::class, 'openAlerts']);
        Route::post('/dashboard/open-alerts/dismiss', [AdminDashboardController::class, 'dismissAlert']);
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

        // «تصدير Excel». Each one carries the same permission as the JSON report beside
        // it — an operator who may not read a report may not download it either, and the
        // two must not be able to drift apart.
        Route::get('/reports/sales/export', [AdminReportExportController::class, 'export'])->defaults('report', 'sales')->middleware('permission:reports.sales');
        Route::get('/reports/restaurants/export', [AdminReportExportController::class, 'export'])->defaults('report', 'restaurants')->middleware('permission:reports.restaurants');
        Route::get('/reports/products/export', [AdminReportExportController::class, 'export'])->defaults('report', 'products')->middleware('permission:reports.products');
        Route::get('/reports/drivers/export', [AdminReportExportController::class, 'export'])->defaults('report', 'drivers')->middleware('permission:reports.drivers');
        Route::get('/reports/zones/export', [AdminReportExportController::class, 'export'])->defaults('report', 'zones')->middleware('permission:reports.zones');
        Route::get('/reports/promo-codes/export', [AdminReportExportController::class, 'export'])->defaults('report', 'promo-codes')->middleware('permission:promos.view');
        Route::get('/reports/refunds/export', [AdminReportExportController::class, 'export'])->defaults('report', 'refunds')->middleware('permission:refunds.view');
        Route::get('/reports/complaints/export', [AdminReportExportController::class, 'export'])->defaults('report', 'complaints')->middleware('permission:complaints.view');
        Route::get('/reports/customers/export', [AdminReportExportController::class, 'export'])->defaults('report', 'customers')->middleware('permission:reports.customers');
        Route::get('/ops/health', [AdminReportController::class, 'opsHealth'])->middleware('permission:ops.health');
    });
});
