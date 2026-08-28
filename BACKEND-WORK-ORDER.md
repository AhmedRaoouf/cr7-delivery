# CR7 Delivery — API changes, 2026-08-28

**For:** the API developer · **Against:** `cr7-delivery` @ `9debd6d`

This is a hand-over, not a request. Everything described here is **implemented, migrated, seeded
and tested** on this branch. Read it, disagree with anything you want to disagree with, then
deploy.

Two things came in together:

- **Part A** — a new money rule the operator asked for: a fixed **‪5‬ ج.م platform fee on every
  order**, on top of the food and on top of the delivery fee.
- **Part B** — the gaps the Flutter client hit while being wired against the API as built. All of
  them are closed. This half is mostly one-line resource additions and four small routes.
- **Part C** — what a fresh clone needed before it could serve a single screen, and what it needs
  now.
- **Part D** — maps and verification codes: the two things that stood between this API and a
  demoable app. Both are now **server settings, not build constants**.

`php artisan test` → **47 tests, 174 assertions, green.** Verified on PHP 8.5 + MySQL 8.
Deployment steps are in **`DEPLOY.md`**, which is the file to follow rather than the summary below.

---

# Part A — the platform service fee

## A.1 What it is

A fixed fee the platform charges the customer on every order, which belongs to the platform owner.
It is **not** the delivery fee (that funds the captain) and **not** the commission (that comes out
of the restaurant's food revenue). It is a third, separate line.

```
total = subtotal − discount_amount + delivery_fee + platform_fee
```

> **On the name.** The operator described this as «ضريبة». It is not a tax: there is no tax
> authority behind it and Egyptian VAT is a registered 14 %. Printing «ضريبة» on a customer receipt
> for a platform charge is inaccurate and becomes a problem the day anyone audits it. The schema
> and the API say **`platform_fee`**; the customer sees **«رسوم الخدمة»**. If a real VAT line is
> wanted later it is a different field with a different rule.

## A.2 The rules, and where each one lives

| # | Rule | Enforced in |
|---|---|---|
| 1 | **Once per `order_group`, never per sub-order.** Three restaurants pay ‪5‬, not ‪15‬. | `CheckoutPricer::price()` — one read, outside the per-restaurant map |
| 2 | Split pro-rata onto sub-orders, remainder to the largest subtotal | `assignRoundingRemainder($groups, 'platform_fee_share', …)` |
| 3 | A discount never reduces it | it is added after `$discountAmount`, never inside the promo branch |
| 4 | A `free_delivery` promo does not waive it | that branch sets `$deliveryFee = 0` and touches nothing else |
| 5 | `free_delivery_over` does not waive it | same |
| 6 | Snapshotted on the order | `order_groups.platform_fee`, `orders.platform_fee_share` |
| 7 | Never in `driver_earning` | `DriverEarningCalculator` is **unchanged** — it reads `delivery_fee_share` |
| 8 | Never in a restaurant's settlement `net` | `GenerateSettlementAction` reports it, `$netAmount` unchanged |
| 9 | On a cash order the captain collects it and owes it through `cash_in_hand` | already true — it is inside `orders.total` |
| 10 | Configurable, never hard-coded | `settings.platform.order_fee` |

Rule 1 is the load-bearing one. It is the same rule the delivery fee already follows (D-30…D-35):
charging per restaurant would make a three-restaurant basket silently ‪3‬× more expensive, which is
the exact thing D-30 exists to prevent.

## A.3 The two decisions, and how they were answered

Both are settings, so either can be flipped without a deploy.

**D-64 — does the fee apply to direct orders (`origin = restaurant_direct`)? → yes.**
A direct order is a phone order the app never saw: `subtotal = 0`, zero commission, the customer
pays cash to the captain (D-47…D-49). The platform is still selling a delivery, and that is exactly
what the fee pays for. `total = delivery_fee + platform_fee`, and the captain collects both.
The alternative — exempting them — gives a restaurant a real incentive to route volume through the
phone at ‪20‬ orders/day/restaurant. Setting: `platform.order_fee_applies_to_direct` (default `true`).

**D-65 — is it refunded when an order fails? → on platform failure, yes; on customer cancellation, no.**
Every restaurant rejected, no captain found, `restaurant_timeout`, an admin cancellation: the
platform did not deliver the service, so keeping the fee is indefensible. A customer who cancels an
order they already confirmed does not get it back. `App\Support\RefundPolicy` owns the judgement and
is used by `AdminCancelOrderAction` and surfaced (as a hint, never an override) on
`POST /admin/refunds` and `GET /admin/orders/{id}`. Setting:
`platform.order_fee_refundable_on_platform_failure` (default `true`).

## A.4 Schema

`database/migrations/2026_08_28_100000_add_platform_fee_columns.php`:

| Table | Column |
|---|---|
| `order_groups` | `platform_fee` `decimal(12,2)` default 0 |
| `orders` | `platform_fee_share` `decimal(12,2)` default 0 |
| `settlements` | `platform_fee_total` `decimal(12,2)` default 0 |
| `settlement_items` | `platform_fee` `decimal(12,2)` default 0 |

All four are additive with defaults, so existing rows read as ‪0‬ and nothing back-fills.

## A.5 Settings

`SettingsSeeder`, group `platform`, seeded with `firstOrCreate` like everything else there:

```php
'platform.order_fee'                                => [5.00, 'platform'],
'platform.order_fee_applies_to_direct'              => [true, 'platform'],   // D-64
'platform.order_fee_refundable_on_platform_failure' => [true, 'platform'],   // D-65
```

## A.6 Files touched

| File | Change |
|---|---|
| `app/Support/CheckoutPricer.php` | **the only place the fee is computed.** Reads the setting, zero on an empty basket, adds `platform_fee_share` to each group, adds `platform_fee` and the new `total` to the return |
| `app/Actions/Customer/CreateOrderAction.php` | writes both snapshots; the sub-order `total` now sums four terms |
| `app/Actions/Restaurant/QuoteDirectOrderAction.php` | returns `platform_fee` and `total` (D-64) |
| `app/Actions/Restaurant/CreateDirectOrderAction.php` | writes them onto the group and the single order |
| `app/Support/DriverEarningCalculator.php` | **unchanged, deliberately** |
| `app/Actions/Admin/GenerateSettlementAction.php` | `platform_fee_total` + per-item `platform_fee`; `net_amount` untouched |
| `app/Http/Controllers/Api/Admin/ReportController.php` | `SUM(platform_fee_share) as platform_fees` — scoped and date-filtered by the same `AdminScope` and range as every other figure |
| `app/Http/Controllers/Api/Admin/DashboardController.php` | `platform_fees_total` **and** `platform_revenue_total` (commission + fees), because platform revenue is now two numbers |
| `app/Http/Resources/Customer/OrderGroupCreatedResource.php` | `platform_fee`, `platform_fee_share` |
| `app/Http/Resources/Customer/OrderGroupDetailResource.php` | same — this is what the receipt renders |
| `app/Http/Controllers/Api/Shared/ConfigController.php` | `platform.order_fee` + both labels, so the basket can print the line before it has a quote |
| `app/Support/RefundPolicy.php` | **new** — D-65 |
| `app/Models/{OrderGroup,Order,Settlement,SettlementItem}.php` | `$fillable` + `decimal:2` casts |

## A.7 What the wire looks like now

`POST /checkout/quote`:

```jsonc
{
  "subtotal": 250.00,
  "discount_amount": 15.00,
  "delivery_fee": 20.00,
  "platform_fee": 5.00,          // NEW — once, whatever the basket spans
  "total": 260.00,               // 250 − 15 + 20 + 5
  "groups": [
    { "restaurant_id": 3, "subtotal": 250.00, "discount_share": 15.00,
      "delivery_fee_share": 20.00, "platform_fee_share": 5.00 }   // NEW
  ]
}
```

`POST /orders` (201) and `GET /orders/{orderGroup}` gain the same two keys.
`GET /config` gains `platform.order_fee`.
`GET /admin/reports/sales` gains `platform_fees` per day.
`GET /admin/dashboard` gains `platform_fees_total` and `platform_revenue_total`.

## A.8 Customer-facing copy

MSA only (D-44), western digits inside an LTR isolate (doc 13 §4):

| | AR | EN |
|---|---|---|
| the line | `رسوم الخدمة` | `Service fee` |
| the hint | `رسوم ثابتة على كل طلب` | `A fixed fee on every order` |

It renders as **its own line** on the basket, the checkout summary, the confirmation screen and the
receipt — never folded into the delivery fee. A customer who compares the delivery fee they were
quoted against the total reads a merged fee as an overcharge, and that is a support ticket every
single order.

## A.9 Tests

`tests/Feature/PlatformFeeTest.php` — 14 tests:

1. a single-restaurant quote returns ‪5‬ and a total that includes it
2. **a three-restaurant quote returns ‪5‬, not ‪15‬, and the shares sum to exactly ‪5.00‬**
3. a `percent` promo does not reduce it
4. a `free_delivery` promo zeroes delivery and leaves it
5. `free_delivery_over` does not waive it
6. an empty cart carries none (and still emits `cart_empty`)
7. changing the setting to ‪7‬ does not re-price an order placed at ‪5‬
8. the sub-order totals reconstruct the group total exactly
9. `driver_earning` is the delivery share and nothing else
10. platform failure refunds it; a customer cancellation does not
11. a settlement reports it and `net_amount` is unchanged
12. `GET /config` publishes it
13. a direct order carries it, and the setting turns it off
14. the created-order resource shows it as its own line, in MSA

---

# Part B — the client gaps, closed

Found wiring `cr7_delivery` against the API. Ordered by how much they cost the product.

| # | Was | Now |
|---|---|---|
| 1 | **No captain-application route.** `APP-03` was on mock data, so nothing was ever received. | `captain_applications` table · `POST /captain-applications` (public — an applicant has no account) · `GET /admin/captain-applications[/{id}]` · `POST /admin/captain-applications/{id}/review`. Approving one runs `CreateDriverAction` and returns the temp password once. Reference format `CAP-YYMMDD-NNNN`, matching Figma `894:5150`. Scoped by the applicant's city; permissions `driver_applications.view` / `.review` already existed. **D-45 holds: the screen files an application, an admin creates the account.** |
| 2 | `ProductResource` had no `restaurant_id`, so a `/search` hit could not name or open its restaurant. | `restaurant_id` + `restaurant_name` (the relation was already eager-loaded). |
| 3 | No customer-facing `delivery_otp`, so `CUS-30d` could not show the delivery code. | On `OrderGroupDetailResource`, for sub-orders at `picked_up`/`on_the_way`/`arrived_customer` **only** (doc 04 §11.3). Earlier states return `null`; a direct order never has one (D-49). |
| 4 | `confirm_deadline_at` was only on `/orders/{id}/confirmation`, which throws unless the gate is open — so `CUS-32`'s countdown needed a request that fails by design. | On `OrderGroupDetailResource`, with `confirmation_opened_at`. |
| 5 | `users.cash_only_until` was not exposed, so `CUS-26` found out from a blocker at the end. | On `UserResource`. |
| 6 | No notification-preferences route; `CUS-44`'s toggles lived on the device and were lost on reinstall. | `users.notify_orders` / `notify_captain` / `notify_promos` (doc 11 §7's own categories; promos defaults **off**) + `GET/PATCH /auth/me/notification-settings`, and on `UserResource` as `notification_settings`. |
| 7 | `restaurants` had no `price_band`, so `CUS-12`'s price filter could not work. | `price_band` enum, **derived not declared** (D-60): `App\Support\PriceBand` takes the median base price of the restaurant's available rows and buckets at ‪75‬ / ‪130‬ ج.م. `ProductObserver` recomputes on any price/availability change; `php artisan restaurants:recompute-price-bands` backfills. A restaurant with no menu carries `null` and answers no chip. The eight seeded brands come back with exactly the bands doc 17 §3b measured. |
| 8 | No `tagline`, `menu_sheets` or `brand_color`. | `tagline_ar` / `tagline_en` / `menu_sheets` (json) / `brand_color`, on both restaurant resources. |
| 9 | `drivers` had no `online_since`, so `DRV-07`'s "online for" timer started from whenever the app last read. | `drivers.online_since`, set on the **transition** only — a reconnect that re-sends `is_online: true` does not restart the shift. |
| 10 | No buyer name on any driver-facing resource, so `DRV-13` had an address and no name. | `orders.customer_name_snapshot`, written by `CreateOrderAction`. |
| 11 | No bulk product route — a restored basket cost one request per line. | `GET /products?ids=1,2,3` (capped at 100; unavailable rows are returned flagged, not 404'd, so the basket can say which line went away). |
| 12 | No `/driver/orders/{id}` or `/driver/earnings/{id}`. | Both added, ownership-checked. |
| 13 | Catalogue sorting was `-rating_avg` only, and there was no max-delivery-fee filter. | `sort=nearest` (correlated haversine; falls back to the default when no `lat`/`lng` is given rather than silently ordering by something else), `sort=fastest` (correlated subquery on the zone's average — a join makes `is_active` ambiguous), `sort=-orders_count`, `filter[price_band]`, `filter[max_delivery_fee]`. |
| 14 | `Restaurant::isOpenNow()` compared `opens_at`/`closes_at` as a plain range, so a kitchen open ‪10:00‬–‪02:00‬ read as closed all evening. | A shift that closes before it opens is read as one window that crosses midnight. |
| 15 | `OrderGroupCreatedResource` carried `'المبلغ بيتحدد بعد ما كل المطاعم ترد'` — Egyptian colloquial, against D-44. | `'يُحدَّد المبلغ بعد أن ترد كل المطاعم'`. |

---

# Part C — what a fresh clone needed

1. **`.env.example` defaulted to SQLite, and the catalogue cannot run on SQLite.** Three customer
   endpoints call `whereFullText(...)` against MySQL-only indexes. It now points at MySQL 8, and
   `phpunit.xml` does too (`cr7_delivery_test`). It also sets `APP_TIMEZONE=Africa/Cairo` — UTC put
   every `business_date`, prep countdown and daily cap three hours out.
2. **Nothing seeded the catalogue**, so a migrated database served an empty home screen and nothing
   downstream of a product could be exercised at all. `CatalogSeeder` now seeds doc 17 in full:
   **8 restaurants · 69 categories · 562 products · 138 option groups · 15 cuisines · 3 banners ·
   2 promo codes**, from `database/data/catalog.json`, with the imagery committed in
   `public/catalog/` (web-served, no `storage:link`, no upload step). It is idempotent.
3. **`/pages/{slug}` 404'd** for `terms`, `privacy` and `about`. `ContentSeeder` adds all three
   plus ‪9‬ FAQs. The legal copy is a working draft — **have it reviewed before launch**; the
   cancellation and payment paragraphs must match whatever is finally agreed.
4. `scripts/generate_postman.php` broke on PHP 8.5 (a deprecation notice from Laravel's own
   `config/database.php` was prepended to the route JSON). Fixed, and the collection is regenerated
   at **315 requests**. A production environment file is in `postman/` alongside the local one.

---

# Part D — maps and verification codes

Full account with the reasoning: spec repo `docs/29-maps-and-otp-2026-08-28.md`. Decisions **D-66**
and **D-67**.

## D.1 The map is a server setting

`GET /config` now carries a `maps` block: the tile URL template with the **public** Mapbox token in
it, `tile_size`, `zoom_offset`, the attribution the provider's terms require, whether geocoding will
answer, and `invert_dark`.

The device fetches tiles directly — proxying every pan through PHP is an outage, not a design — so
the public token has to reach the app. Serving it from `/config` instead of compiling it into the
binary is what makes rotating it a `.env` edit rather than a store release, and what lets the app
ship today drawing OpenStreetMap and start drawing Mapbox the day a token exists.

```dotenv
MAPS_PROVIDER=mapbox
MAPBOX_TOKEN=pk.…            # PUBLIC token only. Never an sk. token.
MAPBOX_STYLE_LIGHT=mapbox/streets-v12
MAPBOX_STYLE_DARK=mapbox/dark-v11
```

Leave `MAPBOX_TOKEN` empty and the same block describes OpenStreetMap. Every screen keeps working;
street labels go missing. There is no failure path where the app draws a grey rectangle.

Three fields are worth knowing about:

* **`tile_size: 512` + `zoom_offset: -1`** — one 512 px tile covers four 256 px ones and bills as
  **one** request, so the ‪200 000‬/month free tier goes four times as far. The offset is what keeps
  the zoom scale lined up at that size; a client that guesses draws the town one level out.
* **`invert_dark`** — OSM ships one light tile set, which the app inverts on the luminance. Mapbox
  ships a real `dark-v11`. Inverting a dark style returns it to **white**, so the server states
  which it just handed over.
* **`attribution`** — required by both providers' terms, and it follows the provider so switching
  back to OSM does not leave the map crediting Mapbox.

## D.2 Geocoding is proxied and cached hard

| Route | Answers |
|---|---|
| `GET /geo/reverse?lat=&lng=` | `{address, place, neighborhood, city}` |
| `GET /geo/search?q=&lat=&lng=` | candidate places, `proximity`-biased to جمصة, `country=eg` |

Both public, both `throttle:60,1` — an unthrottled public route in front of a billed API is a free
way for anyone to spend the month's quota.

**The cache is the load-bearing part.** The address picker fires a camera event every frame of a
pan. Answers are cached ‪30‬ days on coordinates rounded to **4 decimals (~‪11‬ m)**, and the client
waits ‪700‬ ms of stillness before asking. A pin dragged across a block is one request, not one per
frame.

Nothing in this path throws. No token → `NullGeocoder` answers `null`. A 502 from Mapbox → `null`.
The coverage verdict on the picker comes from **our own** zone polygons, so a missing street name
costs the customer nothing.

## D.3 One switch mints every code — and it is dangerous

`random_int` was written out at **seven** call sites: the login code, the pickup code, the delivery
code, both admin captain-swap verbs, the direct-order pickup code and the admin force-pickup. All
seven now go through `App\Support\Otp`. Seven copies of a rule is seven chances to leave one live.

```dotenv
OTP_STATIC_MODE=true
OTP_STATIC_CODE=123456        # 6 digits, FR-AUTH-02
OTP_STATIC_ORDER_CODE=1234    # 4 digits, doc 04
OTP_ECHO_IN_RESPONSE=true
```

> ### ⚠️ While `OTP_STATIC_MODE=true`, every phone number signs in with the same code.
>
> Correct on a private staging server with no SMS gateway — the alternative is an app nobody can log
> into. **A full account takeover on a public one.**
>
> Turning it off: write an `OtpChannel` for the SMS provider, bind it in `AppServiceProvider` in
> place of `LogOtpChannel`, set `OTP_STATIC_MODE=false`, `config:cache`. **No client release** — the
> app hides the «وضع التجربة» note and stops prefilling the moment the server stops publishing a
> code.
>
> While it is on, `AppServiceProvider::boot()` logs a warning on every production boot. That line is
> the reminder; do not filter it out.

`POST /auth/otp/send` returns `data.static_otp`, and `/config` carries the same under
`auth.static_otp`. `Otp::echoable()` checks **staticness first and the flag second** — a *random*
code returned to whoever asked for it is not a second factor, so `OTP_ECHO_IN_RESPONSE=true` alone
can never produce one. `Otp::pad()` forces the configured value to the right length, because a
misconfigured `OTP_STATIC_CODE=12` would otherwise sail past `digits:6` on the way back in and lock
everyone out of an account nobody could report as broken.

## D.4 Files

**New:** `config/maps.php` · `config/otp.php` · `app/Support/Otp.php` · `app/Support/MapStyles.php` ·
`app/Services/Maps/{Geocoder,MapboxGeocoder,NullGeocoder}.php` · `tests/Feature/MapsAndOtpTest.php`
(‪17‬ tests) · `DEPLOY.md`.

**Changed:** `ConfigController` (the `maps` and `auth` blocks) · `GeoController` (`reverse`,
`search`) · `CustomerAuthController` (the echo) · `AppServiceProvider` (the geocoder binding, the
production warning) · `SendOtpAction` and the six order-code call sites · `routes/api.php` ·
`.env.example`.

---

## Deploying

**Follow `DEPLOY.md`.** The short version:

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --force            # idempotent; refreshes the catalogue, never duplicates
php artisan restaurants:recompute-price-bands
php artisan config:cache && php artisan route:cache
```

Seed on the host whose `APP_URL` you want in the image URLs — `CatalogSeeder` builds them with
`asset()` at seed time, the same way `FileUploader` does for uploads.

Then change `SEED_ADMIN_PASSWORD` and the seeded restaurant owner passwords. Every seeded account
ships with `must_change_password = true`, but the default is in this file and in the repo.

## Still open, and named

1. **No SMS and no push provider.** `LogOtpChannel` writes OTPs to `storage/logs/laravel.log`;
   `/devices` stores tokens nothing sends to. Static OTP mode (Part D.3) makes the app usable in the
   meantime — it is **not** a substitute for the gateway, and it must be switched off the day one
   exists. Wiring FCM and an SMS gateway is the last thing between this API and a real launch.
2. **Realtime.** Reverb is in the stack; nothing broadcasts. The client polls.
3. **`name_en` on most dishes is the Arabic string.** Doc 17 publishes Latin names for the brands
   and for ~‪115‬ descriptions, not for ~‪450‬ individual dishes. An invented transliteration is
   worse than the name the restaurant actually uses.
4. **صواني الدمياطي has no logo and its name is a working name** (doc 17 Q-01).
5. **No turn-by-turn inside the app.** The captain hands the destination to Google Maps or Apple
   Maps, and the customer's tracking screen draws a straight line from the captain to the door
   rather than a routed path — a fake road is a promise about a street the captain may not be on.
   Mapbox Directions is one endpoint away if the operator wants the real line.
6. **No per-sub-order cancel.** `POST /orders/{group}/cancel` cancels what it can across the whole
   group; the client refuses a partial selection rather than sending one that would be ignored.
