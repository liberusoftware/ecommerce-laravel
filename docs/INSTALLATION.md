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

### ⚠ Do not run `db:seed` against a production database

`DatabaseSeeder` runs `DummyDataSeeder` inside its baseline chain, so seeding creates sample products. Ten of the thirteen writing seeders are also non-idempotent — running them twice duplicates data rather than reconciling it.

`UserSeeder` additionally prints the generated admin password to stdout. On a shared or logged terminal, treat that password as compromised and rotate it.

Both are recorded as findings in [`CONFORMANCE.md` §3.2–3.3](./CONFORMANCE.md#32-high) and fixed in wave 0 of the [migration plan](./MIGRATION_PLAN.md#wave-0--make-a-module-loadable-and-make-the-rules-enforceable).

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

**Set the keys in `.env`, not in a cached config path you then change.** `SubscriptionController.php:21` reads its key through `env()` inside the constructor, which under `config:cache` resolves to `null` rather than the configured value.

---

## Dropshipping (DropXL)

Configured through `config/dropshipping.php`. At checkout, selecting *"Ship directly to recipient (Drop shipping)"* routes the order through DropXL; supplier order placement is queued after a successful payment, and the order gains `supplier_id` and `supplier_reference`.

**Dropshipping requires a running queue worker.** Without one, orders sit at `supplier_queued` indefinitely — see [`OPERATIONS.md`](./OPERATIONS.md#orders-stuck-at-supplier_queued).

For local testing, point `DROPXL_API_URL` at a mock endpoint returning:

```json
{ "success": true, "data": { "id": "dropxl-123", "reference": "DLX-123" } }
```

`DropxlService.php:23` reads its API key via `env()` in the constructor, with the same `config:cache` caveat as Stripe above.

---

## After installation

Two panels are registered. `/admin` is the default panel; `/app` is the tenant-facing one. Both are described in [`OPERATIONS.md`](./OPERATIONS.md#the-admin-panels).
