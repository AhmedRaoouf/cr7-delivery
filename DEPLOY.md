# CR7 Delivery — deploying `api.cr7-delivery.com`

Everything below runs **on the server**, in the Laravel project root, in this order.
Skip nothing: step 3 is what turns an empty database into a working app.

---

## 0. What is in this branch

| | |
|---|---|
| Platform service fee | 5 EGP, once per order group (D-63…D-65) |
| Real catalogue | 8 restaurants · 69 categories · 562 products · images |
| Maps | Mapbox tiles + geocoding, published through `GET /config` (D-66) |
| Verification codes | one fixed code while there is no SMS gateway (D-67) |
| Captain applications | `APP-03` files an application, never an account |

---

## 1. Pull and install

```bash
git fetch origin
git checkout api-integration-2026-08-28
composer install --no-dev --optimize-autoloader
```

---

## 2. `.env`

Copy the new keys out of `.env.example`. The ones that matter:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://cr7-delivery.com      # the seeder builds image URLs from this

# --- maps -------------------------------------------------------------------
MAPS_PROVIDER=mapbox
MAPBOX_TOKEN=pk.xxxxxxxxxxxxxxxxxxxxxxxx   # a PUBLIC token — see §5
MAPBOX_STYLE_LIGHT=mapbox/streets-v12
MAPBOX_STYLE_DARK=mapbox/dark-v11

# --- verification codes -----------------------------------------------------
# Only the gateway lives here. Whether codes are real is `otp.mode` on ADM-46
# (doc 62, D-106) — see §6. OTP_STATIC_MODE / _CODE / _ORDER_CODE and
# OTP_ECHO_IN_RESPONSE are deleted; a line left over for them does nothing.
OTP_CHANNEL=log
```

`APP_URL` has to be right **before** step 3: `CatalogSeeder` writes absolute image
URLs at seed time, so a wrong host bakes 63 broken images into the database.

`MAPBOX_TOKEN` is the name that is read. `MAPBOX_ACCESS_TOKEN` also works — an
earlier commit documented that spelling — but prefer the first; there is one
token and it should have one name.

---

## 3. Migrate and seed

```bash
php artisan migrate --force
php artisan db:seed --force
```

That is the step the live server has not had yet. Right now
`GET /api/v1/restaurants` answers `{"data":[]}` — the API is up, the database is
empty.

Re-running the seeders is safe: every row is `firstOrCreate`d on a natural key.

---

## 4. Caches

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link      # once, for uploads
```

Run `config:cache` **after** editing `.env`, never before — a cached config
ignores later `.env` edits, which is the usual reason a new token appears to do
nothing.

---

## 5. The Mapbox token

1. https://account.mapbox.com/auth/signup/ — or sign in to an existing account.
2. https://console.mapbox.com/account/access-tokens/ — **Create a token**.
3. Name it `cr7-delivery-api`. Leave the **public** scopes as they come:
   `styles:tiles`, `styles:read`, `fonts:read`, `datasets:read`, `geocoding`.
   Do **not** tick any `write` scope, and do **not** create a secret (`sk.`)
   token — this one is published to every phone by design.
4. Paste the `pk.…` value into `MAPBOX_TOKEN`, then re-run step 4.
5. Confirm: `curl -s https://cr7-delivery.com/api/v1/config | jq .data.maps`
   → `"provider": "mapbox"`.

**Billing.** The free tier is 200 000 tile requests and 100 000 geocoding
requests a month. The app asks for 512 px tiles (one request covers four 256 px
ones) and the server caches every reverse lookup for 30 days keyed on
coordinates rounded to ~11 m, so a dragged pin costs one request, not one per
frame. Add a card at https://console.mapbox.com/account/ only when the usage
graph gets close; without one the account simply stops serving tiles rather than
running up a bill.

**Rotating it.** Change `MAPBOX_TOKEN`, run `php artisan config:cache`. Every
app picks the new one up on its next launch — no store release, because the
token is served from `/config` rather than compiled into the binary.

---

## 6. ⚠️ Verification codes — read before launch

Since doc 62 (D-106) the switch is on `ADM-46`, «التحقق والرسائل», not in `.env`:

| Setting | |
|---|---|
| `otp.mode` | `sms` (default) — a random code through `OTP_CHANNEL` · `test` — the operator's `otp.test_code`, **no SMS sent** |
| `auth.register_requires_otp` | off → sign-up is phone + name + password, no code |
| `otp.ttl_minutes` · `otp.resend_seconds` · `otp.max_attempts` · `otp.max_per_hour` | the clocks and caps |

While `otp.mode = test`:

- every customer, captain and place code is the test code (`123456` by default);
- `POST /auth/otp/send` returns it in its own response, and the app prints it on
  the OTP screen as «وضع التجربة».

**An admin's codes are never the test code** (D-107): an admin's reset and every
password change go through `OTP_CHANNEL` with a random code whatever the mode
says. Pickup and delivery codes are random, always (D-108) — there is no `1234`.

⚠️ **Right after deploying doc 62**, a server that ran with `OTP_STATIC_MODE=true`
is on `otp.mode = sms` — the catalogue default — and with the log still bound
nobody receives a code. Set `otp.mode = test` on `ADM-46` (or switch the
sign-up code off) the same minute, until the gateway delivers.

Test mode is correct for a staging server with no SMS gateway — the alternative is
an app nobody can sign up on. **It is a full account takeover of every non-admin
account on a public server.**

The SMS channel is built and bound: `SmsMisrChannel`, selected by
`OTP_CHANNEL=sms_misr`. **As of 2026-09-13 the gateway is proven on this server
and sign-in does not use it yet** — a test-environment send to the operator's
phone came back `1901`, SMSID `186639970`.

### 6.0 ZADX — the gateway production uses (D-159)

`ZadxChannel`, selected by `OTP_CHANNEL=zadx`. It posts the six-digit code to
`POST https://smsapi.zadx.net/api/v1/otp/send` — ZADX owns the message text — with
`X-Api-Key`, `X-Api-Secret` and a fresh `Idempotency-Key` per send.

```
OTP_CHANNEL=zadx
ZADX_API_KEY=pk_…
ZADX_API_SECRET=sk_…
ZADX_SENDER_ID=ZADX       # must be assigned to the app on the ZADX dashboard
```

```bash
php artisan config:cache
php artisan cr7:sms-doctor                       # balance + plan, free
php artisan cr7:sms-doctor 010XXXXXXXX --force   # one real OTP, spends one credit
```

Unlike SMS Misr, ZADX answers **real HTTP statuses**: `401` credentials, `402`
`no_active_subscription` / `quota_exhausted`, `403` `ip_not_allowed` /
`sender_id_not_allowed` / `app_inactive` / `mode_not_allowed`, `429` the per-number
(1/min, 5/hour) and per-app (60/min) limits, `502` the carrier. Every fault reaches
the log as `zadx.send_failed` (with `code` and `meaning`) and the caller as
`503 otp_send_failed`; a `429` becomes CR7's own `429 otp_send_rate_limited` with
ZADX's `Retry-After`. A message is on ZADX's «Messages» page by the `message_id` in
`zadx.response`. `zadx.low_credits` logs at ≤ 10 credits left. ZADX never resends,
and neither does CR7 — a timeout is `pending_verification` there, credits reserved.

The plan is a **credit balance** (`GET /sms/balance`); `ADM-46`'s gateway card shows
it. Keep `otp.daily_budget` / `otp.hourly_budget` below what the plan can pay for.

### 6.1 Prove the gateway without moving a customer

`cr7:sms-doctor` sends through the gateway's own channel directly (`ZadxChannel` when
`OTP_CHANNEL=zadx` or `--provider=zadx`, else `SmsMisrChannel`), never through the bound
channel, so everything below runs while customers still sign in as before.

```bash
php artisan cr7:sms-doctor                        # reads only; the balance lookup is free
php artisan cr7:sms-doctor 010XXXXXXXX            # one message; asks first
php artisan cr7:sms-doctor 010XXXXXXXX --sender=… # try another sender token for one run
```

Every fault on this path is silent — SMS Misr answers **HTTP 200 on failure**
with the fault in the body's `code`, and `POST /auth/otp/send` answers 200
either way — so do not skip it. The codes that matter:

| code | meaning | fix |
|---|---|---|
| `1901` | accepted (`/api/SMS/`) | — |
| `6000` | accepted (`/api/OTP/`) | — |
| `1903` | username or password wrong | re-copy from the SMS Misr console |
| `1904` | sender not approved | wait for approval, or use the test token at `ENVIRONMENT=2` |
| `1906` | out of points | buy a package |

### 6.2 `.env`

```
SMS_MISR_USERNAME=…
SMS_MISR_PASSWORD=…
SMS_MISR_SENDER=…          # the TEST token at ENVIRONMENT=2, the approved one at 1
SMS_MISR_ENVIRONMENT=2     # 2 = test, 1 = live
SMS_MISR_LANGUAGE=2        # an ENCODING: 1 is GSM-7 and cannot carry Arabic
```

SMS Misr's console prints these as `SMSMISR_USERNAME` — one word. Both spellings
are read, and an **empty** `SMS_MISR_*` line no longer hides a filled
`SMSMISR_*` one (Laravel reads `KEY=` as an empty string, not an absent key).
Run `php artisan config:cache` after every edit; the doctor reads the cache.

### 6.3 Going live — all in one sitting

Only once the production sender token is approved **and** the account holds
points (`cr7:sms-doctor` prints the balance):

1. `SMS_MISR_SENDER=<approved token>` and `SMS_MISR_ENVIRONMENT=1`.
2. `php artisan config:cache`, then `php artisan cr7:sms-doctor <your phone>` —
   and check that the message **arrived**, not only that it returned `1901`.
3. `OTP_CHANNEL=sms_misr`, then `php artisan config:cache`.
4. On `ADM-46`, `otp.mode = sms` (and `auth.register_requires_otp` on).
5. Sign up on the app with a real phone and a real code.

Steps 3 and 4 move together. Test mode with `sms_misr` bound sends nothing for
customers — only admins' codes go out. `otp.mode = sms` with `log` still bound
means nobody receives a code at all, and every such send logs a warning.

Nothing else changes: the app already hides the «وضع التجربة» note the moment
the server stops publishing a code, and stops prefilling the field.

While `OTP_CHANNEL` is not `sms_misr` in production, the app boots a warning into
the log: no SMS leaves, and an admin cannot change a password by SMS —
`php artisan cr7:reset-password <phone> --no-change-required` is the door.

---

## 7. Seeded credentials — change these

| Account | Phone | Password |
|---|---|---|
| Admin | `01000000099` | `Cr7Delivery#2026` (or `SEED_ADMIN_PASSWORD`) |
| Restaurant owners | see `database/seeders/CatalogSeeder.php` | the same |

All of them carry `must_change_password`, which the dashboards enforce — but the
values are in the repository, so change them the day the server is public.

---

## 8. Smoke test

```bash
BASE=https://cr7-delivery.com/api/v1

curl -s $BASE/config | jq '{maps: .data.maps.provider, fee: .data.platform.order_fee, otp: .data.auth.static_otp}'
curl -s "$BASE/restaurants?per_page=3" | jq '.data | length'
curl -s "$BASE/geo/reverse?lat=31.4472&lng=31.5142" | jq .data.address
curl -s -X POST $BASE/auth/otp/send -H 'Content-Type: application/json' \
     -d '{"phone":"01000000123"}' | jq .data.static_otp
```

Expected: `mapbox` · `5` · `"123456"` · `3` · an Arabic street name · `"123456"` —
the two codes only while `otp.mode = test`; `null` in `sms` mode.

An empty `restaurants` array means step 3 has not run.
A `null` under `maps.provider = osm` means the token is missing or `config:cache`
was run before the `.env` edit.

---

## 9. The scheduler — **required**

```bash
* * * * * cd /home/u136483260/domains/cr7-delivery.com/cr7-delivery && php artisan schedule:run >> /dev/null 2>&1
```

Set it in hPanel → **Advanced → Cron Jobs**. Verify it with:

```bash
php artisan schedule:list          # cr7:tick every minute, telescope:prune daily
php artisan cr7:tick --once -v     # run one pass by hand and read what it swept
```

> **Corrected again 2026-09-05, and this time in the other direction.** The note
> that used to sit here said «ordering works with no cron and no queue worker at
> all». That was true only of the path where a human presses every button on time.
>
> Four deadlines are written on every order — `prep_due_at`, the offer TTL, the
> restaurant's accept window, and the retry interval for an order nobody took —
> and until `cr7:tick` existed **not one of them was ever read**. A kitchen that
> forgot left the order in `preparing` forever. Three captains who let an offer
> lapse left it in `searching_driver` forever. An order that found nobody sat in
> `waiting_for_driver` until an operator reassigned it by hand, which is what the
> seventeen stranded orders on this deployment were doing.
>
> **Without this cron entry the order machine still stops.** It is not an
> optimisation and it is not only Telescope's pruning any more.

There is still **no queue worker and no `app/Jobs`**. `cr7:tick` is a plain
command; pushes go out `afterResponse()` in the same PHP process. Nothing here
needs supervisor.

---

## 9b. Push notifications — the service-account file

Push is the one feature that fails **silently and completely** when it is not
configured: `NotificationDispatcher` writes the in-app row first and swallows
every delivery error on purpose, so a missing credentials file looks exactly like
a phone that is switched off.

```bash
php artisan cr7:push-doctor              # says which of the three links is broken
php artisan cr7:push-doctor --to=<user>  # and sends a real one
```

The file goes at `storage/app/firebase/service-account.json` (or wherever
`FIREBASE_CREDENTIALS` points).

**It is a real secret.** Anyone holding it can send a notification to every phone
on the platform. It must never live under `public/` or `storage/app/public/`, must
never be committed, and should be `chmod 600`. Upload it over SFTP — not by
pasting it into a terminal that keeps history.

```bash
chmod 600 storage/app/firebase/service-account.json
```

---

## 9c. The public legal pages and the in-app copy (batch 72)

`/privacy`, `/terms`, `/delete-account` and `/support` are served by the API's own host (Arabic, then English, one URL
each) — the URLs to paste into the Google Play and App Store listings. Their text is
`app/Support/Legal/LegalContent.php`. ⚠️ **A human must review that text before store submission** (entity name, retention
promises, the 30-day erasure window, the governing-law sentence, the age limit).

The contact block on each page reads the `support.email`, `support.whatsapp`, `support.phone`, `support.phone_alt` and
`support.hours_note` settings — check them on the panel first: the seeded values are placeholders.

`GET /pages/privacy` and `/pages/terms` (the in-app copy) come from the `static_pages` table, which a server seeded
before batch 72 still holds in its old, shorter form. After the deploy:

```bash
php artisan content:sync-legal      # rewrites the terms + privacy rows only (not about, not the FAQ)
```

**Account deletion (written requests).** `DELETE /auth/account` is customers only and refuses with `409
active_order_blocks_deletion` while an order, errand or scheduled order is open. A customer who cannot open the app asks
in writing; once the phone number is verified the office runs `php artisan cr7:erase-account <phone>` — the same erasure
the app button runs. Captain and restaurant deletions stay manual (wallet, documents, settlements): the public page promises
them within 30 days, which is an **operator commitment**, not something the code enforces.

No migration. The `/img/{width}/…` resizer now also reads the uploads disk, so `public/img/` must stay writable by the
web user (it already had to be for the catalogue art).

## 10. Telescope

Telescope watches every request the API answers — the customer app, the captain
shell, both dashboards — and tags each entry with the client that made it, so
the list filters down to `app:admin`, `role:driver`, `status:422` or `failed`.

It runs on the deployed API, not only on a developer's machine. That is a
deliberate decision and it comes with a real cost, stated plainly in §10.3.

### 10.1 Turning it on

```bash
composer install --no-dev --optimize-autoloader   # telescope is a runtime require now
php artisan migrate --force                       # creates the four telescope_* tables

php -r "echo bin2hex(random_bytes(24)), PHP_EOL;" # the token — copy it
```

Then in `.env`:

```dotenv
TELESCOPE_ENABLED=true
TELESCOPE_RECORD_ALL=true
TELESCOPE_TOKEN=<the token from above>
TELESCOPE_DRIVER=database
TELESCOPE_RESPONSE_SIZE_LIMIT=512
```

```bash
php artisan config:clear && php artisan config:cache
```

Open it once with the token in the query string:

```
https://cr7-delivery.com/telescope?telescope_token=<the token>
```

It is remembered in an `httpOnly` cookie for eight hours, which is what lets
Telescope's own XHR calls to `telescope/telescope-api/*` through.

### 10.2 Reading it

The entry list has a tag filter. The tags a request carries:

| Tag | Where it comes from |
|---|---|
| `app:app` · `app:restaurant` · `app:admin` | `X-App` (doc 08 §1) |
| `role:customer` · `role:driver` | `X-Role` — the customer and the captain are one binary (D-45), so `X-App` alone cannot tell them apart |
| `platform:ios` · `platform:android` | `X-Platform` |
| `status:200`, `status:422`, … | the response code |
| `failed` | any 4xx or 5xx |
| `user:<id>` | the authenticated user, when there is one |
| `app:unknown` | no `X-App` header — curl, a scanner, a health check |

The dashboards call the API from their own Node server, never from the browser
(D-69), so `app:admin` means the admin dashboard, not the operator's laptop.

### 10.3 What it costs, and switching it off

**Whoever holds the token reads every request body the API received** — every
address, phone number, order and cart that moved through it. `Authorization`,
`Cookie`, passwords, OTPs and FCM tokens are redacted in every environment, and
the bodies are not. Treat `TELESCOPE_TOKEN` as a production credential.

A wrong token, a missing token, or `TELESCOPE_TOKEN=` empty answers **404** on
every Telescope URL. That is deliberate: a 403 tells a stranger there is a panel
here worth attacking.

`TELESCOPE_RECORD_ALL=true` writes a row per request, per query and per model
event, so the tables only grow. `routes/console.php` schedules
`telescope:prune --hours=48` daily — which needs §9's cron entry, and is the one
thing on this deployment that does. Without cron, run it by hand:

```bash
php artisan telescope:prune --hours=48
php artisan telescope:clear                # or wipe it entirely
```

When the test run is over:

```dotenv
TELESCOPE_ENABLED=false
```

then `php artisan config:cache`. The provider is not registered at all after
that — `AppServiceProvider` reads the flag before it loads anything.
