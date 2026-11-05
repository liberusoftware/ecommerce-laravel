# Module architecture: assessment and plan

_Assessed 2026-11-04 against core `main` (`5b3a0c67`), the five site repositories and the published
`liberusoftware` packages. Reassessed the same day after merging `upstream/main` (`e6b55076`) into the
core. Paths are relative to the repository root._

**Status: proposal.** Nothing here is implemented, apart from the one-line CI guard fix in §7.3.
Sections 1 and 2 are findings from the code. Sections 3 to 9 are recommendations.

## 1. Summary

**The fleet has the parts of a sound module system, but this host does not run them.**

- `liberusoftware/module-manager` is installed and never booted. Its provider is not registered, so
  `MODULES_ENABLED` and `MODULES_DISABLED` do nothing, and no `module:*` command exists.
- `users-roles` and `tenancy`, the only modules that do run, get there through Laravel package
  discovery, which bypasses the manager. Setting `MODULES_DISABLED` does not stop either of them.
- The 20+ `module-ecommerce-*` packages from the migration waves are not installed, and the host's
  own code still does that work. They have their own schema, and at least one has a manifest the
  manager rejects.
- Printful and DropXL are built into the core with hard-coded branches. One set of credentials
  serves the whole deployment, so per-store use is not possible yet.
- The sync job publishes from a host into package repositories. That is the opposite of
  independently maintained modules, and it should be retired rather than repaired.

**The recommendation is deliberately small:**

1. Boot the manager that already exists.
2. Install module code only through Composer and deploys, never from an admin request.
3. Add one table for per-store activation and settings.
4. Add one small contracts package as the stable extension point.
5. Put Printful and DropXL behind that contract while they are still in core. Extract them only
   after that works.

**Three decisions need an owner before work starts** (§9.1). The most important: the current
upstream direction keeps module source copies in the host, and that conflicts with this objective.

## 2. Assessment of the current architecture

### 2.1 Previous attempts, and why each stopped short

| Attempt | Evidence | What happened | Lesson |
| --- | --- | --- | --- |
| `app/Modules/` with a `modules` table | Deleted in `1ae0fc09`; [ADR 0011](./adr/0011-adopting-module-manager.md) | Runtime enable and disable existed, but only a console command could reach it. An unknown module, or a database error, counted as enabled | Default to off. Do not re-register providers from database state |
| Namespace-renamed copies in `modules/ecommerce-*` | Added in `d166bec7`, removed in `1e0194b7` | 267 files that nothing loaded, and could not have loaded | A copy in the tree is not a module |
| Git submodules with path repositories | Added in `39ec7166`, removed in `17ac5f4a` | A plain clone broke package discovery, and the Docker build could not resolve them. Nine orphaned tables stayed behind | Do not use submodules. Removal needs a defined path for data |
| `module-manager` adoption | `1ae0fc09`, 2026-08-09 | **Installed but never registered.** No commit has ever added its provider, and the package has no auto-discovery | See §2.2 |
| Migration waves: `module-ecommerce-*` at `0.1.0` | `docs/MIGRATION_PLAN.md`; root `composer.json` | Built and tagged, each with its own suite, and never consumed by a host | Passing in a package's own repository does not prove a host can use it |
| Path repository for `modules/users-roles` | `aff1c60f` | Needed a Dockerfile fix (`f4a52427`). Also carries `extra.laravel.providers` | Breaks the fleet's "install does not boot" rule |
| Host-to-package publishing | `f87e8b2d`, extended on 2026-11-02 and now merged | See §7 | Publishes the wrong way round |
| `modules/tenancy`, tracked and also required from Packagist | `39bbeacf`, merged from upstream | Installed and booted by package discovery. `IsTenantModel` now depends on it | The core now needs a module that the manager neither loads nor knows is required (§2.3) |

### 2.2 The module manager does not run

I probed the merged tree on a read-only mount, with `MODULES_DISABLED=users-roles,tenancy` set:

```text
manager registered at boot:     no
users-roles registered at boot: yes
tenancy registered at boot:     yes
module:list command exists:     no
after manual register, resolved: module-manager      <- both correctly excluded, yet both still running
```

- `config/app.php` does not list `ModuleManagerServiceProvider`, and there is no
  `bootstrap/providers.php`. The reference host registers it in `bootstrap/providers.php`.
- Nothing noticed, because no test covers it. The only core test touching the package is
  `tests/Unit/RegistryCacheSecurityTest.php`, which tests the cache class directly.
- Registering the provider by hand worked against the merged tree with nothing disabled. It resolved
  `module-manager`, `tenancy` and `users-roles`, and `ModuleValidator` reported no errors, so this step
  looks low-risk.

### 2.3 Other faults in the module mechanism

| Fault | Evidence | Consequence |
| --- | --- | --- |
| Three different treatments in one directory | `/modules/module-manager/` is gitignored; `modules/users-roles` is a tracked path repository; `modules/tenancy` is tracked and also required from Packagist | Nobody can say which copy is authoritative. [ADR 0010](./adr/0010-modules-and-themes-are-gitignored.md) was withdrawn, and `MIGRATION_PLAN.md` wave 0 still lists this as open |
| `tenancy` has two copies that can drift | `composer.lock` pins `1.0.1` and the tracked `modules/tenancy` matches it today. `composer install` writes the locked version over the tracked folder, while the image's `COPY . .` ships the tracked folder | Bumping the lock without the folder leaves a dirty tree locally and an image running a different version from its lock. This makes the tracked-or-ignored decision more urgent |
| The core cannot run without `tenancy` | `app/Traits/IsTenantModel.php` uses `Liberu\Foundation\Tenancy\Concerns\BelongsToTeam`. It is the only class the core uses from the package; `TenantContext`, the middleware and the job helpers are unused here | It is a required dependency, not an optional module, so "disable" means nothing for it. The package also has its own `IsTenantModel`, which is easy to confuse with the core's |
| The core suite does not run module tests | `phpunit.xml` covers `tests/Unit` and `tests/Feature` only, and `lint.yml` excludes `modules/` from Pint | `modules/tenancy/tests` passes or fails unseen by this repository's CI |
| The final image copies only `vendor/` from the Composer stage | `Dockerfile:77` and `:178`; `modules/module-manager` is absent from a clean checkout | Registering the manager as things stand should break a clean image build, because the class is not there. Must be fixed in the same change. Found by reading; not built |
| One bad manifest stops every boot | `ModuleDiscovery::discover()` parses every installed manifest, enabled or not, and throws | Installing a package is enough to take a site down, so a build-time check is essential |
| `module-ecommerce-dropshipping` has an invalid manifest | `Manifest::fromFile` reports it is missing `display_name`; it also lacks `capabilities` and `default_enabled` | It would fail at boot once the manager runs. `module-ecommerce-commerce-core` passes |
| Documentation disagrees with the code | `config/modules.php` and ADR 0011 say nothing runs unless named, but `ModuleRegistry::resolve()` honours `default_enabled`. `CLAUDE.md` says `modules/` holds only the manager. `docs/OPERATIONS.md` documents a `module` command that was deleted | Readers will trust the wrong model |
| Wave modules do not match the host's data model | `dropship_*` tables use a string `tenant_id` and integer minor units. The host uses `store_id` and decimals | Adopting them is a data migration, not an install |

### 2.4 Printful and DropXL today

| Fault | Where | Why it matters |
| --- | --- | --- |
| One set of credentials per deployment | `config/dropshipping.php`, `config/services.php:57` | Two stores cannot use different Printful accounts |
| Supplier branches inside a shared service | `DropshippingService`: `if ($supplierId === 'printful')` in three methods | Every new supplier means editing the core |
| A shopper's request can choose the supplier | `CheckoutController.php:394`, `HeadlessCheckoutService.php:50`, default `'dropxl'` | Wave 17 already recorded this. Only a product's own supplier should route an order |
| A cart spanning two suppliers goes to neither | `CheckoutService::supplierFor()` logs a warning and returns `null` | **Printful and DropXL cannot safely coexist.** The customer can pay and no supplier receives the order |
| An order holds one supplier reference | `orders.supplier_id`, `supplier_order_reference` | Same blocker, at the schema level |
| Network failures are never retried | `DropshippingService::placeOrder()` catches the exception and returns `success: false`; the job treats that as a rejection | The job's retry path cannot be reached by a connection error. HTTP calls also have no timeout |
| Nothing records tracking | `supplier_tracking_number` is fillable, and nothing writes it | No shipment updates reach the order |
| Placeholder suppliers ship in config | `supplier1`, `supplier2` in `config/dropshipping.php` | They appear in the suppliers API as if real |
| No consistent admin surface | DropXL has `app/Filament/Admin/Pages/DropxlImport.php`; Printful has only `printful:import-products` | Merchants cannot manage either from `/app` |
| Only faked HTTP in the suite | All five supplier test files use `Http::fake` | The move needs a live check against each real API, and the DropXL URL in config is unconfirmed |

**Current use:** in the site repositories, Printful appears in `jmhclothing` and `coldphysique`.
`DROPXL_*` variables appear in every site except `coldphysique`, inherited from the core's
`.env.example`, and no site's themes or seeders mention DropXL. So DropXL's real usage needs
confirming before it is extracted.

### 2.5 What already exists and works

Tenancy and authorization are sound and should be reused as they are:

- `Team` → `Store` → `Channel`, with `IsStoreScoped` and `StoreContext`.
- `StoreAccess`, with `TeamPermission::STORE_MANAGE` already documented as covering integrations.
- `strictAuthorization()` on both panels.
- `Store::paymentGateways()` is a working precedent for a per-store on/off list.
- The storefront theme system (§6).

## 3. What to retain, correct or replace

| Item | Verdict | Reason |
| --- | --- | --- |
| `liberusoftware/composer-installer` | **Retain** | About 80 lines, with path safety and collision checks. Routes modules and themes to the right directories |
| `liberusoftware/module-manager` | **Retain, and register it** | Manifest validation, semver, dependency order, capability conflicts. Rewriting it would be the "framework within a framework" |
| Deployment-level enablement (ADR 0011) | **Retain** | Providers, routes and config caches must be fixed at boot |
| `module.json` | **Retain** | Already validated, and enough for what we need |
| `App\Filament\ModulePlugins` from the reference host | **Adopt** | 30 lines, and the manifest already declares plugins |
| Manager lock at `1.4.3` | **Correct, with care** | See the risk in §7.2 before moving to the `v1.4.4`+ releases |
| `modules/` tracking | **Correct** | Ignore it; Composer is the only source (§4.3) |
| `users-roles` | **Correct** | Consume it from Packagist and remove `extra.laravel.providers` |
| `tenancy` | **Correct** | Keep the dependency, drop the tracked copy and `extra.laravel.providers`, and treat it as required |
| Dockerfile | **Correct** | Must carry Composer-installed `modules/` and `themes/` into each stage |
| `composer-require.yml` | **Correct** | Stop it staging `modules themes`, and open a PR rather than pushing to the branch |
| `sync-packages` | **Replace, then remove** | §7 |
| `module-manager-filament` | **Do not adopt** | Read-only, needs `observability`, and has no notion of a store |
| `module-ecommerce-dropshipping` | **Do not build on it yet** | Separate schema, and not installable. Its ideas are worth keeping: an idempotency key sent to the supplier, reconcile rather than re-send, refuse clearly when unconfigured |
| Runtime enablement in the database that re-registers providers | **Do not revive** | Rejected correctly in ADR 0011 |

## 4. Proposed architecture

### 4.1 Lifecycle

These are separate states, each with one owner and one place it is recorded. A **deployment** is one
running copy of the application: one site, or one multi-store install.

| State | Means | Owner | Recorded in | Changed by | Takes effect |
| --- | --- | --- | --- | --- | --- |
| **Catalogued** | Known and supported, may not be installed | Maintainers | `config/ecommerce-modules.php` | Pull request | Next deploy |
| **Installed** | Code is present | Deployment operator | `composer.json`, `composer.lock` | Pull request, CI, deploy | Next deploy |
| **Enabled** | Provider boots in this deployment | Deployment operator | `MODULES_ENABLED` | Environment change and restart | Next restart |
| **Migrated** | Module tables exist | Deploy step | `migrations` table | `migrate --isolated` in `.docker/start-container` | During deploy |
| **Available** | This store may use it | Platform admin | `store_modules.available` | `/admin` | Immediately |
| **Active** | This store has it on and configured | Merchant | `store_modules.enabled`, `settings` | `/app` | Immediately |
| **Authorized** | This person may use it | Team role or platform grant | `StoreAccess` | Existing roles | Immediately |

The fleet's documented chain is `Installed -> Enabled -> Entitled -> Authorized`. **Available** is
its "Entitled" step, so this adds nothing to the fleet's model beyond naming it for stores.

**A store cannot install code.** All stores in a deployment share one codebase, so "this store
installs Printful" really means the deployment has the package and this store has activated it.
Other stores are unaffected because every behaviour is gated on activation. Sites that are separate
deployments simply also differ in what they have installed.

### 4.2 Module anatomy

One repository and one Composer package per integration:

```text
module-ecommerce-printful/                 liberusoftware/ecommerce-printful
├── composer.json      type: liberu-module, extra.liberu.name, no extra.laravel.providers
├── module.json        category: adapter, default_enabled: false
├── src/
│   ├── PrintfulServiceProvider.php
│   ├── PrintfulStoreModule.php            what the admin screens list
│   ├── PrintfulFulfilment.php             implements FulfilmentProvider
│   └── Filament/PrintfulPlugin.php        settings and import pages
├── routes/web.php     webhooks only
├── database/migrations/                   tables prefixed ecommerce_printful_
├── config/ resources/views/ resources/lang/
└── tests/
```

Everything uses ordinary Laravel: `mergeConfigFrom`, `loadMigrationsFrom`, `loadRoutesFrom`,
`loadViewsFrom`, `loadTranslationsFrom`. Filament assets go through `FilamentAsset`, which the
`filament:upgrade` Composer hook already publishes.

**This deviates from the fleet's four-package split** (domain, `-api`, `-filament`, `-livewire`).
An integration with one host does not justify four repositories. It needs an ADR under this repo's
rules, and a one-line manager change, because `ModuleValidator` only lets `presentation` modules
declare Filament plugins. It should also allow `adapter`.

### 4.3 Installing and discovering independent repositories

- **Install:** `composer require liberusoftware/ecommerce-printful`. Public modules come from
  Packagist. A private client module uses a `vcs` repository entry and `COMPOSER_AUTH` in CI.
- **Location:** `composer-installer` places it in `modules/<name>/`, and themes in `themes/<name>/`.
- **Git:** `modules/*` is ignored, so the lock file is the only record of what is installed. This
  replaces the withdrawn ADR 0010 and needs a new ADR, because the fleet's `THEMES.md` §3.2 currently says otherwise.
- **Discovery:** `ModuleDiscovery` reads `InstalledVersions::getInstalledPackagesByType('liberu-module')`
  and each `module.json`. Nothing needs adding here.
- **Registration:** `ModuleManagerServiceProvider` is the only registrar, listed in `config/app.php`.
  Modules that declare `extra.laravel.providers` are rejected by CI.
- **Local development:** add a temporary path repository with
  `composer config repositories.x path ../module-x`, and do not commit it. CI rejects `path`
  repositories in the root `composer.json`.
- **Versioning:** bare semver tags, per [ADR 0005](./adr/0005-bare-version-tags.md). Tags are created
  in the module's own repository.

**Compatibility with the core uses Composer rather than a custom check.** The core is a root project
and has no reliable version that a package can constrain against. So both sides depend on the
contracts package:

```text
core:    "liberusoftware/ecommerce-contracts": "^1.0"
module:  "liberusoftware/ecommerce-contracts": "^1.0"
```

A breaking change to an extension point is a major version of that package, and Composer refuses to
install an incompatible module during `composer require`, long before production. `module.json`
continues to declare PHP and Laravel constraints, which `ModuleValidator` checks.

### 4.4 Extension points

New package: `liberusoftware/ecommerce-contracts`, Composer type `library`. It holds interfaces and
small value objects only, with no Laravel models. Modules never import `App\…`, in line with
[`MODULE_DEVELOPMENT.md`](./MODULE_DEVELOPMENT.md) R1. A new catalogue entry is needed, as that
document requires.

```php
namespace Liberu\Ecommerce\Contracts;

interface StoreModule
{
    public function key(): string;              // 'ecommerce-printful'
    public function label(): string;
    public function isConfigured(array $settings): bool;
    public function settingsUrl(int $storeId): ?string;
}

interface FulfilmentProvider
{
    public function key(): string;              // the value in products.supplier_id
    public function place(FulfilmentRequest $request, array $settings): FulfilmentResult;
}

interface SupplierCatalog
{
    public function upsert(int $storeId, ImportedProduct $product): int;
}
```

- **Modules register by container tag**, in their provider:
  `$this->app->tag(PrintfulFulfilment::class, FulfilmentProvider::class)`.
- **`FulfilmentRequest` carries an idempotency reference.** A provider must send it and must throw on
  a transient failure, returning a result only for a real answer. This fixes the unreachable retry path.
- **The core implements `SupplierCatalog`.** It owns writing products and variants, moved out of
  `PrintfulCatalogImporter` and `DropxlService`, so modules never touch `App\Models\Product`.
- **Authorization uses Gate abilities** that the core defines over `StoreAccess`, such as
  `ecommerce.store.manage`. A module calls `Gate::allows()` and never names a role.
- **Do not add a general event system yet.** `app/Events/` does not exist today. Add specific events
  when a second consumer needs them. Tracking updates are the likely first case, as a minor version
  of the contracts package.

### 4.5 Store integration in the core

One new table:

```text
store_modules
  id, store_id (FK, cascade), module (string)
  available (bool, default false)     set by the platform
  enabled (bool, default false)       set by the merchant
  settings (encrypted json, nullable)
  timestamps
  unique (store_id, module)
```

- **No row means off.** This is the same default-deny that ADR 0011 argued for.
- **New classes:** `App\Models\StoreModule` and `App\Support\StoreModules`, with `active($key, $store)`,
  `settings($key, $store)` and `availableTo($store)`.
- **Bind `StoreModules` with `scoped()`.** The Docker image runs Octane, so request state must not
  survive between requests.
- **Why a column and not `liberusoftware/settings`:** that package does support secrets and scopes,
  but it would add another module and table, and activation state belongs on the same row as its
  settings. Revisit if a third kind of per-store setting appears.
- **No environment fallback for credentials.** Falling back to one env token is exactly how one store
  would end up using another's account. The sites are pre-production, so each store can enter its own.
  Env remains valid for API base URLs.

**Fulfilment in the core:**

- `App\Services\Fulfilment\FulfilmentProviders` resolves a tagged provider for a store, or reports it
  as unavailable.
- `DispatchDropshippingOrder` calls it, and the supplier branches leave `DropshippingService`.
- New table `order_fulfilments` (`order_id`, `supplier`, `reference`, `status`, `response`), with one
  row per supplier on an order. This is what allows Printful and DropXL to coexist in one cart.
- The order moves to `processing` when all its fulfilments are placed, and to `supplier_failed` if any
  fails. `Order::transitionTo()` remains the only way to change status.
- `supplierFor()` is replaced by per-line grouping. The request-chosen supplier is removed from both
  checkout paths.

### 4.6 When a module is disabled or unavailable

| Situation | Behaviour |
| --- | --- |
| Storefront product whose supplier is inactive for that store | Page renders and the product cannot be bought. `ProductPresenter` reports it as out of stock, so themes need no changes |
| Checkout contains such a line | Refused before payment, like a stock failure. **It must never take payment for an order nobody can fulfil** |
| Queued order when the module has gone inactive | Moves to `supplier_failed` and staff are notified. Reactivating allows a retry through the existing `SUPPLIER_FAILED → SUPPLIER_QUEUED` transition, which is safe because of the idempotency reference |
| Module's merchant pages | Hidden from navigation and denied by `canAccess()` |
| Module's own non-webhook routes | Middleware `store.module:<key>` returns 404 |
| Supplier webhooks while the module is installed | Still accepted, so in-flight orders still get their updates. Only new dispatches stop |
| Scheduled work | Loops over stores where the module is active, and does nothing otherwise |
| Module removed from `MODULES_ENABLED` | Same as inactive for every store. Rows and data stay |
| Package removed from Composer | Same as above. Tables stay until purged (§5.3) |
| Module fails its manifest or version check | Deploy fails at the build step. It must not first appear as a runtime 500 |

### 4.7 Permissions

| Action | Who | Check |
| --- | --- | --- |
| See the modules page, set availability | Platform staff | `StoreAccess::platform($user, 'manage_modules')` |
| Activate, configure, test connection | Team owner or `admin` | `TeamPermission::STORE_MANAGE` |
| Import products | Team `catalogue` role | `TeamPermission::CATALOGUE_MANAGE` |
| Retry fulfilment | Team `orders` role | `TeamPermission::ORDERS_MANAGE` |
| Install, upgrade, remove | Whoever can merge and deploy | Repository permissions, not the application |

Modules do not add team roles. Module models need policies, and `strictAuthorization()` makes a
missing one fail loudly.

### 4.8 Other candidates for modules

Each of these should wait until the seam has been proven with Printful.

| Functionality | Files | Verdict |
| --- | --- | --- |
| Meta catalogue sync | `app/Services/Facebook/` | **Next.** Optional and integration-shaped |
| EasyPost | `app/Services/Shipping/EasyPostCarrier.php` | **Good candidate.** Needs a carrier contract |
| PayPal | `app/Services/PaymentGateways/PayPalGateway.php` | Later. `PaymentGatewayFactory` is a closed `match`, and this is money-critical |
| Chat | `ChatService`, `ChatAgentDashboard`, Reverb | Later. Larger, and needs its own survey |
| EU tax reporting | `OssReportService`, `EcSalesListService`, `ViesService` | Later. Regional, but check how far it reaches into checkout first |
| Subscriptions | `SubscriptionService`, Cashier | Later. Entangled with the user model |
| Catalogue, cart, checkout, orders, tenancy, GDPR, theme resolver | | **Stay in core** |

## 5. Admin management

### 5.1 Who does what, and where

| Task | Where | How |
| --- | --- | --- |
| **Discover** | `/admin` → Modules | Reads the curated `config/ecommerce-modules.php`. No live Packagist query: the vendor lists 1,667 packages, mostly for other products |
| **Install code** | Git and CI | Pull request adding the package, CI, image build, deploy. The admin page shows the exact command |
| **Enable for the deployment** | Environment | `MODULES_ENABLED`. The admin page displays this read-only |
| **Assign to stores** | `/admin` → Modules → store list | Toggle `available` |
| **Enable for a store** | `/app` → Store Settings → Integrations | Merchant toggle, refused until `isConfigured()` is true |
| **Configure** | The module's own Filament page | Linked by `settingsUrl()`. Secrets are write-only in the form |
| **Upgrade** | Git and CI | Dependabot or a PR, with the matrix in §7.4 |
| **Uninstall** | Git and CI, in stages | §5.3 |

The `/admin` page shows, per module: installed version, enabled or not, pending migrations, manifest
errors, stores using it, and the commands for anything it cannot do itself.

### 5.2 Why the admin panel must not run Composer

- A web process able to write code and run Composer turns any admin compromise into code execution.
- The Kubernetes setup (`k8s/`, with an HPA) runs several replicas from one image. A change made in one
  pod is not in the others and disappears on restart.
- A web-driven install leaves production out of step with the lock file in Git, so the next deploy undoes it.
- Failures happen in a request, partway through, with no CI having seen the result.

A later convenience is possible: a "Request install" action that dispatches a workflow to open a pull
request. It never touches the running server, and it is not needed for a first version.

### 5.3 Upgrade and removal

**Upgrade:**

- Module migrations must be backward compatible, because rolling back an image does not roll back a
  schema. Add first, and remove in a later release.
- Build step: `php artisan module:validate`, then `module:cache`.
- Deploy runs `migrate --isolated`, then restarts Octane and Horizon so workers load the new code.

**Removal, as deliberately separate steps:**

1. Merchants deactivate, and the `/admin` page lists stores still using the module. Nothing is deleted.
2. Remove it from `MODULES_ENABLED` and deploy. The code is present but not booted.
3. Optional and destructive, while the files still exist: `php artisan migrate:reset --path=modules/<name>/database/migrations`,
   then `php artisan module:forget <name>`, a small new core command that deletes its `store_modules` rows.
4. `composer remove`, and deploy.

Skipping step 3 keeps the data, which is the safe default, and the page should say the tables remain.
The Warranty removal (`17ac5f4a`) left nine tables with only a changelog note, and this makes that a
choice rather than an accident.

**Modules holding personal data must join GDPR export and erasure.** A tagged contract added to the
contracts package covers this, and `GdprExportService` and `GdprErasureService` should call it. This
should land before any module stores customer data of its own.

## 6. Themes

The theme system is in good shape and needs little. Retain `StorefrontTheme`, `theme.json`, parent
chains and `channels.theme`. The installer already sends `liberu-theme` packages to `themes/<name>/`,
which is where the resolver looks.

Only these changes are needed for themes and modules to coexist:

1. **Docker:** the fix that carries `modules/` also has to carry `themes/` into the asset stage
   (`Dockerfile:69-77`). Otherwise `vite.config.js` will not see a Composer theme, and it will be missing
   from the build manifest.
2. **Tracked or installed:** a theme with no `composer.json` is site-owned and tracked, as all five are
   today. A theme with one is Composer-installed, and its directory is ignored by name. CI fails a tracked
   theme that has a `composer.json`. The warning against adding `composer.json` in `docs/THEMES.md` can
   go once `sync-packages` is retired, because that job is the only reason for it.
3. **Provider-less commerce themes:** the in-core resolver does not read `provider` from `theme.json`, so
   a distributable commerce theme is views and tokens only. Fleet themes such as `theme-default` will not
   work unchanged, and that matches the earlier decision to keep a small resolver.
4. **Selection:** add `StorefrontTheme::available()` and a select to `ChannelResource`, whose comment
   already says it is waiting for this.
5. **Module views:** a module's default views are already overridable through
   `themes/<name>/resources/views/vendor/<namespace>/`. Nothing needs adding.
6. **CSS:** Tailwind only keeps classes it scans. Add `@source` lines for `modules/*/resources/views` to
   `resources/css/filament/admin/theme.css`, or module admin pages will render unstyled. Storefront module
   views should have small CSS of their own, since each theme builds its own stylesheet.

## 7. CI

### 7.1 What the sync job does

| Question | Answer |
| --- | --- |
| What runs it | `.github/workflows/sync-packages.yml` and `.github/scripts/sync-packages.py`, on pushes to `main` touching `modules/**` or `themes/**` |
| Why it exists | Added in `f87e8b2d` on 2026-10-26. The README says it makes tracked package edits publishable, using the host as the place packages are edited |
| Which repositories | `modules/<dir>` to `liberusoftware/module-<dir>`, and `themes/<dir>` to `liberusoftware/theme-<dir>`. Only directories with a `composer.json` |
| How it reaches them | `git clone` over HTTPS, authenticated by `gh auth setup-git` with `MODULE_SYNC_TOKEN`. It creates missing repositories through the GitHub API as **public** |
| Mechanism | Custom Git and API calls. No submodules. No Composer. It replaces the destination tree except its `.github`, commits, tags, creates a release, and calls Packagist. The merged version also opens a pull request when a destination refuses a direct push, records releases in `.github/package-releases.json` by pushing to the host's `main`, and has a `--use-packagist` mode that rewrites the root `composer.json` |
| Versions | Inferred from the **host's** commit messages |
| What it tests | Only the publisher script. It does not run the module's own tests before publishing |

### 7.2 Why it fails, and what the evidence does and does not show

**In this core it is not failing.** Upstream has one run, a success on 2026-10-27. On the fork it is
skipped by the owner guard.

**In the five site repositories it should be running, and I could not read those logs.** They are
private, and no GitHub API token is available here. What is certain from the code:

- All five inherited the workflow through the core merge.
- The old guard, `github.repository_owner == 'liberusoftware'`, is true for them.
- Their pushes changed `themes/**`, so the path filter matched.

**The most likely failure is missing secrets**, at "Configure cross-repository authentication" with
`Set MODULE_SYNC_TOKEN`, or `Missing required secret`. `boilerplate-laravel` failed with that exact
message on 2026-10-26. This is an inference. **To confirm, open a failed run in any site's Actions tab
and read the first red step.**

**Supplying the secrets to make it pass would be the wrong fix.** A private site would then push
`modules/users-roles` into a shared public repository. Any site theme that ever gained a `composer.json`
would be published to a new public repository.

**Sibling products run the same job, and their logs are public:**

| Failure | Run |
| --- | --- |
| `Set MODULE_SYNC_TOKEN` | `boilerplate-laravel` 2026-10-26 |
| `git push --atomic` rejected | `boilerplate-laravel` 2026-10-27 |
| `already belongs to https://github.com/liberusoftware/module-…` | `maintenance-laravel` 2026-11-02 |
| `approval and required checks are needed`, which the script counts as a failure | `cms-laravel`, `maintenance-laravel` 2026-11-02 |

The last one comes from the newer script, as `PendingPublication`, and this core now has that version.
**Any protected destination will turn the job red by design**, even when everything is working as intended.

**The core has not run the merged script yet.** Upstream's commits used `[skip actions]`, so its only run
is still the 2026-10-27 success. The next push to `liberusoftware/ecommerce-laravel` that touches `modules/**`
will run it, comparing against that run, so that first run would cover `modules/tenancy` as well.

**The design itself is no longer appropriate:**

- **It points the wrong way.** A module edited in a host and pushed outward is not independently maintained.
- **Products overwrite each other.** `module-users-roles` has sync commits from three products.
  `module-module-manager` took commits from five products on 2026-11-02 alone, moving from `1.4.3` to
  `v1.6.2`. These are unreviewed changes to code that decides what boots, and Dependabot is already
  proposing them for this host. The newer script's pull requests only help where a destination is
  protected, and the GitHub API reports `main` as unprotected on `module-users-roles`, `module-tenancy` and
  `module-module-manager`. `tenancy` now shares this exposure, and the core depends on it directly.
- **Release gates get skipped.** It tags `v1.0.3`, while module `install.yml` and `compatibility.yml`
  trigger only on bare tags (checked in `users-roles` and in `module-manager` at `v1.6.2`). [ADR 0005](./adr/0005-bare-version-tags.md)
  describes this exact failure.
- **The credential is powerful.** The token can create public repositories and write workflows across
  the organisation, and every host running the job needs it.

### 7.3 The fix applied, and what it does not do

One line in `.github/workflows/sync-packages.yml`, committed to the fork's `main` right after the upstream
merge. It is not on `liberusoftware/ecommerce-laravel` yet, and upstream's copy still has the old guard:

```diff
-    if: github.repository_owner == 'liberusoftware'
+    if: github.repository == 'liberusoftware/ecommerce-laravel'
```

- **Why it is not simply disabling a check:** the job exists to publish from the core, so running in a
  private client site was the defect. No assertion changes, and it still runs where it is meant to.
- **What it does:** once merged into each site, the job shows as skipped there, and it cannot publish from
  a site whatever secrets are configured.
- **What it does not do:** it reaches the sites only when they next merge the core. It does not change
  sibling products. If your failing check turns out to be somewhere other than a site repository, this will
  not clear it, so please check the run as described above.

### 7.4 What replaces it

The sync job is **not needed** in this architecture. Modules release from their own repositories, and the
host only validates.

**In each module repository** (keeping the existing three thin workflows):

- `tests.yml`, `install.yml` and `compatibility.yml`, triggered on bare tags.
- Pin the reusable workflows to a tag rather than `@main`, as `MODULE_DEVELOPMENT.md` §4.2 already asks.
- Packagist's GitHub hook updates the package on tag. No custom script needed.
- A nightly job installs the module into the core's `main` and latest tag, and runs the composition test.

**In the core:**

| Job | Fails when |
| --- | --- |
| `tests.yml` as it is, with no optional modules | The core depends on an optional module |
| New `modules.yml`, guard step | The root `composer.json` has a `path` repository; `git ls-files modules` is non-empty; a tracked theme has a `composer.json` |
| New `modules.yml`, matrix over `config/ecommerce-modules.php` | Any supported module fails to resolve, `module:validate` fails, or its composition test fails |
| Composition test | Provider not booted, migrations not run, activation gate wrong for a second store, Filament plugin not on its panel |
| Architecture test, after extraction | `printful` or `dropxl` appears under `app/` |
| Contract test kit in `ecommerce-contracts` | A provider breaks idempotency, or error behaviour, in the module's own suite |

A **MySQL run of the listing and checkout tests** is also worth adding. The suite runs on SQLite, and
that is how floralgo's `CAST AS INTEGER` error reached a pushed site.

**Site repositories** keep their tests and lint, and take core changes by merging. They have no reason to
publish anything.

## 8. Phased implementation plan

Each phase can ship by itself, and the site behaviour should be unchanged until Phase 3.

### Phase 0: agree the direction and stop the harm

| | |
| --- | --- |
| **Work** | **Done on the fork:** `upstream/main` merged, and the guard fix committed. **Still open:** settle §9.1, get the guard fix upstream, merge the core into each site, and hold the module-manager Dependabot PR until the version in use has been reviewed |
| **Done when** | Decisions recorded as ADRs; no site runs the sync job; fork and upstream reconciled |
| **Depends on** | Upstream maintainer agreement |
| **Risks** | Upstream's direction may not change, in which case ecommerce must opt out alone. Offering the fork's commits upstream will trigger the merged publisher if they touch `modules/**` (§7.2). Each site takes on the `tenancy` requirement and a lock file conflict on its next core merge |

### Phase 1: make the existing manager real

| | |
| --- | --- |
| **Work** | Register the provider in `config/app.php`. Remove `extra.laravel.providers` from `users-roles` and `tenancy` in their own repositories and consume the published packages. Remove the tracked `modules/tenancy` and `modules/users-roles`, and ignore `modules/*`. Fix the Dockerfile stages. Adopt `ModulePlugins` in both panels. Add `MODULES_ENABLED` to `.env.example`. Add the composition test and the guard step. Correct `config/modules.php`, ADR notes, `CLAUDE.md` and `docs/OPERATIONS.md` |
| **Done when** | `module:list` works in a built image; `MODULES_DISABLED=users-roles` really disables it, or it is made non-optional; `tenancy` is declared as required; clean-clone install and image build both pass |
| **Depends on** | Phase 0, and publishing fixed `users-roles` and `tenancy` packages |
| **Risks** | Boot failures become possible where none existed, so `module:validate` must run in the build first. `users-roles` and `tenancy` are shared and other products may expect discovery. **`IsTenantModel` makes this riskier: if the tracked `tenancy` goes before the Dockerfile fix, images fail wherever a tenant model loads.** `default_enabled: true` foundation modules need a decision on whether disabling them is even allowed |

### Phase 2: contracts and per-store activation

| | |
| --- | --- |
| **Work** | Publish `ecommerce-contracts` 1.0. Add `store_modules`, `StoreModule`, `StoreModules`, the Gate abilities and the route middleware. Add the `/admin` Modules page and `/app` Integrations page. Add `adapter` plugin support to `module-manager`. Prove it all with a fixture module in `tests/Fixtures`, using two stores |
| **Done when** | The fixture can be active on store A and invisible on store B across pages, routes and jobs, with no Printful or DropXL change |
| **Depends on** | Phase 1, a catalogue entry for the new package, and a `module-manager` release |
| **Risks** | The contract gets designed around a fixture, so keep `FulfilmentProvider` out until Phase 3 proves it against real code. Secrets rely on `APP_KEY`, so document rotation with `APP_PREVIOUS_KEYS`. Octane state leaks if `scoped()` is missed; test it across two requests |

### Phase 3: put Printful and DropXL behind the seam, still in core

| | |
| --- | --- |
| **Work** | Add `FulfilmentProvider` to the contracts package. Move supplier branches into in-core classes implementing it. Implement `SupplierCatalog`. Add `order_fulfilments` and per-line routing. Remove the request-chosen supplier and placeholder suppliers. Move credentials to per-store settings. Fix retries and add HTTP timeouts. Add the disabled-module behaviour from §4.6 |
| **Done when** | The full suite passes; Printful and DropXL coexist in one cart in a test; two stores use different credentials in a test; `DispatchDropshippingOrder` names no supplier; a live check against each real API passes |
| **Depends on** | Phase 2, and a real test account for each supplier |
| **Risks** | **The riskiest phase, because it changes money and order flow.** Keep the logic in `CheckoutService`, per `CLAUDE.md`. Both checkout paths must change together. `jmhclothing` and `coldphysique` must re-enter credentials and re-run their theme tests. Merge conflicts in the sites |

### Phase 4: extract Printful, then DropXL

| | |
| --- | --- |
| **Work** | Create `module-ecommerce-printful`. Move the provider, importer, console command and settings page, and add them to the catalogue list. Have `jmhclothing` and `coldphysique` require it. Add the architecture test. Then repeat for DropXL, if confirmed to be in real use |
| **Done when** | The core suite passes with no module installed; the module's suite passes alone; a site runs checkout through to a test supplier order |
| **Depends on** | Phase 3 stable on at least one site |
| **Risks** | Sites resolve `composer.lock` conflicts on each core merge. Decide public or private first (§9.1). Live supplier APIs may need changes the contract did not anticipate, which would mean a contracts minor version |

### Phase 5: retire publishing and finish CI

| | |
| --- | --- |
| **Work** | Delete `sync-packages.yml`, its scripts and the README section. Add the module matrix and the nightly compatibility jobs. Make `composer-require.yml` open a PR. Add a MySQL test run |
| **Done when** | A module release reaches a site only by a reviewed lock file change; the matrix fails on a deliberately broken module |
| **Depends on** | Phase 1 for `users-roles` and `tenancy`, and upstream having another way to publish, or ecommerce opting out |
| **Risks** | Other products still rely on the fleet's publisher and on shared repositories that can change under this host. Pin versions exactly until that stops |

### Phase 6: themes, then further modules

| | |
| --- | --- |
| **Work** | The §6 changes, starting with one distributable theme. Then Meta catalogue sync as the next module, which will also test whether the contract approach holds for a non-fulfilment integration |
| **Done when** | A Composer-installed theme can be selected per channel and appears in a production build |
| **Depends on** | Phases 1 and 5 |
| **Risks** | Low. Each theme override is already kept in step by hand |

## 9. Decisions and limits

### 9.1 Decisions needed

| Decision | Recommendation | Why it matters |
| --- | --- | --- |
| **Does ecommerce opt out of host-to-package publishing and retained source copies?** | Yes. Commit `85f41d4f` upstream ("retain source copies") goes the other way, so this needs agreeing with the upstream maintainer | Decides Phases 0, 1 and 5 |
| **Do the wave modules stay the long-term plan?** | Pause them for this work, and do not delete them. They are a separate data model that nothing consumes | Otherwise two fulfilment designs compete |
| **One package per integration, or four?** | One, with an ADR | Eight repositories for two integrations otherwise |
| Public or private module repositories? | Public on Packagist, unless a licence or client reason says otherwise | Decides how CI and sites authenticate. Needed by Phase 4 |

### 9.2 Requirements covered

| Requirement | Where |
| --- | --- |
| Independent repositories, Composer, discovery and registration | §4.2, §4.3 |
| Migrations, routes, config, assets, translations | §4.2 |
| Versioning and core compatibility | §4.3 |
| Enable and disable | §4.1: deployment level and store level are separate |
| Admin configuration, store availability, permissions | §4.5, §4.7, §5 |
| Upgrade and removal | §5.3 |
| Disabled or unavailable behaviour | §4.6 |
| Printful and DropXL independently or together | §4.5, Phases 3 and 4 |

### 9.3 What was not verified

- **Site CI logs:** not readable here, so the §7.2 cause is an inference.
- **Docker:** the image findings come from reading the Dockerfile. No image was built, including after the
  merge.
- **Merged publisher:** read and its own tests run, but it has not run against real repositories from this core.
- **`modules/tenancy/tests`:** not run, because the host suite does not include them. Only the core's use of
  its `team()` relation is exercised.
- **Reference host:** `boilerplate-laravel` was read, not run.
- **The proposed code:** the contracts, tables and classes are designs and have not been prototyped.
  Registering the manager by hand was tried, in a read-only probe. Nothing else was.
- **Other wave modules:** only `ecommerce-dropshipping` and `ecommerce-commerce-core` were checked in
  detail, so the manifest fault may be wider or narrower than that one.
- **Supplier APIs:** not called. Everything about them comes from code and faked tests.
