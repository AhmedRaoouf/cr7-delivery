# ADMIN-LISTS — the list contract (batch 68, slice API-LISTS)

**The source of truth for every list endpoint's search, filters, sort and paging.** The
dashboards and the Flutter consoles build their filter bars from this file. When the code
and this file disagree, the code is wrong or this file is updated in the same commit.

Base path `/api/v1`. «(new)» marks what batch 68 adds; everything unmarked already worked
and still works exactly as before.

---

## 0 · Conventions — the same on every endpoint below

### 0.1 Spelling

- Every filter is `filter[<key>]=…`. The **bare** `<key>=…` is still accepted (the app's
  consoles and `GET /restaurants` send it). When both are present, `filter[<key>]` wins.
- `filter[search]` is the search box. Aliases, all equal: `search`, `filter[q]`, `q`.
- Unknown filter keys are **ignored** (never an error).

### 0.2 Value types, and what a bad value gets

| Type | Accepts | Bad value → |
|---|---|---|
| `enum` | one value, a comma list `a,b`, or an array `filter[x][]=a&filter[x][]=b`. `all` or empty = no filter | **422** |
| `id` / `ids` | a positive integer, or a comma list of them | **422** |
| `bool` | `1 0 true false yes no on off` | **422** |
| `date` | `YYYY-MM-DD` (an ISO date-time is accepted too) | **422** |
| `string` | anything, trimmed, max 64 chars used | never |

**Dates are business days in Africa/Cairo**, inclusive at both ends: `date_from=2026-09-18`
starts at 00:00 Cairo, `date_to=2026-09-18` ends at 23:59:59 Cairo. `date_from` later than
`date_to` → **422**. (Several endpoints used UTC `whereDate` before and lost the first two
hours of every day — fixed everywhere in batch 68.)

**The 422 is the house envelope:**

```json
{ "success": false, "error": "validation_failed", "message": "…",
  "errors": { "filter.status": ["قيمة غير معروفة: shipped"] } }
```

### 0.3 Search semantics (`filter[search]`)

One box, many haystacks, OR-ed; each endpoint lists which of these it searches:

- **names** — Arabic-folded on both sides (أ إ آ ٱ → ا, ة → ه, ى → ي, ؤ → و, ئ → ي,
  harakat and tatweel dropped, case-insensitive). Split on spaces; **every** word must
  appear (in any of the name columns) — «فراخ مشوي» narrows, it does not widen.
- **phones** — the typed digits (Arabic-Indic accepted, `+20` / `0020` / spaces stripped),
  matched **anywhere** in the stored number, from 3 digits up. `+20 101 111` finds `01011112222`.
- **email** — contains, case-insensitive.
- **refs** — order numbers, ticket numbers, references: contains (`1045` finds `CR7-1045-1`).
- **id** — when the whole term is a number, the row with that id also matches.

`filter[search]` never widens the admin's scope: it is AND-ed inside the scope (§0.6).

### 0.4 Sort

`sort=<field>` ascending, `sort=-<field>` descending. Each endpoint has a whitelist and a
default. **An unknown field is not an error: the default applies.** Ties always break on
`id` in the sort's direction, so a page never repeats or drops a row. The applied sort is
echoed as `meta.sort` on every paginated list in §1–§3 and the customer's own lists in §4
(new); the catalogue reads (`/restaurants`, a menu, reviews) do not echo it.

### 0.5 Paging

- `page` (1-based), `per_page` **clamped to 1…100** (was 200) — non-numeric → the default,
  `0` → 1, `500` → 100. Default 20 unless the endpoint says otherwise.
- Every paginated answer carries `meta.current_page`, `meta.per_page`, `meta.total`,
  `meta.last_page` (and `meta.sort`, see §0.4). «total: yes» below means exactly this.
  The 1…100 clamp is global (`ForceJsonResponse`, `security.max_per_page`), so it also holds
  on any list this file does not name.
- 🟡 `apps/admin/src/lib/queries.ts:209` asks `/admin/restaurants?per_page=200` for a
  picker — it now gets 100. Use **`GET /admin/lookups/restaurants`** (§1.27) for pickers.

### 0.6 Scope (D-50…D-52, D-141)

Every admin list applies `AdminScope` **first**; filters only narrow what is already in
scope. A filter naming an out-of-scope id (`filter[restaurant_id]=<not mine>`) returns an
**empty page**, never 403/404 and never the row.

### 0.7 Client guidance (debounce and polling)

- Search boxes: **debounce 350 ms**, cancel the in-flight request when a new one starts,
  and do not send a 1-character term unless it is a digit (ids).
- Polled lists (board, alerts, fleet): keep 15–20 s, **pause when the tab/app is hidden**,
  and back off on `429` using `meta.retry_after` (seconds; also the `Retry-After` header).
- Catalogue reads (§5): send `If-None-Match` with the last `ETag` — a `304` has no body.

---

## 1 · Admin (`type:admin`, `X-App: admin`)

### 1.1 `GET /admin/orders` — and `GET /admin/orders/export` (same filters + sort, no paging)

| | |
|---|---|
| search | `order_number`, `restaurant_order_ref` (refs) · customer `users.name` (name), `users.phone` (phone) · `customer_name_snapshot` (name, new) · `delivery_contact_phone` (phone, new) · `guest_customer_name` / `guest_customer_phone` (new) · id (new) |
| `status` | enum `OrderStatus`: `pending restaurant_timeout accepted awaiting_customer_confirmation awaiting_payment confirmed preparing ready searching_driver waiting_for_driver driver_assigned arrived_restaurant waiting_at_restaurant picked_up on_the_way arrived_customer delivered cancelled delivery_issue` — multi (new) |
| `status_group` (new) | enum `active` (not delivered/cancelled) · `completed` (delivered) · `cancelled` · `issue` (delivery_issue, restaurant_timeout) |
| `origin` | enum `app`, `restaurant_direct`; `direct` = `restaurant_direct` (new) |
| `restaurant_id` | ids (multi new) |
| `driver_id` | ids, or `none` = no captain yet (new) |
| `customer_id` (new) | ids (`orders.user_id`) |
| `city_id`, `zone_id` | ids (multi new) |
| `order_group_id` (new) | id |
| `payment_method` | enum `cash vodafone_cash instapay` (multi new) |
| `has_driver` (new) | bool |
| `overdue` (new) | bool — `preparing` past `prep_due_at` |
| `date_from`, `date_to` | date on `created_at` (Cairo — was UTC) |
| sort | `created_at` (default `-created_at`), `id`, `total`, `delivered_at`, `status` |
| paging | 20 / max 100, total: yes |

### 1.2 `GET /admin/direct-orders`

| | |
|---|---|
| search | `order_number` (refs), `guest_customer_name` (name), `guest_customer_phone` (phone), id (new) |
| filters | `status` (enum `OrderStatus`, multi new) · `restaurant_id` (ids) · `driver_id` (ids, new) · `city_id` · `zone_id` (new) · `date_from`/`date_to` (new, `created_at`) |
| sort | `created_at` (default `-created_at`), `id`, `status` |
| paging | 20 / 100, total: yes |

### 1.3 `GET /admin/restaurants`

| | |
|---|---|
| search | `name_ar`, `name_en` (names, folded — new), `phone` (new), `slug` (new), id (new) |
| `city_id` | ids |
| `zone_id` (new) | ids — home zone **or** a served zone |
| `store_category_id` (new) | ids |
| `cuisine_id` (new) | ids |
| `is_active`, `is_verified`, `is_featured`, `direct_orders_enabled` (new) | bool |
| `open_now` (new) | bool — the same rule as `is_open_now` (switch + today's hours, Cairo clock), in SQL |
| `price_band` (new) | enum `budget mid premium` |
| `has_pending` (new) | bool — has an order in `pending` / `restaurant_timeout` |
| sort | `id` (default, as before), `name`, `created_at`, `rating_avg`, `orders_count`, `sort_order` |
| paging | 20 / 100, total: yes |

### 1.4 `GET /admin/drivers`

| | |
|---|---|
| search | captain `users.name` (name, folded — new), `users.phone` (phone), `vehicle_plate` (new), id (new) |
| `city_id` | ids |
| `zone_id` (new) | ids — an assigned zone |
| `is_online` | bool |
| `is_available`, `is_active` (new) | bool |
| `has_active_order` (new) | bool — carrying at least one order (the trip) |
| `vehicle_type` (new) | enum `motorcycle bicycle car scooter` |
| `earning_type` (new) | enum `fixed per_km percent_of_delivery_fee` |
| `stale` (new) | bool — online but no fix for 90 s |
| sort | `id` (default), `name`, `created_at`, `rating_avg`, `completed_orders`, `cash_in_hand`, `wallet_balance`, `location_updated_at` |
| paging | 20 / 100, total: yes |

### 1.5 `GET /admin/customers`

| | |
|---|---|
| search | `name` (name, folded — new), `phone`, `email` (new), id (new) |
| `city_id`, `zone_id` (new) | ids — of the default address |
| `is_active` (new) | bool |
| `blocked` (new) | bool — `blocked_at` set |
| `restricted` (new) | bool — `cash_only_until` in the future |
| `has_orders` (new) | bool |
| `date_from`, `date_to` (new) | date on sign-up (`created_at`) |
| sort | `created_at` (default `-created_at`), `name`, `last_login_at`, `orders_count` (new) |
| paging | 20 / 100, total: yes. Each row now carries `orders_count` (new) |

### 1.6 `GET /admin/complaints`

| | |
|---|---|
| search | `ticket_number` (refs), `subject` (name), reporter `users.name` / `users.phone`, id (all new) |
| `status` | enum `open in_progress resolved rejected` (multi new) |
| `category` | enum `order_issue late_delivery missing_item food_quality driver_behaviour payment app_problem other` (multi new) |
| `priority` | enum `low normal high` (multi new) |
| `user_role` (new) | enum `customer driver restaurant` |
| `assigned_to` (new) | ids, `none` (unassigned) or `me` |
| `restaurant_id` (new) | ids |
| `city_id`, `zone_id` (new) | ids — of the linked order |
| `overdue` | bool — past `due_at`, not resolved |
| `date_from`, `date_to` (new) | date on `created_at` |
| sort | `created_at` (default `-created_at`), `due_at`, `updated_at`, `priority` |
| paging | 20 / 100, total: yes |

### 1.7 `GET /admin/payments`

| | |
|---|---|
| search | `reference` (refs), group `group_number` (refs), payer name / phone, id (all new) |
| `status` | enum `pending awaiting_proof under_review paid failed refunded partially_refunded` (multi new) |
| `method` (new) | enum `cash vodafone_cash instapay` |
| `city_id`, `zone_id` (new) | ids — of the basket |
| `overdue` (new) | bool — `pending`/`awaiting_proof` past `deadline_at` |
| `date_from`, `date_to` (new) | date on `created_at` |
| sort | `created_at` (default `-created_at`), `amount`, `deadline_at` |
| paging | 20 / 100, total: yes |

### 1.8 `GET /admin/refunds`

| | |
|---|---|
| search | `reference`, order `order_number` (refs), id (new) |
| `status` | enum `pending processed failed` (multi new) |
| `type` (new) | enum `full partial` |
| `method` (new) | enum `cash vodafone_cash instapay wallet` |
| `reason_code` (new) | string, exact |
| `restaurant_id` (new) | ids — of the order |
| `date_from`, `date_to` (new) | date on `created_at` |
| sort | `created_at` (default `-created_at`), `amount`, `processed_at` |
| paging | 20 / 100, total: yes |

### 1.9 `GET /admin/settlements`

| | |
|---|---|
| search | id, `payment_reference` (refs), party name — place `name_ar`/`name_en` or captain name (all new) |
| `party_type` | enum `restaurant driver` |
| `party_id` (new) | ids (use with `party_type`) |
| `restaurant_id`, `driver_id` (new) | ids — shorthand for `party_type` + `party_id` |
| `status` | enum `draft pending paid` (multi new) |
| `date_from`, `date_to` (new) | date — statements whose period **overlaps** the range |
| sort | `period_end` (default `-period_end`), `created_at`, `net_amount`, `paid_at` |
| paging | 20 / 100, total: yes. `party_name` no longer costs a query per row |

### 1.10 `GET /admin/delivery-issues`

| | |
|---|---|
| search | order `order_number` (refs), id (new) |
| `status` | enum `open in_progress resolved` (multi new) |
| `category` (new) | enum `customer_unreachable wrong_address accident food_problem driver_unresponsive payment handover other` |
| `reported_by` (new) | enum `driver customer restaurant system` |
| `restaurant_id` | ids |
| `driver_id` (new) | ids — the order's captain |
| `date_from`, `date_to` (new) | date on `created_at` |
| sort | `created_at` (default `-created_at`) |
| paging | 20 / 100, total: yes |

### 1.11 `GET /admin/captain-applications`

| | |
|---|---|
| search | `full_name` (name, folded — new), `phone` (phone), `reference` (refs), id (new) |
| `status` | enum `pending under_review approved rejected` (multi new) |
| `city_id` | ids |
| `vehicle_type` (new) | enum `motorcycle bicycle car scooter` |
| `date_from`, `date_to` (new) | date on `created_at` |
| sort | `created_at` (default `-created_at`), `status` |
| paging | 20 / 100, total: yes |

### 1.12 `GET /admin/errands`

| | |
|---|---|
| search | `reference` (refs), `pickup_address_text` / `dropoff_address_text` (name), customer name / phone (new), id (new) |
| `status` | enum `open offered assigned picked_up delivered cancelled` (multi new) |
| `unclaimed` | bool |
| `city_id`, `zone_id`, `driver_id`, `customer_id` (new) | ids |
| `date_from`, `date_to` (new) | date on `created_at` |
| sort | `created_at` (default `-created_at`), `offered_fee` |
| paging | 20 / 100, total: yes; `meta.unclaimed` as before |

### 1.13 `GET /admin/scheduled-orders`

| | |
|---|---|
| search | `reference` (refs), customer name / phone, id (all new) |
| `status` | enum `scheduled released failed cancelled` (multi new) |
| `restaurant_id` | ids |
| `customer_id` (new) | ids |
| `date_from`, `date_to` (new) | date on `scheduled_for` |
| sort | `scheduled_for` (default, ascending), `created_at`, `estimated_total` |
| paging | 20 / 100, total: yes; `meta.pending_count` as before |

### 1.14 `GET /admin/promo-codes`

| | |
|---|---|
| search | `code` (refs), `title_ar`/`title_en` (names) — new |
| `is_active` | bool |
| `state` (new) | enum `live` (active, started, not ended) · `scheduled` (starts later) · `expired` (ended) · `inactive` (switched off) |
| `type` (new) | enum `fixed percent free_delivery` |
| `cost_bearer` (new) | enum `platform restaurant` |
| sort | `created_at` (default `-created_at`), `used_count`, `starts_at`, `ends_at`, `code` |
| paging | 20 / 100, total: yes |

### 1.15 `GET /admin/promo-codes/{promoCode}/usages`

| search | user name / phone, order `order_number` (new) |
|---|---|
| filters | `date_from`, `date_to` (new) |
| sort | `created_at` (default `-created_at`) |
| paging | 20 / 100, total: yes |

### 1.16 `GET /admin/users` — admin accounts, global admins only

| | |
|---|---|
| search | `name` (name), `phone`, `email`, id (all new) |
| `role` | string — role name |
| `status` | enum `active` (new) · `suspended` · `invited` |
| sort | `created_at` (default `-created_at`), `name`, `last_login_at` |
| paging | 20 / 100, total: yes |

### 1.17 `GET /admin/audit-logs` — global admins only

| | |
|---|---|
| search | `action` (refs), actor name (new) |
| `user_id` | ids |
| `action` | string, exact; ends in `*` for a prefix: `orders.*` (new) |
| `auditable_type` (new) | string — `order`, `restaurant`, … or the full class name |
| `auditable_id` (new) | id |
| `date_from`, `date_to` | date on `created_at` (Cairo — was UTC) |
| sort | `created_at` (default `-created_at`) |
| paging | **30** / 100, total: yes |

### 1.18 `GET /admin/cities`

| search (new) | `name_ar`/`name_en` |
|---|---|
| filters | `is_active` (new) |
| sort | `sort_order` (default), `name`, `id` |
| paging | 20 / 100, total: yes |

### 1.19 `GET /admin/zones`

| search (new) | `name_ar`/`name_en` |
|---|---|
| filters | `city_id` · `is_active`, `is_selectable` (new, bool) |
| sort | `id` (default, as before), `sort_order`, `name`, `delivery_fee` |
| paging | 20 / 100, total: yes |

### 1.20 `GET /admin/pricing-rules`

| filters | `city_id` · `zone_id` (alias `service_zone_id`) · `is_active` (new) |
|---|---|
| sort | `sort_order` (default), `fee`, `min_distance_km` |
| paging | 20 / 100, total: yes |

### 1.21 `GET /admin/restaurants/{restaurant}/products`

| search (new) | `name_ar`/`name_en` (names), id |
|---|---|
| filters | `menu_category_id` (ids) · `is_available`, `is_featured` (new) |
| sort | `sort_order` (default), `name`, `price`, `sold_count`, `rating_avg`, `created_at` |
| paging | 20 / 100, total: yes |

Every row (and `show`, `store`, `update`) also carries **`gallery_items`** and **`gallery_version`** — see §1.27c.

### 1.22 `GET /admin/finance/drivers`

| search | captain name (folded) / phone |
|---|---|
| filters | `city_id` · `zone_id`, `is_online`, `is_active` (new) · `owes` (new, bool — the captain owes the platform: `cash_in_hand − wallet_balance > 0`, `DriverLedger`'s own rule) |
| sort | `cash_in_hand` (default `-cash_in_hand`), `wallet_balance`, `name` |
| paging | 20 / 100, total: yes; `meta.totals` as before (whole scope, not the filter) |

### 1.23 `GET /admin/finance/restaurants` — **not paginated** (one row per place)

| search (new) | place name (folded), phone |
|---|---|
| filters | `city_id` (new) · `owed` (new, bool — `owed_online > 0`) |
| sort | `-owed_online` (default), `owed_online`, `name`, `paid_at_pickup`, `oldest_unclaimed_at` |
| meta | `totals` (of the rows returned), no `total` |

### 1.24 `GET /admin/drivers/{driver}/ledger`

| filters | `type` (enum `order_earning bonus deduction cash_settlement cash_collected cash_handover payout place_advance`, multi new) · `date_from`, `date_to` (new) |
|---|---|
| sort | `created_at` (default `-created_at`) |
| paging | **30** / 100, total: yes |

### 1.25 `GET /admin/customers/{customer}/orders`

| filters (new) | `status_group` (`active completed cancelled`) · `date_from`, `date_to` |
|---|---|
| sort | `created_at` (default `-created_at`), `total` |
| paging | 20 / 100, total: yes |

### 1.26 Reports (paginated ones)

| Endpoint | search (new) | filters | sort (default first) |
|---|---|---|---|
| `GET /admin/reports/customers` | name / phone | `segment` (`new returning restricted`, enum) | `-created_at`, `name` |
| `GET /admin/reports/drivers` | name / phone | `city_id` (new), `date_from/to` | `-delivered_orders_count`, `earnings`, `name` |
| `GET /admin/reports/products` | product name | `restaurant_id` (new) | `-sold_count`, `rating_avg`, `price`, `name` |
| `GET /admin/reports/restaurants` | place name | `city_id` (new), `date_from/to` | `-gmv`, `delivered_orders_count`, `name` |
| `GET /admin/reports/promo-codes` | code / title | `is_active` (new) | `-used_count`, `usages_sum_discount_amount`, `code` |

All 20 / 100, total: yes. The chart/summary reports (`sales`, `zones`, `complaints`,
`refunds`, `customers/cohorts`) are not lists and are unchanged.

### 1.27 `GET /admin/lookups/{kind}` (new) — pickers for the filters above

Light rows for dropdowns and typeaheads, scoped like the list they feed.

| `kind` | rows | scope |
|---|---|---|
| `restaurants` | `{id, name, name_en, city_id, is_active}` | `AdminScope::applyToRestaurants` |
| `drivers` | `{id, name, phone, is_online, is_active}` | `applyToDrivers` |
| `cities` | `{id, name, name_en}` | `applyToCities` |
| `zones` | `{id, name, name_en, city_id}` | `applyToZones` |
| `store_categories` | `{id, name, name_en, slug}` | global |
| `cuisines` | `{id, name, name_en}` | global |
| `admins` | `{id, name}` — active admin accounts, for `assigned_to` | caller must be global, or hold `complaints.view` or `users.view` (else 404) |

`filter[search]` (names, phone for drivers), `filter[city_id]` (restaurants, drivers,
zones), `filter[is_active]`, `limit` 1…200 (default 50). Sorted by name. **No paging, no
`meta.total`** — `meta.count` and `meta.truncated` (true when `limit` cut the list).

### 1.27b `GET /admin/media` and `GET /admin/media/available` (doc 71)

`GET /admin/media` — paged (`per_page` ≤ 100, default 24), `q` folds names, filename, tags, assigned places/groups and the
words that name a usage/source/visibility; `filter[usage]`, `filter[source]` (`uploaded|shipped|system`),
`filter[visibility]` (`global|restricted|hidden`, alias `shared`), `filter[tag]` (slug), `filter[place_id]`,
`filter[group_id]`, `filter[store_category_id]`, `filter[unused]`, `filter[state]` (`active|removed|all`),
`date_from`/`date_to`, `sort` = `created_at|name|bytes|source|visibility`. `GET /admin/media/available` is filtered by
`filter[source]` (`backend|app|dashboard`), `filter[usage]`, `filter[folder]`, `q`; `meta.folders` lists the folders.

Every item these lists (and `GET /admin/media/{id}`, `GET /admin/media/picker` and `GET /restaurant/media/picker`)
return carries **`thumb_url`** — the picture at ≈ 384 px wide; see §1.27c.

### 1.27c Product gallery and thumbnails (batch 72)

**Payload additions** (restaurant and admin product endpoints only — the customer API is unchanged):
`gallery` stays the list of URLs, exactly as before; beside it

```json
"gallery_items": [{ "ref": "own:9f2c…", "url": "https://…/storage/products/a.jpg",
                    "thumb_url": "https://…/img/384/storage/products/a.jpg?v=1789…", "position": 0 }],
"gallery_version": "5d41402abc4b2a76b9719d911017c592"
```

`ref` = `own:` + `sha1(url)` (the picker's own-picture handle); `gallery_version` = md5 of the stored JSON list — it
changes on any add, remove or reorder. The cap is **12** pictures per product (`Product::GALLERY_MAX`).

**Per-picture endpoints** — restaurant `X-App: restaurant` (own products only, a rival's is `404`) and the admin mirror
under `/admin/restaurants/{restaurant}/products/{product}/gallery…` (`permission:products.update`, scoped: a restaurant
outside the admin's scope is `404`). Each answers the whole product (`200`, with `gallery_items` / `gallery_version`).

| Request | Body | Effect |
|---|---|---|
| `POST …/gallery` | multipart `gallery[]` files (image, ≤ 5 MB) and/or `gallery_media[]` refs (`asset:N` / `own:…`, resolved as the picker does); optional `expected_version` | **appends** — refs first, then files, each in the order sent; a URL already present is skipped |
| `DELETE …/gallery/{ref}?expected_version=` | — | removes that entry (it must be in **this** product's gallery); the file is deleted after the commit unless the library or another field still shows it |
| `PUT …/gallery/order` | `{"order":["own:…",…],"expected_version":"…"}` | reorders; `order` must be **exactly** the current entries, each once. Never deletes |

Errors: `422 validation_failed` (bad body, or > 12 in a replace), `422 media_not_allowed` (a ref the actor may not use),
`422 gallery_full` (`meta.max`) on an append, `422 gallery_order_invalid` (not a permutation), `404 gallery_item_not_found`,
`409 gallery_changed` — **`expected_version` was sent and is no longer current**; its `meta` carries the *current*
`gallery_items` and `gallery_version` so the client can re-render without another request. Omitting `expected_version`
is allowed and means last-write-wins. Every mutation runs in one transaction with the product row locked `FOR UPDATE`.

**The replace path is unchanged for old clients** — `PATCH …/products/{id}` with `gallery[]` / `gallery_media[]` still
replaces the gallery; what changed: the stored order is the order sent (refs first, then uploads), a picture named twice
is stored once, > 12 is `422 validation_failed`, an empty `gallery` key no longer wipes it (`remove_gallery` does),
dropped files are deleted after the commit (library / shared files are never deleted), and `expected_version` is honoured.

**Thumbnails.** `thumb_url` is `<host>/img/384/<path>?v=<mtime>` — the existing `/img/{width}/…` resizer, now also
serving `storage/…` (the uploads disk, so library and uploaded pictures), `media/system/…` and `shipped/…`, besides
`catalog/…`. Widths are the allowlist `96·192·384·768·1200`; the resized file is written to `public/img/{width}/…` so the
web server serves it from disk afterwards; `Cache-Control: public, max-age=31536000, immutable`. A picture that cannot be
resized (remote host, GIF, missing file, a name the route would refuse) is answered **as its own `url`** — `thumb_url`
is always fetchable. Deleting a file also removes its cached thumbnails.

### 1.27d `DELETE /auth/account` — a customer deletes their account (batch 72)

Customers only. A captain or a restaurant answers `403 account_deletion_by_request` (they ask in writing — see the public
`/delete-account` page; the office runs `php artisan cr7:erase-account <phone>` for a customer who cannot open the app).

| Response | When |
|---|---|
| `200 {success:true, message:"تم حذف الحساب بنجاح"}` | erased; every session of the account ended |
| `409 {success:false, error:"active_order_blocks_deletion", message:"لديك طلب جارٍ. انتظر حتى يكتمل أو ألغِه قبل حذف الحساب.", meta:{active_orders, active_errands, scheduled_orders}}` | any order that is not `delivered`/`cancelled` (`delivery_issue` counts), any errand in `open/offered/assigned/picked_up`, or a scheduled order still `scheduled`. Nothing is changed |
| `403 account_deletion_by_request` | the account is a captain, restaurant or admin |
| `401` | a second call — the first one ended the session |

One transaction, the user row locked, the active-work check repeated under the lock; files (profile photo, complaint
photos) are deleted only after the commit. **Erased:** saved addresses (soft-deleted too), favourites, basket, notifications,
finished scheduled orders, sessions/tokens, device tokens, social-account and verified-phone rows, OTP/reset rows for the old
phone/email, complaint photos, the profile photo, birth date and gender. **Blanked on the records that stay:** on every
order, order group and errand — name → «مستخدم محذوف», contact phone, notes, instructions and cancellation note removed, street
address → the area, pin rounded to 2 decimals (~1 km), errand addresses/details erased, review comments removed. **Kept:**
orders, payments, refunds, wallet/settlement rows, transfer and purchase receipts, complaint text, rating stars, promo usage,
audit logs; SMS counters are pruned at 30 days. Restaurant and captain views of the order keep rendering, with the
anonymised name.

### 1.28 Admin lists that are **not** paginated and take no filters (unchanged)

`banners`, `cuisines`, `faqs`, `pages`, `home-popups` (`active_only`), `store-categories`
(`active_only`), `roles`, `permissions`, `settings`, `settings/schema`,
`restaurants/{r}/categories`, `products/{p}/option-groups`, `drivers/{d}/documents`,
`customers/{c}/addresses`, `customers/{c}/complaints`, `orders/{o}/notes|attempts|eligible-drivers`,
`errands/{e}/eligible-drivers`, every `dashboard/*`, `ops/health`, `complaints/sla`.

---

## 2 · Place (`type:restaurant`, `X-App: restaurant`) — always the caller's own place

### 2.1 `GET /restaurant/orders`

| | |
|---|---|
| search | `filter[q]` **and** `filter[search]`: `order_number`, `restaurant_order_ref` (refs), `customer_name_snapshot` (name), `delivery_contact_phone` (phone), `guest_customer_name`/`guest_customer_phone` (new), id (new) |
| `queue` | enum `new awaiting_customer confirmed preparing ready with_driver delivered done` (an unknown queue was ignored — now 422) |
| `status` | enum `OrderStatus` (multi new) |
| `payment_method` (new) | enum `cash vodafone_cash instapay` |
| `origin` (new) | enum `app restaurant_direct` (`direct` alias) |
| `overdue` (new) | bool — `preparing` past `prep_due_at` |
| `date_from`, `date_to` | date on `created_at` (Cairo — was UTC) |
| sort | `created_at` (default `-created_at`), `total`, `status`, `prep_due_at` |
| paging | 20 / 100, total: yes |

### 2.2 `GET /restaurant/direct-orders`

| search (new) | `order_number`, guest name / phone, id |
|---|---|
| filters | `status` (multi new) · `date_from`, `date_to` (on `business_date`) |
| sort | `created_at` (default `-created_at`), `status` |
| paging | 20 / 100, total: yes |

### 2.3 `GET /restaurant/products`

| search | `name_ar`/`name_en` (folded — new), id (new) |
|---|---|
| filters | `menu_category_id` · `is_available`, `is_featured` (new) |
| sort | `sort_order` (default), `name`, `price`, `sold_count`, `created_at` |
| paging | **30** / 100, total: yes |

Every row (and `show`, `store`, `update`) also carries **`gallery_items`** and **`gallery_version`** — see §1.27c.

### 2.4 `GET /restaurant/categories` — not paginated

`filter[search]` (names, folded — new), `filter[is_active]` (new). Ordered by `sort_order`.

### 2.5 `GET /restaurant/complaints`

| | |
|---|---|
| `tab` | enum `about_me` (default) · `mine` |
| search (new) | `ticket_number`, `subject`, id |
| `status`, `category` | enums as §1.6 (multi new) |
| `unread` (new) | bool — not yet opened by the place (`about_me` only) |
| `date_from`, `date_to` (new) | date on `created_at` |
| sort | `created_at` (default `-created_at`), `updated_at` |
| paging | 20 / 100, total: yes |

### 2.6 `GET /restaurant/ratings`

| filters (new) | `stars` (enum `1…5`, multi) · `has_comment` (bool) · `date_from`, `date_to` |
|---|---|
| sort | `created_at` (default `-created_at`), `stars` |
| paging | 20 / 100, total: yes |

### 2.7 `GET /restaurant/settlements`

| filters (new) | `status` (`draft pending paid`) · `date_from`, `date_to` (period overlap) |
|---|---|
| sort | `period_end` (default `-period_end`), `created_at` |
| paging | 20 / 100, total: yes |

### 2.8 `GET /restaurant/scheduled-orders`

| filters (new) | `date_from`, `date_to` on `scheduled_for` |
|---|---|
| sort | `scheduled_for` (default, ascending) |
| paging | 20 / 100, total: yes |

### 2.9 `GET /restaurant/notifications` and `GET /notifications` (every signed-in account)

| filters | `unread` (bool) · `type` (new, string list — e.g. `order.new,complaint.reply`) |
|---|---|
| sort | newest first (fixed) |
| paging | 20 / 100, total: yes |

---

## 3 · Captain (`type:driver`, `X-App: driver`) — always the caller's own rows

### 3.1 `GET /driver/orders` — finished work

| | |
|---|---|
| search (new) | `order_number` (refs), place name (`restaurant_name_snapshot`), id |
| `status` | enum `OrderStatus` (multi new). Absent = `delivered` + `cancelled` |
| `from` | date — «اليوم / هذا الأسبوع» (unchanged) |
| `date_from`, `date_to` (new) | date — on `delivered_at`, else `created_at` (the rule `from` uses) |
| `payment_method` (new) | enum |
| sort | `created_at` (default `-created_at`), `delivered_at`, `driver_earning` |
| paging | 20 / 100, total: yes |

### 3.2 `GET /driver/settlements`

`filter[status]` (new), `filter[date_from|date_to]` (new, overlap). Default `-period_end`. 20 / 100, total: yes.

### 3.3 `GET /driver/errands/mine`

`filter[status]` (new, enum as §1.12, multi). Default `-created_at`. 20 / 100, total: yes.

### 3.4 `GET /driver/earnings` — not paginated

`from`/`to` (unchanged) **or** `filter[date_from]`/`filter[date_to]` (new, Cairo days);
`filter[type]` (new, ledger types, multi) narrows `breakdown` only — the balances are
always the whole account.

---

## 4 · Customer

| Endpoint | search | filters | sort | paging |
|---|---|---|---|---|
| `GET /orders` | group number, place name (new) | `status_group` (`active completed cancelled`), `date_from/to` (new) | `-created_at` (default), `total` | 20/100, total |
| `GET /complaints` | ticket, subject (new) | `status` (multi, new) | `-created_at` | 20/100, total |
| `GET /customer/errands` | — | `status` (multi, new) | `-created_at` | 20/100, total |
| `GET /scheduled-orders` | — | `status` (multi new) | `scheduled_for` | 20/100, total |
| `GET /restaurants` | names + descriptions, folded (unchanged; `filter[search]` accepted — new) | `city_id`, `zone_id`, `store_category`, `cuisine_id`, `min_rating`, `free_delivery`, `price_band`, `max_delivery_fee`, `open_now` (**now in SQL, so `meta.total` counts only open places** — it counted closed ones before) | `-rating_avg`, `rating_avg`, `popular`/`-orders_count`, `fastest`, `nearest` (needs `lat`,`lng`); default featured then `sort_order` | 20/100, total |
| `GET /restaurants/{r}/products` | names + descriptions | `category_id` | `price`, `-price`, `-sold_count`; default `sort_order` | 30/100, total |
| `GET /cuisines/{c}/products` | names + descriptions | `city_id` | `price`, `-price`, `-rating_avg`; default featured, sold | 30/100, total |
| `GET /restaurants/{r}/reviews` | — | `stars` (new) | `-created_at` | 20/100, total |
| `GET /search` | `search` (≥1 token) | — | relevance | not paginated |

---

## 5 · HTTP caching — `ETag`, `304`, `Cache-Control` (new)

The reads the customer home opens on are built once and served from the server cache
until something they depend on changes (`CachePublicResponse`, route alias `http.cache`).

| Endpoint | Server cache | `Cache-Control` (signed out; `private` unless `HTTP_CACHE_PUBLIC=true`) | `Vary` |
|---|---|---|---|
| `GET /config` | 60 s, **per audience** (guest · first order ahead · returning) | `public, max-age=30` | `Accept-Language, Authorization` |
| `GET /banners`, `GET /home-popups` | 60 s, per audience | `public, max-age=30` | `Accept-Language, Authorization` |
| `GET /cuisines`, `/store-categories`, `/cities`, `/cities/{c}/zones`, `/areas`, `/faqs`, `/pages/{slug}` | 300 s | `public, max-age=60` | `Accept-Language` |
| `GET /restaurants`, `/restaurants/{r}`, `/restaurants/{r}/categories`, `/restaurants/{r}/products`, `/restaurants/{r}/best-sellers`, `/products/{p}`, `/cuisines/{c}/products` | 60 s | `public, max-age=30` | `Accept-Language` |

- **Every `200` above carries a weak `ETag`** (`W/"<32 hex>"`). Send it back as
  `If-None-Match`: a match is **`304 Not Modified` with an empty body**, and on a stored
  answer the controller does not run at all. A `304` repeats `ETag`, `Cache-Control`, `Vary`.
- **Signed in** (a bearer token): batch 67's rule still wins — every answer to a token is
  `Cache-Control: no-store, private`. The `ETag` and the `304` work exactly the same, so a
  client that keeps the tag itself (Dio interceptor, the BFF) still gets bodiless revalidation.
  The audience routes (config, banners, popups) key the stored answer by audience, never by
  account, and never hand one audience's body to another.
- `X-Cache: HIT | MISS | BYPASS` on every answer — BYPASS = answered and tagged, not stored.
- **Only bounded requests are stored**: query keys limited to `page`, `per_page`, `lat`,
  `lng`, `city_id`, `zone_id`, `sort`, `category_id`, `selectable`, `active_only`,
  `filter[store_category|store_category_id|cuisine_id|free_delivery|open_now|price_band]`;
  `page` ≤ 3; `lat`/`lng` only when they are an area's own centre (what the app sends).
  A `search`, a deeper page, a GPS point or any other key is answered (and tagged) but not stored.
- **Invalidation on write:** saving or deleting a `Restaurant`, `RestaurantOpeningHour`,
  `MenuCategory`, `Product`, `ProductOptionGroup`, `ProductOption`, `Cuisine`,
  `StoreCategory`, `Banner`, `HomePopup`, `City`, `ServiceZone`, `Faq` or `StaticPage`
  retires every stored catalogue answer (a new version, not a delete); saving a `Setting`
  retires `/config` and the catalogue. So do the two bulk writes that fire no model event
  (`POST /restaurant/products/bulk-availability`, `POST /admin/home-popups-reorder`, `POST /admin/store-categories-reorder`).
  Not retired, bounded by the TTL: a pivot change (a place's cuisines or served zones),
  counters moved with `increment()`, and time-driven facts (`is_open_now`, a banner's window).
- `cr7:prune-cache` (every 10 min) deletes expired rows from the `cache` table in batches of
  2 000 (`cache (expiration)` is indexed); it is a no-op on any store but `database`.
- Switches, no deploy needed: `HTTP_CACHE_ENABLED=false` (build every read again),
  `HTTP_CACHE_PUBLIC=true` (`public` instead of `private`). **Off by default** — production
  sits behind Hostinger's CDN (`server: hcdn`), and whether it honours `Vary` has not been
  measured; a CDN that ignores it would hand an English body to an Arabic phone.

## 6 · Throttles (new)

Every signed-in route group carries `throttle.account` (`ThrottlePerAccount`): one bucket
per account, keyed by the account id — never the IP (both dashboards share one BFF
address; a carrier NAT puts a town behind a handful) — sized by account type, 60-second
window. Refusal is the house envelope, `429`:

```json
{ "success": false, "error": "rate_limited", "message": "محاولات كثيرة جدًا، حاول لاحقًا",
  "meta": { "retry_after": 17 } }
```

plus `Retry-After`. Every allowed answer carries `X-RateLimit-Limit` / `X-RateLimit-Remaining`.

| Account type | Per minute | Env |
|---|---|---|
| admin | 300 | `THROTTLE_ADMIN_PER_MINUTE` |
| restaurant | 240 | `THROTTLE_PLACE_PER_MINUTE` |
| driver | 240 | `THROTTLE_CAPTAIN_PER_MINUTE` |
| customer | 180 | `THROTTLE_CUSTOMER_PER_MINUTE` |
| `GET /search` (public) | 120 per account when signed in · 300 per IP | `THROTTLE_SEARCH_PER_MINUTE`, `THROTTLE_SEARCH_PER_IP_PER_MINUTE` |

The counters live in the **file** cache store (`THROTTLE_STORE`), not the default database
store: counting every poll in the `cache` table would be a locked write per request. The
route-level limiters that already existed (sign-in, OTP, refresh, geo, complaint create)
are unchanged and still apply on top.

A client that gets a `429` waits `meta.retry_after` seconds before that screen polls again;
it never retries in a loop.
