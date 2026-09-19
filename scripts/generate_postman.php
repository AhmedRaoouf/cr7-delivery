<?php

/**
 * Generates a complete Postman collection + environment from the actual registered
 * routes, so it can never drift from the real API surface. Run: php scripts/generate_postman.php
 */

// display_errors off: on PHP 8.5 Laravel's own config emits a PDO deprecation that
// would otherwise be prepended to the JSON and break the decode.
$routesJson = shell_exec(
    escapeshellarg(PHP_BINARY).' -d display_errors=0 artisan route:list --path=api/v1 --json 2>&1',
);

// Belt and braces — take the payload from the first bracket, whatever preceded it.
$start = strpos((string) $routesJson, '[');
$routes = $start === false ? null : json_decode(substr((string) $routesJson, $start), true);

if (! is_array($routes)) {
    fwrite(STDERR, "Failed to read routes.\n".$routesJson);
    exit(1);
}

function folderFor(string $uri): string
{
    $uri = preg_replace('#^api/v1/#', '', $uri);
    $segments = explode('/', $uri);
    $first = $segments[0] ?? '';

    return match (true) {
        $first === 'admin' => '05 - Admin',
        $first === 'driver' => '03 - Driver',
        $first === 'restaurant' => '04 - Restaurant',
        in_array($first, ['auth'], true) => '01 - Auth',
        in_array($first, ['cart', 'checkout', 'orders', 'promo', 'addresses', 'favorites', 'complaints', 'notifications', 'restaurants', 'products', 'search'], true) => '02 - Customer',
        default => '00 - Public',
    };
}

function toPostmanUrl(string $uri): array
{
    $uri = preg_replace('#^api/v1/#', '', $uri);
    $uri = preg_replace('/\{([a-zA-Z_]+)\}/', ':$1', $uri);
    $segments = array_values(array_filter(explode('/', $uri), fn ($s) => $s !== ''));

    $variables = [];
    foreach ($segments as $segment) {
        if (str_starts_with($segment, ':')) {
            $variables[] = ['key' => substr($segment, 1), 'value' => ''];
        }
    }

    return [
        'raw' => '{{base_url}}/'.$uri,
        'host' => ['{{base_url}}'],
        'path' => $segments,
        'variable' => $variables,
    ];
}

/** Resource sub-folder within a role folder, e.g. admin/restaurants/{restaurant}/products -> "Restaurants". */
function subFolderFor(string $uri): string
{
    $uri = preg_replace('#^api/v1/#', '', $uri);
    $segments = explode('/', $uri);

    if (in_array($segments[0] ?? '', ['admin', 'driver', 'restaurant'], true) && count($segments) > 1) {
        array_shift($segments);
    }

    $resource = $segments[0] ?? 'misc';

    $labels = [
        'auth' => 'Auth',
        'config' => 'Config',
        'devices' => 'Devices',
        'geo' => 'Geo',
        'cities' => 'Cities',
        'zones' => 'Zones',
        'cuisines' => 'Cuisines',
        'banners' => 'Banners',
        'pages' => 'Pages',
        'faqs' => 'FAQs',
        'promo' => 'Promo',
        'promo-codes' => 'Promo Codes',
        'restaurants' => 'Restaurants',
        'products' => 'Products',
        'categories' => 'Categories',
        'search' => 'Search',
        'favorites' => 'Favorites',
        'cart' => 'Cart',
        'checkout' => 'Checkout',
        'addresses' => 'Addresses',
        'orders' => 'Orders',
        'order-groups' => 'Order Groups',
        'direct-orders' => 'Direct Orders',
        'complaints' => 'Complaints',
        'notifications' => 'Notifications',
        'me' => 'Me / Profile',
        'status' => 'Status',
        'location' => 'Location',
        'offers' => 'Offers',
        'documents' => 'Documents',
        'transactions' => 'Transactions',
        'earning-rule' => 'Earning Rule',
        'open-status' => 'Open Status',
        'opening-hours' => 'Opening Hours',
        'drivers' => 'Drivers',
        'driver-documents' => 'Driver Documents',
        'pricing-rules' => 'Pricing Rules',
        'payments' => 'Payments',
        'payment-proofs' => 'Payment Proofs',
        'refunds' => 'Refunds',
        'settlements' => 'Settlements',
        'delivery-issues' => 'Delivery Issues',
        'users' => 'Users',
        'roles' => 'Roles',
        'settings' => 'Settings',
        'customers' => 'Customers',
        'reports' => 'Reports',
        'dashboard' => 'Dashboard',
    ];

    return $labels[$resource] ?? ucwords(str_replace('-', ' ', $resource));
}

function xAppFor(string $folder): string
{
    return match ($folder) {
        '03 - Driver' => 'app',
        '04 - Restaurant' => 'restaurant',
        '05 - Admin' => 'admin',
        '02 - Customer' => 'app',
        default => 'app',
    };
}

function tokenVarFor(string $folder): ?string
{
    return match ($folder) {
        '02 - Customer' => '{{customer_token}}',
        '03 - Driver' => '{{driver_token}}',
        '04 - Restaurant' => '{{restaurant_token}}',
        '05 - Admin' => '{{admin_token}}',
        default => null,
    };
}

/**
 * Routes that accept an uploaded file (doc 12 §5: public images vs "Admin only" private
 * documents) - these get a Postman `formdata` body instead of raw JSON, with the listed
 * fields marked type=file. Matches the FormRequest rules exactly (image/file validation).
 */
function fileFieldsFor(string $method, string $uri): ?array
{
    $map = [
        'POST api/v1/admin/categories' => ['image'],
        'PATCH api/v1/admin/categories/{category}' => ['image'],
        'POST api/v1/admin/restaurants/{restaurant}/categories' => ['image'],
        'PATCH api/v1/admin/restaurants/{restaurant}/categories/{menuCategory}' => ['image'],
        'POST api/v1/admin/restaurants/{restaurant}/products' => ['image', 'gallery'],
        'PATCH api/v1/admin/restaurants/{restaurant}/products/{product}' => ['image', 'gallery'],
        'POST api/v1/restaurant/categories' => ['image'],
        'PATCH api/v1/restaurant/categories/{category}' => ['image'],
        'POST api/v1/restaurant/products' => ['image', 'gallery'],
        'PATCH api/v1/restaurant/products/{product}' => ['image', 'gallery'],
        // Batch 72 — append pictures to one product's gallery (files; `gallery_media[]` refs are text).
        'POST api/v1/restaurant/products/{product}/gallery' => ['gallery'],
        'POST api/v1/admin/restaurants/{restaurant}/products/{product}/gallery' => ['gallery'],
        'PATCH api/v1/restaurant/me' => ['logo', 'cover'],
        'PATCH api/v1/auth/me' => ['avatar'],
        'POST api/v1/admin/banners' => ['image_ar', 'image_en'],
        'PATCH api/v1/admin/banners/{banner}' => ['image_ar', 'image_en'],
        'POST api/v1/admin/drivers/{driver}/documents' => ['file'],
        'POST api/v1/driver/documents' => ['file'],
        'POST api/v1/orders/{orderGroup}/payment/proof' => ['image'],
    ];

    return $map["{$method} {$uri}"] ?? null;
}

/** Best-effort example bodies for the routes worth demonstrating; everything else gets an empty {} template. */
function exampleBody(string $method, string $uri): ?array
{
    $map = [
        'POST api/v1/auth/otp/send' => ['phone' => '01012345678'],
        'POST api/v1/auth/otp/verify' => ['phone' => '01012345678', 'code' => '123456'],
        'POST api/v1/auth/login' => ['login' => '01000000099', 'password' => 'Cr7Delivery#2026'],
        // D-120 — send `X-Auth-Refresh: 1` on the sign-in to receive a refresh token at all.
        'POST api/v1/auth/refresh' => ['refresh_token' => '{{refresh_token}}'],
        'PATCH api/v1/auth/me' => ['name' => 'New Name', 'locale' => 'ar', 'theme' => 'dark'],
        'POST api/v1/auth/password/change' => ['current_password' => 'old', 'password' => 'new-password', 'password_confirmation' => 'new-password'],
        'POST api/v1/devices' => ['token' => 'fcm-token-example', 'platform' => 'android', 'app' => 'app', 'device_id' => 'device-uuid'],
        'POST api/v1/geo/resolve' => ['lat' => 31.383, 'lng' => 31.483],

        'POST api/v1/addresses' => ['label' => 'home', 'city_id' => 1, 'zone_id' => 1, 'street' => 'Test St', 'latitude' => 31.383, 'longitude' => 31.483, 'is_default' => true],
        'PATCH api/v1/addresses/{address}' => ['street' => 'Updated St'],
        'POST api/v1/favorites' => ['type' => 'restaurant', 'id' => 1],
        'DELETE api/v1/favorites' => ['type' => 'restaurant', 'id' => 1],
        'POST api/v1/cart/items' => ['product_id' => 1, 'quantity' => 1, 'option_ids' => []],
        'PATCH api/v1/cart/items/{item}' => ['quantity' => 2],
        'POST api/v1/checkout/quote' => ['address_id' => 1, 'payment_method' => 'cash'],
        'POST api/v1/promo/validate' => ['code' => 'WELCOME10', 'address_id' => 1],
        'POST api/v1/orders' => ['address_id' => 1, 'payment_method' => 'cash', 'customer_note' => ''],
        'POST api/v1/orders/{orderGroup}/cancel' => ['reason_code' => 'changed_mind', 'note' => ''],
        'POST api/v1/orders/{orderGroup}/ratings' => ['restaurants' => [['restaurant_id' => 1, 'stars' => 5, 'comment' => 'Great!']]],
        'POST api/v1/orders/{orderGroup}/payment/proof' => ['image' => 'proof.jpg', 'reference' => 'TXN123', 'amount_claimed' => 100],
        'POST api/v1/complaints' => ['category' => 'food_quality', 'order_id' => 1, 'description' => 'Food was cold'],
        'POST api/v1/complaints/{complaint}/messages' => ['message' => 'Thanks for the update'],

        'POST api/v1/driver/status' => ['is_online' => true],
        'POST api/v1/driver/location' => ['latitude' => 31.383, 'longitude' => 31.483, 'speed' => 0, 'accuracy' => 5],
        'POST api/v1/driver/orders/{order}/reject' => ['reason_code' => 'too_far'],
        'POST api/v1/driver/orders/{order}/pickup' => ['otp' => '0000'],
        'POST api/v1/driver/orders/{order}/deliver' => ['otp' => '0000'],
        'POST api/v1/driver/orders/{order}/cancel' => ['reason_code' => 'emergency', 'note' => ''],
        'POST api/v1/driver/orders/{order}/issue' => ['category' => 'wrong_address', 'description' => ''],
        'POST api/v1/driver/documents' => ['type' => 'license', 'file' => 'license.jpg'],

        'PATCH api/v1/restaurant/me' => ['name_ar' => 'اسم عربي', 'name_en' => 'English Name'],
        'POST api/v1/restaurant/open-status' => ['is_open' => true],
        'PUT api/v1/restaurant/opening-hours' => ['hours' => array_map(fn ($d) => ['weekday' => $d, 'opens_at' => '09:00', 'closes_at' => '23:00', 'is_closed' => false], range(0, 6))],
        'POST api/v1/restaurant/orders/{order}/accept' => ['prep_minutes' => 15, 'restaurant_order_ref' => 'K-1'],
        'POST api/v1/restaurant/orders/{order}/reject' => ['reason_code' => 'too_busy', 'note' => ''],
        'POST api/v1/restaurant/orders/{order}/start-preparing' => ['restaurant_order_ref' => 'K-1'],
        'PATCH api/v1/restaurant/orders/{order}/prep-time' => ['extra_minutes' => 5],
        'POST api/v1/restaurant/categories' => ['name_ar' => 'قسم', 'name_en' => 'Category'],
        'POST api/v1/restaurant/products' => ['menu_category_id' => 1, 'name_ar' => 'منتج', 'name_en' => 'Product', 'price' => 100],
        'POST api/v1/restaurant/products/{product}/availability' => ['is_available' => true],
        'POST api/v1/restaurant/direct-orders/quote' => ['latitude' => 31.39, 'longitude' => 31.49],
        'POST api/v1/restaurant/direct-orders' => ['guest_customer_name' => 'Mahmoud', 'guest_customer_phone' => '01055555555', 'delivery_address_text' => 'Test St', 'delivery_latitude' => 31.39, 'delivery_longitude' => 31.49, 'restaurant_order_ref' => 'D-1', 'declared_order_value' => 120, 'food_payment' => 'collect_by_driver', 'ready_now' => true],
        'POST api/v1/restaurant/direct-orders/{order}/cancel' => ['reason_code' => 'customer_cancelled', 'note' => ''],
        'POST api/v1/restaurant/complaints' => ['category' => 'app_problem', 'subject' => '', 'description' => ''],
        'POST api/v1/restaurant/complaints/{complaint}/messages' => ['message' => 'Reply from restaurant'],

        'POST api/v1/admin/cities' => ['name_ar' => 'مدينة', 'name_en' => 'City', 'center_lat' => 31.38, 'center_lng' => 31.48, 'pricing_type' => 'fixed_zone', 'default_min_order' => 50],
        'PATCH api/v1/admin/cities/{city}' => ['name_en' => 'Updated City'],
        'POST api/v1/admin/cities/{city}/pricing-type' => ['pricing_type' => 'distance_based'],
        'POST api/v1/admin/zones' => ['city_id' => 1, 'name_ar' => 'منطقة', 'name_en' => 'Zone', 'center_lat' => 31.38, 'center_lng' => 31.48, 'delivery_fee' => 10],
        'POST api/v1/admin/pricing-rules' => ['city_id' => 1, 'min_distance_km' => 0, 'max_distance_km' => 3, 'fee' => 10],
        'POST api/v1/admin/pricing-rules/preview' => ['restaurant_id' => 1, 'lat' => 31.39, 'lng' => 31.49],
        'POST api/v1/admin/restaurants' => ['city_id' => 1, 'service_zone_id' => 1, 'name_ar' => 'مطعم', 'name_en' => 'Restaurant', 'owner_phone' => '01099999990', 'phone' => '01099999991', 'latitude' => 31.383, 'longitude' => 31.483, 'commission_rate' => 10],
        'POST api/v1/admin/restaurants/{restaurant}/status' => ['is_active' => true],
        'POST api/v1/admin/restaurants/{restaurant}/commission' => ['commission_rate' => 12],
        'POST api/v1/admin/restaurants/{restaurant}/zones' => ['zone_ids' => [1]],
        'POST api/v1/admin/restaurants/{restaurant}/direct-orders' => ['enabled' => true],
        'POST api/v1/admin/restaurants/{restaurant}/categories' => ['name_ar' => 'قسم', 'name_en' => 'Category'],
        'POST api/v1/admin/restaurants/{restaurant}/products' => ['menu_category_id' => 1, 'name_ar' => 'منتج', 'name_en' => 'Product', 'price' => 100],
        // Batch 72 — the per-picture gallery API. `order` is the full list of `gallery_items[].ref`,
        // `expected_version` the `gallery_version` you last read (omit it for last-write-wins).
        'PUT api/v1/restaurant/products/{product}/gallery/order' => ['order' => ['own:<sha1>', 'own:<sha1>'], 'expected_version' => '<gallery_version>'],
        'PUT api/v1/admin/restaurants/{restaurant}/products/{product}/gallery/order' => ['order' => ['own:<sha1>', 'own:<sha1>'], 'expected_version' => '<gallery_version>'],
        'POST api/v1/admin/drivers' => ['city_id' => 1, 'name' => 'Driver', 'phone' => '01088888880', 'vehicle_type' => 'motorcycle', 'earning_type' => 'fixed', 'earning_value' => 15, 'zone_ids' => [1]],
        'POST api/v1/admin/drivers/{driver}/status' => ['is_active' => true],
        'POST api/v1/admin/drivers/{driver}/earning-rule' => ['earning_type' => 'fixed', 'earning_value' => 20],
        'POST api/v1/admin/drivers/{driver}/transactions' => ['type' => 'bonus', 'amount' => 50, 'note' => 'Ramadan bonus'],
        'POST api/v1/admin/driver-documents/{driverDocument}/review' => ['status' => 'approved'],
        'POST api/v1/admin/cuisines' => ['name_ar' => 'صنف', 'name_en' => 'Cuisine'],

        'POST api/v1/admin/orders/{order}/cancel' => ['reason_code' => 'ops_decision', 'note' => '', 'refund' => false],
        'POST api/v1/admin/orders/{order}/reassign-driver' => ['driver_id' => 1, 'reason_code' => 'driver_unresponsive', 'note' => ''],
        'POST api/v1/admin/orders/{order}/unassign-driver' => ['reason_code' => 'driver_unresponsive', 'note' => '', 'requeue' => true],
        'POST api/v1/admin/orders/{order}/force-status' => ['status' => 'delivered', 'note' => 'manual correction'],
        'POST api/v1/admin/orders/{order}/extend-prep' => ['extra_minutes' => 10, 'note' => ''],
        'POST api/v1/admin/orders/{order}/notes' => ['kind' => 'note', 'body' => 'Called the customer'],
        'POST api/v1/admin/orders/{order}/issues' => ['category' => 'handover', 'description' => ''],
        'POST api/v1/admin/order-groups/{orderGroup}/recalculate' => [],
        'POST api/v1/admin/payments/{payment}/approve' => ['note' => ''],
        'POST api/v1/admin/payments/{payment}/reject' => ['reason' => 'Amount mismatch'],
        'POST api/v1/admin/refunds' => ['order_id' => 1, 'amount' => 50, 'type' => 'partial', 'reason_code' => 'missing_item'],
        'POST api/v1/admin/refunds/{refund}/process' => ['method' => 'cash'],
        'POST api/v1/admin/settlements/generate' => ['party_type' => 'restaurant', 'party_id' => 1, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31'],
        'POST api/v1/admin/settlements/{settlement}/pay' => ['reference' => 'BANK-001'],
        'POST api/v1/admin/delivery-issues/{deliveryIssue}/resolve' => ['resolution_note' => 'Resolved with the customer'],
        'PATCH api/v1/admin/complaints/{complaint}' => ['status' => 'in_progress', 'priority' => 'high'],
        'POST api/v1/admin/complaints/{complaint}/messages' => ['message' => 'We are looking into this', 'visibility' => 'all'],
        'POST api/v1/admin/complaints/{complaint}/assign' => ['assigned_to' => 1],
        'POST api/v1/admin/complaints/{complaint}/escalate' => ['priority' => 'high'],

        'POST api/v1/admin/promo-codes' => ['code' => 'PROMO10', 'title_ar' => 'خصم', 'title_en' => 'Discount', 'type' => 'percent', 'value' => 10],
        'POST api/v1/admin/banners' => ['title_ar' => 'بانر', 'title_en' => 'Banner', 'image_ar' => 'a.jpg', 'image_en' => 'a.jpg'],
        'POST api/v1/admin/pages' => ['slug' => 'terms', 'title_ar' => 'الشروط', 'title_en' => 'Terms', 'body_ar' => '...', 'body_en' => '...'],
        'POST api/v1/admin/faqs' => ['question_ar' => 'سؤال', 'question_en' => 'Question', 'answer_ar' => 'إجابة', 'answer_en' => 'Answer'],
        'POST api/v1/admin/notifications/broadcast' => ['audience' => 'customers', 'title_ar' => 'عرض', 'title_en' => 'Offer', 'body_ar' => 'خصم اليوم', 'body_en' => 'Discount today'],

        'POST api/v1/admin/users' => ['name' => 'Ops Admin', 'phone' => '01077777770', 'role' => 'operations', 'scope' => ['scope_type' => 'city', 'city_ids' => [1]]],
        'POST api/v1/admin/users/{user}/roles' => ['roles' => ['operations']],
        'POST api/v1/admin/users/{user}/permissions' => ['grants' => [], 'revokes' => []],
        'POST api/v1/admin/users/{user}/scope' => ['scope_type' => 'city', 'city_ids' => [1]],
        'POST api/v1/admin/users/{user}/suspend' => ['reason' => 'Policy violation'],
        'POST api/v1/admin/roles' => ['name' => 'custom_role', 'label_ar' => 'دور مخصص', 'label_en' => 'Custom Role', 'permissions' => ['orders.view']],
        'POST api/v1/admin/roles/{role}/clone' => ['name' => 'operations_copy', 'label_ar' => 'نسخة', 'label_en' => 'Copy'],
        'PATCH api/v1/admin/settings' => ['payment.transfer_deadline_minutes' => 15],
        'POST api/v1/admin/customers/{customer}/status' => ['is_active' => true],
    ];

    return $map["{$method} {$uri}"] ?? null;
}

$items = [];
$folders = [];

foreach ($routes as $route) {
    $uri = $route['uri'];
    $methods = explode('|', $route['method']);

    foreach ($methods as $method) {
        if ($method === 'HEAD') {
            continue;
        }

        $folder = folderFor($uri);
        $subFolder = subFolderFor($uri);
        $folders[$folder] ??= [];
        $folders[$folder][$subFolder] ??= [];

        $headers = [
            ['key' => 'Accept', 'value' => 'application/json'],
            ['key' => 'Accept-Language', 'value' => 'ar'],
            ['key' => 'X-App', 'value' => xAppFor($folder)],
        ];

        $fileFields = fileFieldsFor($method, $uri);

        // Postman sets its own multipart boundary Content-Type; setting one manually breaks the upload.
        if (! $fileFields && in_array($method, ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
            $headers[] = ['key' => 'Content-Type', 'value' => 'application/json'];
        }
        if (str_contains($uri, 'orders') && $method === 'POST' && ! str_contains($uri, '{')) {
            $headers[] = ['key' => 'Idempotency-Key', 'value' => '{{$guid}}'];
        }
        if (str_contains($uri, 'direct-orders') && $method === 'POST' && substr_count($uri, '/') <= 3) {
            $headers[] = ['key' => 'Idempotency-Key', 'value' => '{{$guid}}'];
        }

        $tokenVar = tokenVarFor($folder);
        if ($tokenVar) {
            $headers[] = ['key' => 'Authorization', 'value' => "Bearer {$tokenVar}"];
        }

        $request = [
            'method' => $method,
            'header' => $headers,
            'url' => toPostmanUrl($uri),
        ];

        if ($fileFields && in_array($method, ['POST', 'PATCH', 'PUT'], true)) {
            $formdata = [];
            foreach ($fileFields as $fileField) {
                $formdata[] = ['key' => $fileField, 'type' => 'file', 'src' => []];
            }
            $nonFileExample = array_diff_key((array) (exampleBody($method, $uri) ?? []), array_flip($fileFields));
            foreach ($nonFileExample as $key => $value) {
                $formdata[] = ['key' => $key, 'value' => is_scalar($value) ? (string) $value : json_encode($value), 'type' => 'text'];
            }

            // PHP never populates $_FILES for a real multipart PATCH/PUT request. Laravel's fix is
            // method-spoofing: send POST with a `_method` field and it re-routes internally as
            // PATCH/PUT while $_FILES parses normally either way.
            if (in_array($method, ['PATCH', 'PUT'], true)) {
                $formdata[] = ['key' => '_method', 'value' => $method, 'type' => 'text'];
                $request['method'] = 'POST';
            }

            $request['body'] = ['mode' => 'formdata', 'formdata' => $formdata];
        } elseif (in_array($method, ['POST', 'PATCH', 'PUT'], true)) {
            $body = exampleBody($method, $uri) ?? new stdClass;
            $request['body'] = [
                'mode' => 'raw',
                'raw' => json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'options' => ['raw' => ['language' => 'json']],
            ];
        } elseif ($method === 'DELETE' && exampleBody($method, $uri)) {
            $request['body'] = [
                'mode' => 'raw',
                'raw' => json_encode(exampleBody($method, $uri), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'options' => ['raw' => ['language' => 'json']],
            ];
        }

        $name = $route['action'] !== 'Closure' ? preg_replace('/^.*\\\\/', '', $route['action']) : $uri;
        $name = "{$method} /{$uri}";

        $folders[$folder][$subFolder][] = [
            'name' => $name,
            'request' => $request,
        ];
    }
}

ksort($folders);

foreach ($folders as $folderName => $subFolders) {
    $subItems = [];
    foreach ($subFolders as $subFolderName => $requests) {
        $subItems[] = ['name' => $subFolderName, 'item' => $requests];
    }
    $items[] = ['name' => $folderName, 'item' => $subItems];
}

/** Auto-capture the token from a login/verify response into an environment variable. */
function loginRequest(string $name, string $method, string $uri, string $xApp, array $body, string $tokenVar): array
{
    return [
        'name' => $name,
        'event' => [[
            'listen' => 'test',
            'script' => [
                'type' => 'text/javascript',
                'exec' => [
                    'const json = pm.response.json();',
                    'if (json.success && json.data && json.data.token) {',
                    "    pm.environment.set('{$tokenVar}', json.data.token);",
                    "    console.log('Saved {$tokenVar}');",
                    '}',
                ],
            ],
        ]],
        'request' => [
            'method' => $method,
            'header' => [
                ['key' => 'Accept', 'value' => 'application/json'],
                ['key' => 'Content-Type', 'value' => 'application/json'],
                ['key' => 'Accept-Language', 'value' => 'ar'],
                ['key' => 'X-App', 'value' => $xApp],
            ],
            'url' => toPostmanUrl($uri),
            'body' => [
                'mode' => 'raw',
                'raw' => json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'options' => ['raw' => ['language' => 'json']],
            ],
        ],
    ];
}

$quickstart = [
    loginRequest('1. Send customer OTP', 'POST', 'api/v1/auth/otp/send', 'app', ['phone' => '01012345678'], 'customer_token'),
    [
        'name' => '2. Verify customer OTP (check storage/logs/laravel.log for the code)',
        'event' => [[
            'listen' => 'test',
            'script' => ['type' => 'text/javascript', 'exec' => [
                'const json = pm.response.json();',
                'if (json.success && json.data && json.data.token) { pm.environment.set(\'customer_token\', json.data.token); }',
            ]],
        ]],
        'request' => [
            'method' => 'POST',
            'header' => [
                ['key' => 'Accept', 'value' => 'application/json'],
                ['key' => 'Content-Type', 'value' => 'application/json'],
                ['key' => 'Accept-Language', 'value' => 'ar'],
                ['key' => 'X-App', 'value' => 'app'],
            ],
            'url' => toPostmanUrl('api/v1/auth/otp/verify'),
            'body' => [
                'mode' => 'raw',
                'raw' => json_encode(['phone' => '01012345678', 'code' => 'PASTE_CODE_FROM_LOG'], JSON_PRETTY_PRINT),
                'options' => ['raw' => ['language' => 'json']],
            ],
        ],
    ],
    loginRequest('3. Login as super_admin', 'POST', 'api/v1/auth/login', 'admin', ['login' => '01000000099', 'password' => 'Cr7Delivery#2026'], 'admin_token'),
    loginRequest('4. Login as driver (create one via admin first)', 'POST', 'api/v1/auth/login', 'app', ['login' => '01000000000', 'password' => 'PASTE_TEMP_PASSWORD'], 'driver_token'),
    loginRequest('5. Login as restaurant (create one via admin first)', 'POST', 'api/v1/auth/login', 'restaurant', ['login' => '01000000000', 'password' => 'PASTE_TEMP_PASSWORD'], 'restaurant_token'),
];

array_unshift($items, ['name' => '00 - Quickstart (run these first, top to bottom)', 'item' => $quickstart]);

$collection = [
    'info' => [
        'name' => 'CR7 Delivery API',
        'description' => 'Auto-generated from the live Laravel routes (doc 08 API Contract). Regenerate with php scripts/generate_postman.php.',
        'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
    ],
    'item' => $items,
];

file_put_contents(__DIR__.'/../postman/CR7-Delivery-API.postman_collection.json', json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$environment = [
    'name' => 'CR7 Delivery - Local',
    'values' => [
        ['key' => 'base_url', 'value' => 'http://127.0.0.1:8000/api/v1', 'enabled' => true],
        ['key' => 'customer_token', 'value' => '', 'enabled' => true],
        ['key' => 'driver_token', 'value' => '', 'enabled' => true],
        ['key' => 'restaurant_token', 'value' => '', 'enabled' => true],
        ['key' => 'admin_token', 'value' => '', 'enabled' => true],
    ],
];

file_put_contents(__DIR__.'/../postman/CR7-Delivery-Local.postman_environment.json', json_encode($environment, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$total = array_sum(array_map(fn ($sub) => array_sum(array_map('count', $sub)), $folders));
$subFolderCount = array_sum(array_map('count', $folders));
echo "Generated {$total} requests across ".count($folders)." folders / {$subFolderCount} sub-folders.\n";
