# CR7 Delivery — API (Laravel)

Read this before touching anything. It says what this service is, what talks to it, where every
route lives, and — at the end — the places the contract and the clients do not line up yet.

> **This repo is written and owned by the backend developer.** The Flutter app in
> `../cr7_delivery` is the consumer. Change the app to fit this API, not the other way round,
> unless the gap is listed in §9 and has been agreed.

---

## 0. Language

Reply to the user in Arabic. Untranslated: code, paths, route names, column names, error codes,
screen IDs (`CUS-14`, `DRV-08`, `RES-27`), decision IDs (`D-45`).

Arabic **inside the codebase** — validation messages, `ApiResponse::success(message:)` strings —
is Modern Standard Arabic (D-44). Not Egyptian colloquial: «سيتم إرسال» not «هيتبعت». See §9.6 for
the one place that rule is currently broken.

---

## 1. What this is

| | |
|---|---|
| Repo | `/Volumes/CrucialX9/development/cr7-delivery/` |
| Stack | PHP 8.2+ · Laravel 11 · Sanctum · `spatie/laravel-permission` · Scramble (OpenAPI) |
| Shape | A pure JSON API. `routes/web.php` is empty; everything is `routes/api.php`. |
| Base path | `/api/v1` — `routes/api.php` is mounted at `/api`, and everything sits inside `Route::prefix('v1')`. |
| Spec | `../New Figma App/docs/` — doc 08 is the API contract, doc 03 the ERD, doc 04 the order state machine. The route file's section comments (`§2 Public`, `§4 Customer`…) mirror doc 08 exactly. |

Four clients, one API:

| `X-App` | `users.type` it may carry | What it is |
|---|---|---|
| `app` | `customer` **or** `driver` | The one Flutter binary, two shells (D-45) |
| `restaurant` | `restaurant` | Restaurant dashboard (web, not built) |
| `admin` | `admin` | Admin dashboard (web, not built) |

---

## 2. Running it locally

`vendor/` and `.env` are not in the repo, so a fresh clone does not boot.

```bash
cp .env.example .env
composer install
php artisan key:generate
mysql -u root -e "CREATE DATABASE cr7_delivery CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan migrate --seed
php artisan serve                      # http://127.0.0.1:8000
```

**MySQL 8, never SQLite.** `CustomerRestaurantController::index`, `::products` and
`SearchController` all call `whereFullText(...)`, and the `fullText` indexes in
`0001_01_01_000021_create_restaurants_table.php` and `..._000025_create_catalog_tables.php` are
MySQL-only. `.env.example` points at MySQL for exactly this reason — do not put it back.

`.env.example` also sets `APP_TIMEZONE=Africa/Cairo`. Leaving it at UTC puts every `business_date`,
prep countdown and daily cap three hours out.

### What the seeders give you

`DatabaseSeeder` runs `Permissions → Roles → Settings → Location → AdminUser → Content → Catalog`:

| Seeder | What lands |
|---|---|
| `LocationSeeder` | جمصة + two zones (وسط جمصة ‪10‬ ج.م، الشاطئ ‪15‬ ج.م) |
| `SettingsSeeder` | every runtime knob, `firstOrCreate` so a live edit survives a re-run |
| `AdminUserSeeder` | `01000000099` / `Cr7Delivery#2026`, `super_admin`, global scope |
| `ContentSeeder` | `terms` · `privacy` · `about` and ‪9‬ FAQs — without these the legal screens 404 |
| **`CatalogSeeder`** | **doc 17's real catalogue: 8 restaurants, 69 categories, 562 products, 138 option groups, cuisines, banners, `WELCOME20` + `FREEDEL`** |

The catalogue data is `database/data/catalog.json`, generated from the same source the Figma boards
were built from, and the imagery is committed in **`public/catalog/`** — web-served directly, so it
needs no `storage:link` and no upload step. Image URLs are built with `asset()` **at seed time**,
so seed on the host whose `APP_URL` you want baked in (uploads through `FileUploader` behave the
same way).

Every restaurant gets an owner login: the phone in `database/data/catalog.json`,
password `Cr7Delivery#2026`, `must_change_password` on.

`CatalogSeeder` is idempotent — `updateOrCreate` throughout — so re-running it refreshes the menu
rather than duplicating it.

`postman/CR7-Delivery-API.postman_collection.json` covers all 315 routes with real request bodies;
`postman/CR7-Delivery-Local.postman_environment.json` and `…-Production.postman_environment.json`
hold `base_url`. The «00 - Quickstart» folder logs you in as each role and stores the token. OTP
codes are written to `storage/logs/laravel.log` by `App\Services\Otp\LogOtpChannel` — there is no
SMS provider wired. Regenerate the collection with `php scripts/generate_postman.php`.

### Tests

`php artisan test` — the suite runs against MySQL too, `cr7_delivery_test`:

```bash
mysql -u root -e "CREATE DATABASE cr7_delivery_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan test
```

> 🔴 **One database, one runner.** `phpunit.xml` pins `DB_DATABASE=cr7_delivery_test`, and
> `RefreshDatabase` truncates it between tests — so a second `php artisan test` running at the
> same time does not merely interfere, it produces dozens of `DeadlockException`s in suites that
> have nothing to do with each other, and the failures move around between runs. If you need a
> concurrent run, give it its own database and drop it afterwards:
>
> ```bash
> mysql -u root -e "CREATE DATABASE cr7_delivery_test_mine CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
> DB_DATABASE=cr7_delivery_test_mine php artisan test
> mysql -u root -e "DROP DATABASE cr7_delivery_test_mine;"
> ```
>
> This cost real time on 2026-09-08: two parallel runs reported 162 failures on a suite that is
> green, and the run before it 138 — all contention, none of it code.

`tests/Feature/PlatformFeeTest.php` is the money one — it is what stops the service fee drifting
into the delivery fee, the discount, the captain's earning or the restaurant's payout.
`tests/Feature/CatalogSeedTest.php` asserts doc 17's price bands come back unchanged, and that
**every seeded image resolves to a file that exists** — which is what catches a catalogue row
pointing at artwork nobody generated.

---

## 3. The response envelope

`App\Support\ApiResponse` is the only thing that builds a response body. Every controller returns
through it.

```jsonc
// success
{ "success": true, "data": …, "message": "…", "meta": { … } }

// error
{ "success": false, "error": "promo_expired", "message": "…", "errors": { "field": ["…"] } }
```

`array_filter` strips every null key, so **`data`, `message`, `meta` and `errors` are absent rather
than null** when empty. A client reading `body['data']` must tolerate its absence.

`message` is always run through `__()`. `lang/en.json` is string-keyed by the exact Arabic sentence,
so call sites keep writing Arabic and `SetLocaleFromHeader` (prepended to the `api` group) swaps it
when `Accept-Language: en` arrives.

Exception → envelope mapping lives in `bootstrap/app.php`:

| Thrown | `error` | Status |
|---|---|---|
| `App\Exceptions\ApiException` | its own code | its own status |
| `ValidationException` | `validation_failed` | 422 |
| `AuthenticationException` | `unauthenticated` | 401 |
| `PermissionUnauthorizedException` / `AuthorizationException` | `forbidden` | 403 |
| `ModelNotFoundException` / `NotFoundHttpException` | `not_found` | 404 |

---

## 4. Auth and the three middlewares

`POST /auth/otp/send` → `POST /auth/otp/verify` is the **customer** path; the customer never has a
password (FR-AUTH-01/02/03). `SendOtpAction`: 6 digits, 5-minute validity, 60-second resend
cooldown (`429 otp_resend_cooldown`), 5 sends per hour per phone (`429 otp_send_rate_limited`).
The send response carries **only a message** — no expiry, no resend window — so a client counting
down has to hold those constants itself.

`POST /auth/login` is the **one** password endpoint for driver, restaurant and admin.
`PasswordAuthController` reads `X-App` to decide which `users.type` to expect, so a driver signing
in sends `X-App: app` exactly like a customer.

| Alias | Class | What it does |
|---|---|---|
| `client.type` | `EnsureClientMatchesUserType` | `X-App` must match `users.type`. **Route-level only** — it reads `$request->user()`, so it must run after `auth:sanctum`; global API middleware always runs first and would see a null user. |
| `type:customer` | `EnsureUserType` | Narrows the shared `app` binary to one type. |
| `permission:…` | Spatie | Admin routes only. |

Geographic scope (`App\Support\AdminScope`, D-50…D-52) is enforced **inside** each admin
controller, not by middleware. Out of scope resolves as **404**, never 403.

### Sessions — D-120 (doc 64)

A sign-in that sends **`X-Auth-Refresh: 1`** gets a session: a Sanctum access token with its own
`expires_at` (`config('cr7_auth.access_minutes')`, 60) plus an opaque refresh token
(`refresh_tokens`, sha256). Without the header it gets exactly the pre-D-120 `{user, token}` and a
**legacy** token (`expires_at` null) — the 1.0.3/1.0.4 apps depend on that. A legacy token now lives
while it is used: `last_used_at` must be inside the idle window (`Sessions::accepts`, registered with
`Sanctum::authenticateAccessTokensUsing`). **Keep `sanctum.expiration` null** — it would cut every
legacy token at a fixed age.

| Route | Auth | Answers |
|---|---|---|
| `POST /auth/refresh` `{refresh_token}` | public, `throttle:auth-refresh` | session block · 401 `session_expired` · 423 `account_suspended` · 429 `refresh_rate_limited` |
| `POST /auth/session/upgrade` | legacy bearer | session block · 409 `session_already_refreshable` |

- **Only `App\Support\Auth\SessionIssuer` issues a session**, and **only `App\Support\Auth\Sessions`
  ends one.** Never `->tokens()->delete()` again: it leaves the refresh tokens alive and the next
  renewal brings the session back.
- Rotation is single-use with a ‪120‬ s replay window (the same pair again), lost-response recovery
  (successor's refresh token never presented → reissued) and theft detection (successor renewed →
  the whole family revoked). Never decide on the successor's access token: the dashboards render with
  it server-side whether or not the browser stored the new cookie.
- Idle windows are on `ADM-46`: `auth.session_days` (‪60‬) and `auth.admin_session_days` (‪14‬).
- `cr7:prune-sessions` (hourly) and `sanctum:prune-expired` (daily) clean both tables.

---

## 5. Layout

```
app/
  Actions/{Admin,Auth,Customer,Driver,Restaurant}/   one class, one `execute()`, all writes
  Enums/                                             every wire vocabulary
  Http/
    Controllers/Api/{Auth,Shared,Customer,Driver,Restaurant,Admin}/
    Middleware/                                      the three above
    Requests/{Auth,Shared,Customer,Driver,Restaurant,Admin}/
    Resources/                                       only 10 — see §6
  Models/                                            50 Eloquent models
  Support/                                           ApiResponse, CheckoutPricer, CartFormatter,
                                                     DispatchService, AdminScope, AuditLogger, Geo…
```

Controllers validate and respond. **Anything that writes more than one row is an Action** —
`CreateOrderAction`, `AcceptOfferAction`, `DeliverOrderAction`. Pricing is never duplicated:
`CheckoutPricer::price` serves both `POST /checkout/quote` and `CreateOrderAction`, so the number
quoted is the number charged.

---

## 6. Resources — and where they are not

There are only **10** API Resources. Everything else returns a **raw Eloquent model**, which means
both language columns land on the wire and the client picks:

| Endpoint | Shape |
|---|---|
| `/cities`, `/cities/{id}/zones`, `/cuisines` | raw — `name_ar` **and** `name_en` |
| `/banners` | raw — `title_ar`/`title_en`, `image_ar`/`image_en`, `link_type`, `link_value` |
| `/faqs`, `/pages/{slug}` | raw — `question_ar`/`question_en`, `body_ar`/`body_en` |
| `/addresses` | raw `UserAddress` — `city_id`/`service_zone_id`, relations **not** loaded |
| `/notifications` | raw — `type`, `title_key`, `payload` json, `deep_link`; **no rendered title or body** |
| `/complaints` | raw `Complaint` + `messages` filtered to `visibility = all` |
| `/driver/me`, `/driver/orders/*`, `/driver/documents`, `/driver/settlements` | raw models |

`Accept-Language` does **not** narrow a raw model. Only the ten Resources localise, via
`App\Support\Locale::current($request)`.

The localised ten: `UserResource`, `Customer/{RestaurantResource, RestaurantDetailResource,
ProductResource, ProductDetailResource, OrderGroupListResource, OrderGroupDetailResource,
OrderGroupCreatedResource}`, `Restaurant/ComplaintResource`, `Admin/OrderResource`.

Pagination is hand-rolled — `ApiResponse::success($paginator->items(), meta: [...])` with
`current_page`, `per_page`, `total`, `last_page`. It is **not** Laravel's `links`/`meta` envelope.

---

## 7. The rules that bite

1. **`Idempotency-Key` is mandatory on `POST /orders`.** `CreateOrderRequest::idempotencyKey()`
   throws 422 without the header, and `orders.idempotency_key` is uniquely indexed.
2. **Payment comes after the accept** (D-36…D-39). Every order starts `pending`; the payment gate
   opens only once every restaurant has answered. `GET /orders/{id}/payment` answers
   `{gate_open: false}` until then, and `POST /orders/{id}/payment/method` only ever switches
   **to cash**.
3. **`POST /promo/validate` is strict** — it throws `ApiException`, it never returns
   `valid: false`. Only `CheckoutPricer::price` returns a soft `promo.valid`.
4. **One delivery fee per group**, split pro-rata onto sub-orders, remainder to the largest
   subtotal (`CheckoutPricer::assignRoundingRemainder`).
5. **`{orderGroup}` vs `{order}`.** Every customer route takes an `order_groups.id`. Every driver
   and restaurant route takes an `orders.id` — a sub-order. `complaints.order_id` is a sub-order
   too, so a client holding a group id has to resolve one.
6. **`POST /driver/location` accepts a single point or a list** (`array_is_list`), each with its
   own `recorded_at`.
7. **Multipart needs `_method` spoofing.** `PATCH /auth/me` with an avatar must be sent as `POST`
   with `_method=PATCH` — Laravel does not parse a multipart body off a real PATCH.
8. **`ChangePasswordRequest` uses `confirmed`**, so `password_confirmation` is required.
9. **Private disk.** Payment proofs and driver documents go through `FileUploader::storePrivate`
   and are never public URLs (doc 12 §5).

---

## 8. Things worth knowing before changing them

- `Setting` rows are the runtime knobs (`SettingsSeeder`, doc 03 §9). `GET /config` exposes only a
  curated subset — force-update, maintenance, support, payment, currency, cart caps, order statuses.
- `RollupStatus::compute` derives a group's status from its sub-orders. Nothing writes
  `order_groups.rollup_status` directly.
- `AuditLogger::record` on every admin write (D-52), plus an `order_status_history` row the
  customer reads as «تدخّلت الإدارة».
- Complaints are two-sided (D-53): `visible_to_restaurant` auto-mirrors for
  `food_quality`, `missing_item`, `order_issue` only, and `visibility = internal` messages never
  leave the platform.

---

## 9. What changed on 2026-08-28, and what is still open

The client gaps found while wiring `../cr7_delivery` are **closed**. What follows is what moved and
what did not.

### D-63…D-65 — the platform service fee («رسوم الخدمة»)

A fixed amount the platform charges the customer on top of the food and the delivery fee.

```
total = subtotal − discount_amount + delivery_fee + platform_fee
```

* Computed in **`App\Support\CheckoutPricer` and nowhere else**, which is what guarantees the
  quoted number is the charged number.
* **Once per `order_group`** — three restaurants pay ‪5‬, not ‪15‬, exactly like the delivery fee
  under D-30…D-35. Split pro-rata onto sub-orders through the existing
  `assignRoundingRemainder`, so the shares add back to the group figure exactly.
* Never touched by a discount, by a `free_delivery` promo, or by `free_delivery_over`.
* Snapshotted on `order_groups.platform_fee` / `orders.platform_fee_share`. Changing
  `platform.order_fee` afterwards cannot re-price a placed order.
* **Not** in `driver_earning` and **not** in a settlement's `net_amount` — reported on
  `settlements.platform_fee_total` and `settlement_items.platform_fee`, never paid out.
* **D-64:** direct orders carry it too (`platform.order_fee_applies_to_direct`, default true).
* **D-65:** `App\Support\RefundPolicy` — refunded on platform failure, not on a customer
  cancellation. It computes what an admin is *shown*; it never moves money by itself.

Settings: `platform.order_fee` (5.00) · `platform.order_fee_applies_to_direct` ·
`platform.order_fee_refundable_on_platform_failure`.

### Everything else that landed

| Was missing | Now |
|---|---|
| catalogue seed | `CatalogSeeder` + `ContentSeeder`, doc 17 in full |
| captain applications | `captain_applications` table, `POST /captain-applications` (public), `GET/POST /admin/captain-applications*` — approving one creates the account through `CreateDriverAction` (D-45) |
| notification preferences | `users.notify_orders` / `notify_captain` / `notify_promos` + `GET/PATCH /auth/me/notification-settings` |
| `users.cash_only_until` | on `UserResource` |
| `ProductResource` restaurant | `restaurant_id` + `restaurant_name` |
| `delivery_otp` | on `OrderGroupDetailResource`, **only** for sub-orders past `picked_up` (doc 04 §11.3) |
| `confirm_deadline_at` | on `OrderGroupDetailResource`, so `CUS-32` needs no second request that throws by design |
| `restaurants.price_band` | derived, D-60 — `App\Support\PriceBand` (median base price, cuts at ‪75‬ / ‪130‬), recomputed by `ProductObserver`; backfill with `php artisan restaurants:recompute-price-bands` |
| `restaurants.tagline_*`, `menu_sheets`, `brand_color` | columns + resources |
| `drivers.online_since` | set on the online transition only, so a reconnect does not restart the shift |
| customer name for the captain | `orders.customer_name_snapshot` |
| bulk products | `GET /products?ids=1,2,3` |
| `/driver/orders/{id}`, `/driver/earnings/{id}` | added |
| catalogue sorts | `nearest` (needs `lat`/`lng`), `fastest`, `-orders_count`, `filter[price_band]`, `filter[max_delivery_fee]` |
| overnight opening hours | `Restaurant::isOpenNow()` reads `closes_at < opens_at` as one window that crosses midnight |
| colloquial string | `OrderGroupCreatedResource` note is MSA |

### Still open

1. **No SMS or push provider.** `LogOtpChannel` writes codes to the log; `/devices` stores tokens
   nobody sends to. Wiring FCM and an SMS gateway is its own job.
2. **Realtime.** Reverb is in the stack but nothing broadcasts; the client polls.
3. **`name_en` on most dishes is the Arabic string.** Doc 17 publishes Latin names for the brands
   and for ~115 descriptions, not for ~450 individual dishes. An invented transliteration would be
   worse than the name the restaurant actually uses; `Accept-Language: en` shows the Arabic.
4. **صواني الدمياطي has no logo** (doc 17 Q-01) and its name is a working name.
5. **Per-sub-order cancel.** `POST /orders/{group}/cancel` cancels what it can across the group;
   there is no partial endpoint, and the client refuses a partial selection rather than sending one
   that would be ignored.
