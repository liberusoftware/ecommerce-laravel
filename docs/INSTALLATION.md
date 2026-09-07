# Installation

Getting `ecommerce-laravel` running locally or on a server. Day-to-day running of an installed instance is in [`OPERATIONS.md`](./OPERATIONS.md).

---

## Requirements

- PHP **8.5+**
- Composer
- Node.js **20+**
- A database: MySQL, MariaDB or PostgreSQL
- Docker (optional — required only for option 3)

---

## Option 1 — Automated script

The repository ships an interactive setup script that walks through environment configuration, dependency installation, migration and seeding in one pass.

```bash
git clone https://github.com/liberusoftware/ecommerce-laravel.git
cd ecommerce-laravel
chmod +x setup.sh
./setup.sh
```

It prompts to copy `.env.example` to `.env`, confirms database credentials, runs `composer install`, generates the application key, migrates and seeds, and optionally starts the development server.

A graphical installer, where one is published for your platform, is on the [Releases](https://github.com/liberusoftware/ecommerce-laravel/releases) page.

---

## Option 2 — Manual

```bash
git clone https://github.com/liberusoftware/ecommerce-laravel.git
cd ecommerce-laravel
composer install
cp .env.example .env
php artisan key:generate
```

Configure `.env`:

```env
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password

STRIPE_KEY=pk_test_xxx
STRIPE_SECRET=sk_test_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx

DROPXL_API_KEY=Bearer xxx
DROPXL_API_URL=https://api.dropxl.com
```

Then:

```bash
php artisan migrate --seed
npm install && npm run build
php artisan serve
```

### ⚠ Seeding twice duplicates data

Ten of the thirteen writing seeders are non-idempotent — running them twice duplicates rows rather than reconciling them. Seed once, on a fresh database.

Two related faults have been fixed and are worth knowing about if you are on an older checkout:

- **`DummyDataSeeder` used to sit in the baseline chain**, so `db:seed --force` created sample products in production. It now runs only outside production.
- **`UserSeeder` used to print the generated admin password to stdout** unconditionally, and CI runs `db:seed --force` on every push. It now prints only under `APP_ENV=local`. **If you seeded a production or CI database before this change, treat that password as compromised and rotate it.**

Recorded in [`CONFORMANCE.md` §3.2–3.3](./CONFORMANCE.md#32-high), which is a snapshot and still describes the pre-fix state by design.

---

## Option 3 — Docker

```bash
git clone https://github.com/liberusoftware/ecommerce-laravel.git
cd ecommerce-laravel
cp .env.example .env          # update DB_ and app values
docker-compose up -d
docker-compose exec app php artisan migrate --seed
```

Laravel Sail also works for local development: `./vendor/bin/sail up -d`.

---

## Stripe

1. Add the three Stripe keys to `.env` (above). The publishable key must also be reachable through `config/services.php` — a missing entry there is the usual cause of a checkout that fails without a clear error.
2. Test with Stripe's test cards. `4242 4242 4242 4242`, any future expiry, any CVC, any postcode.
3. For webhooks, install the [Stripe CLI](https://stripe.com/docs/stripe-cli), set `STRIPE_WEBHOOK_SECRET`, and forward events to `/stripe/webhook`.

Keys are read through `config('services.stripe.*')`, so run `php artisan config:clear` after changing `.env` on a host with a cached config.

---

## Dropshipping (DropXL)

Configured through `config/dropshipping.php`. At checkout, selecting *"Ship directly to recipient (Drop shipping)"* routes the order through DropXL; supplier order placement is queued after a successful payment, and the order gains `supplier_id` and `supplier_reference`.

**Dropshipping requires a running queue worker.** Without one, orders sit at `supplier_queued` indefinitely — see [`OPERATIONS.md`](./OPERATIONS.md#orders-stuck-at-supplier_queued).

For local testing, point `DROPXL_API_URL` at a mock endpoint returning:

```json
{ "success": true, "data": { "id": "dropxl-123", "reference": "DLX-123" } }
```

The API URL and key are read through `config('services.dropxl.url')` and `config('services.dropxl.key')`, both fed from `DROPXL_API_URL` and `DROPXL_API_KEY`.

---

## After installation

Two panels are registered. `/admin` is the default panel; `/app` is the tenant-facing one. Both are described in [`OPERATIONS.md`](./OPERATIONS.md#the-admin-panels).
