# Deploying from Termux

`DEPLOY.md` is what runs **on the server**. This is how to drive it from an Android
phone.

`api.cr7-delivery.com` currently answers from **Hostinger** (`platform: hostinger`,
`panel: hpanel`, PHP 8.3.31), so Termux is the terminal, not the host. §4 covers running
the API on the phone itself, which is a useful staging trick and a bad production plan.

---

## 1. Termux, once

Install Termux from **F-Droid**, not Google Play — the Play build is frozen at an old
version and its package repository no longer resolves.

```bash
pkg update && pkg upgrade -y
pkg install -y openssh git rsync nano
termux-setup-storage        # only if you need /sdcard
```

A phone keyboard has no Ctrl. `pkg install -y termux-api` plus the Termux:Styles add-on
gives you the extra-keys row; without it, `nano` is unusable and `vi` is worse.

---

## 2. The SSH key

Generate it **on the phone**. A key you copied from the laptop is a key that now exists
in two places.

```bash
ssh-keygen -t ed25519 -C "termux@$(getprop ro.product.model)" -f ~/.ssh/id_ed25519
cat ~/.ssh/id_ed25519.pub
```

Paste that public line into hPanel → **Advanced → SSH Access → Manage SSH keys**. The
same panel shows the host, port and username; Hostinger uses a non-standard port, so
write it down.

```bash
cat >> ~/.ssh/config <<'EOF'
Host cr7
  HostName <the IP hPanel shows>
  User <the hPanel SSH user>
  Port <the hPanel SSH port>
  IdentityFile ~/.ssh/id_ed25519
  ServerAliveInterval 30
EOF
chmod 600 ~/.ssh/config

ssh cr7 'php -v && pwd'
```

`ServerAliveInterval` matters more here than anywhere else: Android kills idle sockets,
and a dropped connection in the middle of `php artisan migrate` is how a database ends up
half-migrated.

**Before any long command, take the wakelock** — from the Termux notification, «Acquire
wakelock». Without it the phone sleeps, the shell dies, and the deploy stops wherever it
happened to be.

---

## 3. The deploy

### 3.1 Push from the laptop, pull on the server

The repository is `github.com/AhmedRaoouf/cr7-delivery` and the branch is `main`.

```bash
ssh cr7
cd ~/domains/cr7-delivery.com/public_html      # whatever hPanel calls the docroot

php artisan down --render="errors::503"

git fetch origin
git checkout main
git pull --ff-only origin main

composer install --no-dev --optimize-autoloader

php artisan migrate --force

php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan up
```

`php artisan down` first and `up` last. Between them the API answers 503 instead of
answering with half-migrated tables.

Order matters in two places, and both have bitten before:

- **`config:cache` after every `.env` edit, never before.** A cached config ignores
  later `.env` edits, which is the usual reason a freshly pasted token appears to do
  nothing.
- **`migrate` before `config:cache`,** so a migration that reads config gets the live
  values rather than a stale compiled file.

### 3.2 If the server has no SSH

hPanel → **Git** → point it at the repository and branch, then press **Deploy**. That
only moves files; it runs no artisan command. So a deploy that touches a migration or an
`.env` key still needs a shell, and hPanel's **Terminal** is the fallback — it is the
same commands as §3.1 minus the `ssh cr7`.

### 3.3 First deploy after this branch

Two things in `main` are new since the server was last built:

1. **`UserResource` publishes an admin's `roles`, `permissions` and `scope`.** The Admin
   dashboard renders its navigation from those. Without this commit every operator sees
   an empty sidebar — no migration, no config, just the code.
2. **`OrderItem` appends `name`, `image` and `unit_price`** (doc 08 §5). The restaurant
   dashboard reads an order line by those names.

Neither needs a migration. `git pull` and the three `:cache` commands are the whole
deploy.

### 3.4 Demo data

`DemoDataSeeder` writes customers, captains, orders in every board state, complaints,
ratings and a settlement — enough for the dashboards to be worth looking at. It refuses
to run when `APP_ENV=production` unless `DEMO_SEED=true`, because inventing an order on a
live server invents a settlement line.

```bash
php artisan db:seed --class=DemoDataSeeder     # staging
php artisan demo:flush                          # removes exactly that set, nothing else
```

Re-running the seeder is a no-op: every row is keyed on a natural key beginning `DEMO`.

---

## 4. Running the API on the phone

Worth doing to demo the app with no internet, or to keep working when the server is down.
Not worth doing as production: Android will kill the process the moment it wants the
memory, and nothing restarts it.

```bash
pkg install -y php php-apache composer mariadb

mysqld_safe -u $(whoami) &
sleep 5
mysql -u root -e "CREATE DATABASE cr7_delivery CHARACTER SET utf8mb4;"

git clone https://github.com/AhmedRaoouf/cr7-delivery.git
cd cr7-delivery
composer install
cp .env.example .env
php artisan key:generate
```

Then edit `.env`:

```dotenv
APP_URL=http://<the phone's LAN IP>:8000
DB_HOST=127.0.0.1
DB_USERNAME=<your Termux username, from `whoami`>
DB_PASSWORD=

OTP_STATIC_MODE=true
OTP_STATIC_CODE=123456
MAPS_PROVIDER=osm
```

`APP_URL` has to be right **before** seeding: `CatalogSeeder` writes absolute image URLs
at seed time, so a wrong host bakes 63 broken images into the database.

```bash
php artisan migrate --seed
php artisan serve --host=0.0.0.0 --port=8000
```

`--host=0.0.0.0` is what makes it reachable from the laptop on the same Wi-Fi;
`127.0.0.1` binds to the phone alone. Find the address with `ip addr show wlan0`.

Three things will not work, and all three are the phone rather than the code:

- **Redis** — `pkg install redis` exists, but nothing keeps it alive. Leave
  `CACHE_STORE=database`.
- **The queue and the scheduler** — Termux has no supervisor and no cron. Run
  `php artisan queue:work` in a second session (swipe from the left edge → **New
  session**) and `php artisan schedule:run` by hand. Without the scheduler, orders sit in
  `waiting_for_driver` because the dispatch rounds never fire.
- **Reverb** — it holds a socket open, which is the first thing Android's doze mode
  closes.

---

## 5. Checking it worked

```bash
BASE=https://cr7-delivery.com/api/v1

curl -s $BASE/config | jq '{maps: .data.maps.provider, fee: .data.platform.order_fee}'
curl -s "$BASE/restaurants?per_page=3" | jq '.data | length'
```

Expected: `mapbox` · `5` · `3`.

Then the change this deploy is for — an admin's authority:

```bash
curl -s -X POST $BASE/auth/login \
  -H 'Content-Type: application/json' -H 'X-App: admin' \
  -d '{"login":"01000000099","password":"<the real one>"}' \
  | jq '.data.user | {roles, scope, permissions: (.permissions | length)}'
```

`roles` and `scope` present and `permissions` non-zero means the Admin dashboard has a
navigation to draw. `null` on all three means §3.1 pulled but did not `config:cache`, or
pulled the wrong branch.

`jq` is `pkg install jq`.

---

## 6. Before this server is public

`DEPLOY.md` §6 is the one to re-read. While `OTP_STATIC_MODE=true`, **every phone number
signs in with `123456`** and every pickup and delivery code is `1234`. That is correct for
a staging box and a full account takeover on a public one. The seeded passwords in
`DEPLOY.md` §7 are in the repository, so change them the same day.
