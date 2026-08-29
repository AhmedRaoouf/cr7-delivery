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
# These two move TOGETHER. See the warning in §6.
#   log      + true   staging: everyone signs in with OTP_STATIC_CODE
#   sms_misr + false  live:    real code, real SMS
# `log` + `false` is the broken pair — the code is random and written only to
# storage/logs, so /auth/otp/send answers 200 and nobody can sign in at all.
OTP_CHANNEL=log
OTP_STATIC_MODE=true
OTP_STATIC_CODE=123456
OTP_STATIC_ORDER_CODE=1234
OTP_ECHO_IN_RESPONSE=true
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

While `OTP_STATIC_MODE=true`:

- **every** phone number signs in with `123456`;
- every pickup and delivery code is `1234`;
- `POST /auth/otp/send` returns the code in its own response, and the app prints
  it on the OTP screen as «وضع التجربة».

That is correct for a staging server with no SMS gateway — the alternative is an
app nobody can log into. **It is a full account takeover on a public server.**

To turn it off, bind a real channel and flip one flag:

1. Write an `App\Services\Otp\OtpChannel` implementation for the SMS provider.
2. Bind it in `AppServiceProvider::register()` in place of `LogOtpChannel`.
3. `OTP_STATIC_MODE=false`, then `php artisan config:cache`.

Nothing else changes: the app already hides the «وضع التجربة» note the moment
the server stops publishing a code, and stops prefilling the field.

While static mode is on, the app boots a warning into the log on every request
cycle in production. That line is the reminder — do not filter it out.

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

Expected: `mapbox` · `5` · `"123456"` · `3` · an Arabic street name · `"123456"`.

An empty `restaurants` array means step 3 has not run.
A `null` under `maps.provider = osm` means the token is missing or `config:cache`
was run before the `.env` edit.

---

## 9. Queue and scheduler

```bash
php artisan queue:work --queue=default --tries=3    # under supervisor
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

The dispatch rounds, the prep-time countdown and the settlement generator are all
scheduled jobs. Without the cron entry, orders sit in `waiting_for_driver`.
