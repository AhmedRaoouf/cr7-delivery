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
echoed as `meta.sort` on every paginated answer (new).

### 0.5 Paging

- `page` (1-based), `per_page` **clamped to 1…100** (was 200) — non-numeric → the default,
  `0` → 1, `500` → 100. Default 20 unless the endpoint says otherwise.
- Every paginated answer carries `meta.current_page`, `meta.per_page`, `meta.total`,
  `meta.last_page` (and `meta.sort`). «total: yes» below means exactly this.
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

### 1.22 `GET /admin/finance/drivers`

| search | captain name (folded) / phone |
|---|---|
| filters | `city_id` · `zone_id`, `is_online`, `is_active` (new) · `owes` (new, bool — `cash_in_hand > 0`) |
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
| `GET /admin/reports/customers` | name / phone | `segment` (`new returning restricted`), `date_from/to` (the range the report counts) | `-created_at`, `name` |
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
| `admins` | `{id, name}` — for `assigned_to` | global admins, or holders of `complaints.view` |

`filter[search]` (names, phone for drivers), `filter[city_id]` (restaurants, drivers,
zones), `filter[is_active]`, `limit` 1…200 (default 50). Sorted by name. **No paging, no
`meta.total`** — `meta.count` and `meta.truncated` (true when `limit` cut the list).

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

The catalogue the customer home opens on is built once and served from the server cache
until something it depends on changes. Every answer below carries a weak `ETag`; a request
whose `If-None-Match` matches gets **`304 Not Modified`** with no body and **without
running the query**.

| Endpoint | Server cache | `Cache-Control` | `Vary` |
|---|---|---|---|
| `GET /config` | 60 s | `private, max-age=0, must-revalidate` | `Accept-Language, Authorization` |
| `GET /cuisines`, `GET /store-categories`, `GET /cities`, `GET /areas`, `GET /cities/{c}/zones`, `GET /faqs`, `GET /pages/{slug}` | 300 s | `public, max-age=60` | `Accept-Language` |
| `GET /restaurants` (no `search`, pages 1–3) | 60 s | `public, max-age=30` | `Accept-Language` |
| `GET /restaurants/{r}`, `/categories`, `/products` (no `search`), `/best-sellers` | 60 s | `public, max-age=30` | `Accept-Language` |
| `GET /banners`, `GET /home-popups` | 60 s | signed-out: `public, max-age=30`; signed-in: `private, max-age=0, must-revalidate` | `Accept-Language, Authorization` |

- **Invalidation on write:** saving or deleting any `Restaurant`, `Product`, `MenuCategory`,
  `ProductOptionGroup`, `ProductOption`, `RestaurantOpeningHour`, `Cuisine`, `StoreCategory`,
  `Banner`, `HomePopup`, `City`, `ServiceZone`, `Faq`, `StaticPage` bumps the catalogue
  version; saving a `Setting` bumps the config version. The next read rebuilds. Time-driven
  facts (`is_open_now`, a banner's window) are at most one TTL late.
- Search requests and pages past 3 are never cached (unbounded keys on a database cache store).
- A `304` carries the same `ETag`, `Cache-Control` and `Vary`.
- `/config`'s `first_order.free_delivery_eligible` still follows the token.

## 6 · Throttles (new)

Every authenticated group now has a per-account limiter, sized for polling every 15 s with
room for navigation bursts. Refusal is the house envelope, `429`:

```json
{ "success": false, "error": "rate_limited", "message": "محاولات كثيرة جدًا، حاول لاحقًا",
  "meta": { "retry_after": 17 } }
```

(plus `Retry-After` and `X-RateLimit-*` headers).

One limiter, `api-user`, keyed by the account id; the limit follows the account type:

| Account type | Limit | Applies to |
|---|---|---|
| admin | 300 / min | `/admin/*` and the shared signed-in routes |
| restaurant | 240 / min | `/restaurant/*` and the shared signed-in routes |
| driver | 240 / min | `/driver/*` and the shared signed-in routes |
| customer | 180 / min | the customer routes (`/cart`, `/orders`, `/addresses`, `/favorites`, `/customer/*`, `/complaints`, `/scheduled-orders`, …) and the shared signed-in routes (`/notifications`, `/devices`, …) |
| `GET /search` (public, `public-search`) | 120 / min per account (signed in) · 300 / min per IP | |

Unchanged: the named limiters on sign-in, OTP, refresh, geo (owned elsewhere).
