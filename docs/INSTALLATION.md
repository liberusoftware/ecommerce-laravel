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

### Seeding

The seeders reconcile rather than append: running `db:seed` again on a seeded database changes nothing (`FreshInstallTest` pins this).

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

### Products a supplier fulfils (Printful)

A product whose `supplier_id` is set (for example `printful`) is fulfilled by that supplier: an order
containing it goes there automatically, whatever the checkout form names, and only that supplier's
lines are sent. The checkout then requires the delivery name, city and postcode. Each variant is
sent by its `supplier_variant_id` (Printful's sync variant id) when set, otherwise by its SKU as
Printful's external id.

```dotenv
PRINTFUL_API_TOKEN=xxx
PRINTFUL_STORE_ID=          # only for an account-level token
```

`php artisan printful:import-products` imports the Printful store's sync products as made-to-order
products, one variant per Printful sync variant (Colour and Size), priced at Printful's retail price,
with its mockups in the gallery. Re-running it updates in place. `--product=<id>` imports one product,
and `--onto=<local id>` maps it onto an existing product, matching variants by their options. The
command is not scheduled by the core; a site that wants its catalogue kept in step schedules it.

Imports are uncategorised unless `dropshipping.suppliers.printful.categories` maps category names to
keywords, for example `'Headwear' => ['keywords' => ['cap', 'beanie']]`; the first category whose
keywords appear in the product or variant names is used, and `'default' => true` catches the rest.

---

## Where configuration lives

| What | Where | Who changes it |
| --- | --- | --- |
| Installation mode (`ECOMMERCE_MODE`: `single`, `saas`, `marketplace`), database, mail, queue, payment and DropXL credentials | `.env` | Whoever deploys |
| Platform name and contact, currency and language for new stores, whether a merchant may run several stores, which store serves the main site | `/admin` → Platform settings | Platform operator |
| A store's name, logo, contact details, address, currency, language, tax-inclusive display, accepted gateways, footer | `/app` → Store settings (or `/admin` → Stores) | The store's owner or team admins |

`ECOMMERCE_MULTIPLE_STORES_PER_TEAM`, `DEFAULT_CURRENCY`, `STORE_COUNTRY`, `STORE_STATE` and `TAX_DISPLAY_PRICES_WITH_TAX` are only starting values: they seed the platform settings and the first store when the database is created. After that the screens above are what count.

Payment credentials never appear in either settings screen; the screens show only whether each gateway is configured.

## Rebuilding a development database

The repository is pre-production and edits migrations in place rather than adding corrective ones, so an existing database does not pick up a changed migration. The supported installation path for the first release is a **fresh database**: `php artisan migrate` on an empty schema, which is what `setup.sh`, the Docker install and the `Install` CI workflow do.

A database migrated before the consolidation cannot be upgraded in place. `php artisan migrate` on one stops at `2026_10_23_000000_refuse_a_pre_release_schema` with an error naming the missing columns, before anything is written, rather than carrying on against a schema that, among other things, deletes order lines along with their product. Rebuild it:

```bash
php artisan migrate:fresh --seed
```

This drops every table, data included. There is no upgrade path for data in such a database; export anything worth keeping first. Seeding is safe to repeat on the rebuilt database.

## After installation

Two panels are registered. `/admin` is the default panel; `/app` is the tenant-facing one. Both are described in [`OPERATIONS.md`](./OPERATIONS.md#the-admin-panels).
