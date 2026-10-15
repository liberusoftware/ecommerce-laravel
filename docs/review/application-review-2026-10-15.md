# Application review — 2026-10-15

Scope: the whole application at `bbfc853` (local `main`, four commits ahead of `origin/main`, none pushed).
Method: read the code and docs, traced behaviour, and ran throwaway probe tests against a fresh
SQLite install and read-only queries against the local MySQL database. Nothing was changed except
adding this file and [the development plan](development-plan-2026-10-15.md).

Status words: **Confirmed** = reproduced or read unambiguously in code; **Suspected** = strong evidence,
not reproduced; **Improvement** = not a defect.

---

## A. Executive summary

The application is large (about 100 models, two Filament panels, REST, GraphQL, 235 test files, 1418
passing tests) and much of its hardening is real: checkout reserves stock atomically and charges outside
the transaction, order status changes go through a state machine, the storefront and API are
host-scoped, and PHPStan is clean.

**A fresh install isn't usable from the back office.** The seeders create the catalogue, menus and
collections with `team_id = NULL` because the default store has no owning team. Both Filament panels
filter resources by `team_id`, so the seeded admin sees empty Products, Categories, Menus and Menu
Items pages, even though the storefront shows all 21 products. That's why your products and categories
seemed to be missing. `MULTITENANCY=false` has nothing to do with it: no code reads that variable.

The biggest remaining risks:

1. **The panels never update the user's current team** (`SwitchTeam` is an empty listener). The store
   scope relies on that value, so in the panels it can apply no filter at all or the wrong team's stores.
2. **Merchants can edit money and payment state directly.** The `/app` order form saves `payment_status`
   and `total_amount` without going through the state machine, so orders can become "paid" with no
   history row and no invoice, and be counted as revenue.
3. **Deleting a customer or an order permanently erases financial records.** Hard deletes cascade to
   paid orders, order items, invoices and refunds.

**Readiness:** the storefront is in a reasonable state for a demo. The back office isn't ready for its
intended use until F-01, F-03, F-04 and F-05 are fixed. None of them needs an architectural change.

---

## B. Application map

### Layout

| Path | What it is |
|---|---|
| `app/` | The application. `CLAUDE.md` says `src/`; that is out of date (F-16). |
| `app/Models` | ~100 Eloquent models |
| `app/Filament/Admin` | `/admin`, platform back office, `super_admin` only (`User::canAccessPanel`) |
| `app/Filament/App` | `/app`, merchant back office, any user with a team; `strictAuthorization()` |
| `app/Http/Controllers` | Blade storefront, Stripe/PayPal webhooks, `Api/*` REST |
| `app/GraphQL/StorefrontSchema.php` | Code-first GraphQL storefront and headless checkout |
| `app/Services` | `CheckoutService` (money core), `StoreContext`, `ChannelResolver`, analytics, tax, shipping, GDPR |
| `modules/module-manager` | Installed by Composer; not committed, not registered as a provider |
| `modules/ecommerce-*` | 267 unloaded copies of `app/` code (F-07) |
| `modules/module-ecommerce-warranty-and-claims*` | Git submodules, loaded through Composer path repositories |

### Tenancy model (as built)

```
Request host ──ResolveChannel──▶ Channel ──▶ Store ──▶ Team (nullable owner)
                                              │
Commerce rows: store_id (IsStoreScoped global scope)   team_id (IsTenantModel write hook only)
Filament panels: resources filtered by team_id (Filament tenancy, ownershipRelationship 'team')
```

- **Store scope** (`app/Services/StoreContext.php`):
  - Storefront and API requests use the store resolved from the host. `web` and `api` both run
    `ResolveChannel`, which returns 404 for an unknown host.
  - In the panels, the scope uses every store owned by the user's `current_team_id`. A team with no
    store falls back to the deployment's only store if that store has no owner, otherwise it sees
    nothing (`a25d78f`, `bbfc853`).
  - With no logged-in user (console, queues) the scope applies no filter.
- **Team scope:** applied only by Filament, and only to models that have a resource in the panel
  being served.
- **Roles:** Spatie with `teams => false`, so roles are global (F-15). Shield generates the policies.

### Roles

| Role | Where it comes from | Reaches |
|---|---|---|
| `super_admin` | `UserSeeder` | `/admin`, `/app`, and webhook-endpoint API |
| `admin` | referenced in code, not seeded | the same, if assigned |
| Team member | Jetstream team membership | `/app`, subject to Shield permissions |
| Shopper | `CreateNewUser` (no team is created) | storefront, account pages, own-data API |

### Main workflows

- **Browse:** home, products, categories, collections, tags, search and sitemap, all scoped to the
  store resolved from the host.
- **Cart and checkout:** session cart (`CheckoutController`) or GraphQL `CartItem` cart
  (`HeadlessCheckoutService`). Both use `CheckoutService` to revalidate coupons, reserve stock, take
  payment and transition the order.
- **Payment events:** Stripe and PayPal webhooks drive `Order::transitionTo()`. Reaching `paid`
  generates an invoice and fires outbound webhooks.
- **Back office:** catalogue, orders, customers, invoices, reviews and coupons in `/app`; platform
  configuration, stores, users and roles in `/admin`.
- **Returns and refunds:** API approve and receive, then `Refund` uses `PaymentGatewayService::refundPayment`.

---

## C. Findings register

Severity is ordered by priority. Already-fixed issues (#1–#4a, commits `73089c8`…`bbfc853`) are not
repeated.

### F-01 — Seeded catalogue is invisible in both back-office panels
- **Severity:** High. **Status:** Confirmed. **Area:** fresh install, seeding, tenancy.
- **Evidence:**
  - The migration `2026_08_08_000001_create_the_initial_channel.php` creates store 1 before any team
    exists, so `stores.team_id = NULL`.
  - `DefaultTeamSeeder` creates team 1 but never assigns store 1 to it.
  - `UserSeeder` attaches the admin to team 1 without setting `current_team_id`.
  - `DummyDataSeeder` and `MenuSeeder` run with nobody logged in. `StoreContext::teamForWrites()`
    derives the team from store 1, which has no owner, so every row gets `team_id = NULL`.
  - Your MySQL database: products `{team_id: null, store_id: 1} × 21`, categories × 7, collections × 4,
    and `menus`/`menu_items` with `team_id` null.
  - SQL captured on `/admin/1/products`: `where products.team_id in (1)`, giving 0 rows. An isolated
    `/app/1/products` request applies the same filter.
- **Impact:** after following the install docs, the admin sees empty Products, Categories, Menus,
  Menu Items and Collections pages. The rows exist and the storefront shows them. Anything created
  later by a seeder, a console command or a queued job has the same problem.
- **Resolution:**
  - Make the seeded default team own the default store before any catalogue is seeded.
  - Set the admin's `current_team_id` to that team.
  - Seed order then produces `team_id = 1` with no further changes, because `teamForWrites()` derives
    the team from the store.
  - For databases that already exist, document a re-seed, or provide a one-off backfill that copies
    `stores.team_id` onto rows that have a `store_id` but no `team_id`.
- **Dependencies:** none. It interacts with F-03 (the admin's current team).

### F-03 — Panels never update `current_team_id`; the store scope relies on it
- **Severity:** High. **Status:** Confirmed (stub, null value); Suspected (mismatch scenario).
  **Area:** tenancy, authorization.
- **Evidence:**
  - `app/Listeners/SwitchTeam.php::handle()` is empty (`// dd($event);`), yet `AppPanelProvider::boot()`
    registers it for `TenantSet`.
  - `StoreContext::panelTeamId()` reads `current_team_id`, and its docblock says "both panels switch it
    when the tenant changes". They don't.
  - Probe: after the seeded admin visited `/admin/1` and `/app/1`, `current_team_id` was still `NULL`.
- **Impact:**
  - **Null `current_team_id`:** the store scope applies no filter at all in the panels. Widgets, relation
    managers and dropdown options for models without a panel resource (for example `Customer` in
    `/admin`) then span every store.
  - **Stale `current_team_id`:** suppose a user who belongs to teams A and B switches the Filament tenant
    to B. Resources would then be filtered by team B *and* by A's stores (giving empty lists), and
    widgets would show A's data under B's name. This is suspected from the code, not reproduced.
- **Resolution:** implement the listener (`$user->switchTeam($event->getTenant())`, guarded by
  `belongsToTeam`). Optionally, have `StoreContext` prefer `Filament::getTenant()` while a panel is
  being served. Add tests for both.
- **Dependencies:** best done right after F-01.

### F-04 — `/app` order form edits `payment_status` and `total_amount` directly
- **Severity:** High. **Status:** Confirmed. **Area:** order integrity, money.
- **Evidence:**
  - `app/Filament/App/Resources/Orders/OrderResource.php::form()` exposes `payment_status` (pending,
    paid, cancelled), `total_amount`, `shipping_status`, `order_date` and `customer_id`.
  - `EditOrder` saves those fields directly. `payment_status` is in `Order::$fillable`.
  - Probe: saving `payment_status=paid` and `total_amount=1` on a 50.00 order left `status=pending`,
    `payment_status=paid`, `total=1.00`, no status-history row and no invoice.
  - `CreateOrder` allows an order with no items and any total.
- **Impact:** the status and payment status disagree. Revenue analytics (which filter on
  `payment_status = 'paid'`) count unpaid orders. There's no audit trail, no invoice and no outbound
  webhook. The order total can be rewritten after payment. "cancelled" isn't a value the state machine
  ever writes to `payment_status`.
- **Resolution:**
  - Make `payment_status`, `total_amount` and `status` read-only in the form.
  - Add header actions that call `Order::transitionTo()`, using the allowed transitions in
    `Order::TRANSITIONS`.
  - Restrict or remove manual order creation, or require line items.
- **Dependencies:** none.

### F-05 — Hard deletes cascade through financial records
- **Severity:** High. **Status:** Confirmed. **Area:** data integrity.
- **Evidence:**
  - Foreign keys: `orders.customer_id → customers ON DELETE CASCADE`; `order_items.order_id`,
    `invoices.order_id`, `refunds.order_id` and `return_requests.order_id` all cascade;
    `invoices.customer_id` cascades; `orders.team_id` and `invoices.team_id` cascade from `teams`.
  - `Customer` and `Order` aren't soft-deleted.
  - `/app` has `DeleteAction` and `DeleteBulkAction` on Customers (`EditCustomer`, `CustomerResource`),
    Orders (`EditOrder`, `OrderResource`) and Invoices (`InvoiceResource`). They're gated only by
    `delete_*` permissions (`CustomerPolicy::delete`, `OrderPolicy::delete`).
- **Impact:** one bulk delete of customers permanently removes their paid orders, invoices and refunds.
  Deleting a team removes all its orders and invoices. That breaks accounting, VAT reporting
  (OSS/EC sales list) and dispute handling.
- **Resolution:** decide the retention policy, then pick one or more of:
  - forbid deleting customers that have orders, and orders that are paid or later (in the policies);
  - soft-delete customers and orders;
  - change those cascades to `restrictOnDelete`. This is pre-production, so edit the migrations in place.

  Invoices should never be deletable.
- **Dependencies:** a product decision on retention.

### F-06 — Logged-in user opening `/login` or `/register` lands on a 404
- **Severity:** Medium. **Status:** Confirmed. **Area:** auth workflow.
- **Evidence:** `RedirectIfAuthenticated` redirects to `RouteServiceProvider::HOME = '/home'`, and no
  `/home` route exists (`route:list --path=home` finds none). Probe: `/login` as an authenticated user
  gives 302 to `/home`, which returns 404.
- **Resolution:** point `HOME` at `/` (or reuse `LoginResponse`'s role-based targets). Add a test.

### F-07 — `modules/ecommerce-*` holds 267 unloaded copies of `app/` code
- **Severity:** Medium. **Status:** Confirmed. **Area:** maintainability.
- **Evidence:**
  - Commit `d166bec` added 13,694 lines under 13 `modules/ecommerce-*` folders.
  - The files are line-for-line copies of `app/` with renamed namespaces (for example, `Product.php`
    and `Order.php` differ in zero lines).
  - Nothing autoloads or requires them; `Liberu\Ecommerce\Core\ServiceProvider` doesn't resolve.
  - They import classes that don't exist in those modules (for example
    `Liberu\Ecommerce\ProductCatalog\Traits\IsStoreScoped`), so they couldn't load even if registered.
  - CLAUDE.md says `modules/` holds the module manager only.
- **Impact:** about 13.7k lines of drift-prone duplicates (none of this session's fixes are in them),
  misleading search results, and a risk that someone edits the wrong copy.
- **Resolution:** delete them. Extraction is meant to happen in separate repositories, as with
  warranty-and-claims.

### F-02 — `MULTITENANCY=false` is not read by anything
- **Severity:** Medium (it misleads operators). **Status:** Confirmed. **Area:** configuration.
- **Evidence:**
  - Added to `.env.example` in `41f77d5` (a docs commit). No PHP, YAML or shell file in any commit
    of the history has ever read it.
  - Tenancy is switched on unconditionally by `Features::teams()` in `config/jetstream.php`, which
    both panel providers check.
- **Impact:** operators believe tenancy can be switched off. It can't.
- **Resolution:** remove the variable from `.env.example`, and document that tenancy is always on.
  Don't build a switch without a design.

### F-08 — `allow_backorders` and `is_preorder` don't affect checkout
- **Severity:** Low. **Status:** Confirmed. **Area:** catalogue rules.
- **Evidence:** both are columns on `products`, but nothing in `app/` reads them for stock decisions.
  `CheckoutService::reserveStock()` always refuses when `inventory_count < quantity`.
- **Resolution:** decide whether these features exist. Either implement them in `reserveStock()`, or
  hide the fields.

### F-09 — Home page runs one rating query per product
- **Severity:** Low. **Status:** Confirmed. **Area:** performance.
- **Evidence:** a probe of `/` after a fresh seed ran 20 queries, 10 of them identical
  `select avg(rating) from product_rating where product_id = ?`. The other storefront pages run 6–7.
- **Resolution:** eager-load the averages with `withAvg('ratings', 'rating')` for both home-page lists
  (`HomeController`, `$withRatings`).

### F-10 — Invoices table shows a blank customer column
- **Severity:** Low. **Status:** Confirmed. **Area:** `/app` UI.
- **Evidence:** `InvoiceResource` uses `TextColumn::make('customer.name')`, but `Customer` has
  `first_name`/`last_name` and no `name` accessor.
- **Resolution:** use a state callback, or add a `name` accessor.

### F-11 — Placeholder payment and shipping code
- **Severity:** Low. **Status:** Confirmed. **Area:** payments, shipping.
- **Evidence:**
  - `StripeGateway::processSubscription()` returns `success: true` with an invented `stripe_sub_…`
    id. It's reachable only through `PaymentGatewayService::processSubscription()`, which has no
    caller.
  - `ShippingService::calculateDistanceRate()` always returns 0.
- **Resolution:** make the Stripe stub fail closed, as `PayPalGateway::processSubscription()` already
  does, or delete it. Document that distance pricing isn't implemented.

### F-12 — Dependencies with published advisories
- **Severity:** Low. **Status:** Confirmed. **Area:** dependencies.
- **Evidence:** `composer audit` reports:
  - `filament/filament` 5.8.0 (affected below 5.8.2): MFA management actions don't require
    re-authentication. The app doesn't use Filament MFA.
  - `league/commonmark` 2.10.1: a high-severity GFM denial-of-service and a medium raw-HTML bypass.
    The app doesn't render user-supplied markdown.
- **Resolution:** upgrade both through the repo's `composer-require.yml` workflow, at low priority.

### F-13 — In-process tests can lose the second panel's tenancy scope
- **Severity:** Low. **Status:** Suspected for production; Confirmed in the test harness.
  **Area:** test reliability.
- **Evidence:**
  - `FilamentManager::bootCurrentPanel()` boots only once per manager instance (the
    `isCurrentPanelBooted` flag).
  - Probe: in one test, a `/admin` request followed by an `/app` request produced `/app` SQL with no
    `team_id` filter, and `app_tenancy` was never registered. An isolated `/app` request was scoped
    correctly.
  - `filament` is a `scoped` binding, so Octane flushes it between requests. I couldn't run Octane to
    confirm production is unaffected.
- **Impact:** a test that requests both panels can pass while tenancy is missing.
- **Resolution:** keep each test to one panel, or call `Filament::getPanel(...)->boot()` explicitly as
  `TopProductsWidgetTest` does. Optionally, verify under Octane.

### F-14 — Outbound webhooks are deployment-wide
- **Severity:** Improvement.
- **Evidence:** `WebhookEndpoint` has no `store_id` or `team_id`. `DispatchOutboundWebhook` sends every
  store's order events to every active endpoint. Only `super_admin`/`admin` can manage endpoints
  (`WebhookEndpointController::authorizeStaff`).
- **Impact:** it works for a platform operator. Merchants can't have their own endpoints.
- **Resolution:** add `store_id` and a store scope if merchants need webhooks.

### F-15 — Spatie team permissions are off; team calls are no-ops
- **Severity:** Improvement.
- **Evidence:** `config/permission.php` has `teams => false`, yet `LoginResponse`, `RegisterResponse`
  and `TeamsPermission` call `setPermissionsTeamId()`, which does nothing.
- **Impact:** a role applies in every team the user belongs to. Membership and Filament tenancy still
  limit which records they reach.
- **Resolution:** remove the dead calls, or deliberately enable team-scoped roles.

### F-16 — Documentation drift
- **Severity:** Low. **Status:** Confirmed. **Area:** docs and tooling.
- **Evidence:**
  - `CLAUDE.md` says the app lives in `src/` (it's at the root) and that "neither PHP nor Composer
    exists on this machine" (both do).
  - It refers to `/home/tom/...` brief paths.
  - It says "No `Store` model or `stores` table exists yet" in `CONTEXT.md` §Store warning.
  - It says `StoreResource` models `Team`; it now uses `Store`.
  - **New evidence about my own commit `a25d78f`:** its message and `MIGRATION_PLAN.md` §352 say
    "every Jetstream personal team is one" store-less team. In fact `CreateNewUser` creates no team for
    shoppers. Store-less teams come from the seeded default team and from `/app` CreateTeam
    (`App\Actions\Jetstream\CreateTeam` switches to the new team but creates no store). The fix is
    unaffected; only that sentence overstates.
- **Resolution:** correct those statements.

### F-17 — Seeder fragility
- **Severity:** Low. **Status:** Confirmed. **Area:** seeding.
- **Evidence:**
  - `DefaultTeamSeeder` hard-codes `id => 1, user_id => 1` before any user exists. Outside `local`
    without `SEED_ADMIN_PASSWORD`, `UserSeeder` creates no user, so team 1 is owned by a user who
    doesn't exist.
  - It imports `App\Models\Branch`, which doesn't exist.
  - `TeamSeeder` is never called.
- **Resolution:** create the team from the admin user (or a nullable owner), drop the dead import, and
  delete `TeamSeeder`.

### F-18 — Inventory log values are read outside the guarded decrement
- **Severity:** Low. **Status:** Confirmed (by reading the code). **Area:** inventory audit.
- **Evidence:** `CheckoutService::reserveStock()` and `releaseStock()` read `inventory_count` before the
  atomic update and write `old_quantity`/`new_quantity` from that read.
- **Impact:** under concurrent checkouts the audit rows can disagree with the real sequence. Stock
  itself stays correct.
- **Resolution:** lock the row (`lockForUpdate`) inside the transaction, or record only
  `quantity_change`.

### F-19 — Pint baseline
- **Severity:** Low. **Status:** Confirmed, known.
- **Evidence:** 297 files fail `pint --test` across the whole tree. CI checks only changed files.
- **Resolution:** keep the per-file ratchet. No bulk reformat.

---

## D. Fresh-install findings

**What should happen** after `setup.sh` (or migrate, then `db:seed`) on a blank database:

- one store served at `localhost`;
- a default team that owns it;
- an admin who belongs to that team;
- 21 demo products, 7 categories, 4 collections and a main menu, visible and editable both on the
  storefront and in `/admin` and `/app`.

**What actually happens.** A throwaway probe ran migrate plus the real `DatabaseSeeder` on SQLite, and
its row counts matched your MySQL database exactly.

| Check | Result |
|---|---|
| `stores` | 1 row, `team_id = NULL` |
| Products / categories / collections | 21 / 7 / 4, all `team_id = NULL`, `store_id = 1` |
| Admin `current_team_id` | `NULL`, still `NULL` after visiting both panels |
| Storefront `/`, `/products`, `/categories/electronics/products`, product page | 200, products shown |
| `/admin/1/products` | 200, **empty**. SQL: `products.team_id in (1)` |
| `/app/1/products` (isolated request) | **empty**, same filter (see F-13 for the in-process artefact) |
| `/admin/1/categories`, menus, menu items | empty, same cause |
| Every `/admin` and `/app` list, create and custom page | 200; no server errors anywhere |
| `/login` while logged in | 302 to `/home`, then **404** (F-06) |

**Why products and categories seem to be missing:**
- The migration creates the default store before any team exists, and no seeder later gives it an owner.
- `IsTenantModel` derives `team_id` from the store's owner. With no owner and nobody logged in, every
  seeded row gets `team_id = NULL`.
- The panels show only rows whose `team_id` matches the current team.

Nothing is lost; the rows are simply unattributed.

**Not the cause:**
- `MULTITENANCY=false` (F-02): unused.
- Cached config: `bootstrap/cache` has no `config.php`.
- Pending migrations: none.
- This session's `StoreContext` commits: before them, the team filter already hid these rows.

**Your current database** can be corrected by re-seeding after F-01 is fixed (`migrate:fresh --seed`
deletes all data, so that's your decision), or by a one-off backfill that sets store 1's `team_id = 1`
and copies it onto every row with `store_id = 1` and no `team_id`.

---

## F. Test and verification summary

| Check | Result |
|---|---|
| `php artisan test` (full suite, SQLite) | 1418 passed, 3480 assertions |
| PHPStan (level 0, whole tree) | No errors |
| Pint, files changed in this session | Pass |
| Pint, whole tree | 297 files fail; pre-existing, not touched |
| `composer audit` | 3 advisories, 2 packages (F-12) |
| `migrate:status --pending` (MySQL, read-only) | No pending migrations |
| Read-only MySQL queries | Row counts and `team_id`/`store_id` attribution, stores, users, roles, menus |
| Throwaway probes (scratchpad only, not committed) | Fresh install + seed + crawl of every storefront and panel page; panel SQL capture; scope registration; order-form save; logged-in `/login`; home-page query counts; store-less `/app` crawl |

**Could not be verified safely or at all:**
- Behaviour under Octane/Swoole (F-13).
- Real Stripe, PayPal, DropXL and Facebook calls; there are no credentials, and the tests stub them.
- Mail delivery.
- Browser-level JavaScript (Livewire interactions beyond what `Livewire::test` covers).
- Anything that would write to your MySQL database.

### Coverage assessment

The suite is strong on checkout money paths, tenancy scoping per model (`StoreScopeCoverageTest`,
`TenantModelSchemaTest`), GDPR, tax, webhooks and authorization hardening. Gaps that let this review's
findings through:

| Gap | Recommended regression test |
|---|---|
| Nothing seeds `DatabaseSeeder` and checks panel visibility (`DummyDataSeederTest` checks only catalogue shape) | Seed, log in as the seeded admin, and assert `/admin/{team}/products` lists the seeded products (F-01) |
| No test of Filament tenant switching against `current_team_id` | Visit `/app/{teamB}` and assert `current_team_id` and the store scope follow (F-03) |
| Order edit form | Assert the form can't change `payment_status`/`total_amount`, and that transition actions write history and an invoice (F-04) |
| Delete safety | Assert a customer with orders and a paid order can't be deleted, and invoices survive (F-05) |
| Guest-only redirects | `/login` and `/register` while logged in reach a 200 page (F-06) |
| Query budget | The home page stays under N queries as products grow (F-09) |
