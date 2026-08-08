# Conformance snapshot

**Measured 2026-08-08 against `2d1024c`** — the last application commit before this document.

This is a **point-in-time snapshot, not a living document.** Every number below is a count of the tree at that commit. The moment wave 0 of [`MIGRATION_PLAN.md`](./MIGRATION_PLAN.md) lands, this document starts going out of date, and that is correct: it exists to record where the repository stood when the plan was written, so that later readers can tell what the plan was responding to. It is superseded finding-by-finding by the 105 `Architecture: Ecommerce — <module>` execution epics, not revised.

The two documents that *are* living are [`MIGRATION_PLAN.md`](./MIGRATION_PLAN.md) and [`MODULE_DEVELOPMENT.md`](./MODULE_DEVELOPMENT.md).

---

## 1. Scope and method

### 1.1 What was audited, against what

`ecommerce-laravel` in full — 906 files across `app/`, `routes/`, `database/`, `resources/views/` and `tests/` — against [`liberusoftware/documentation`](https://github.com/liberusoftware/documentation):

| Source | Role |
| --- | --- |
| [`projects/ecommerce/ECOMMERCE.md`](https://github.com/liberusoftware/documentation/blob/main/projects/ecommerce/ECOMMERCE.md) | Product scope. Authoritative for the 105-module catalogue |
| [`projects/boilerplate/BOILERPLATE.md`](https://github.com/liberusoftware/documentation/blob/main/projects/boilerplate/BOILERPLATE.md) | The foundation catalogue — **28 modules**, not 29 |
| [`architecture/MODULES.md`](https://github.com/liberusoftware/documentation/blob/main/architecture/MODULES.md) | Packaging, naming, the 20 boundary rules, §30's definition of done |
| [`architecture/REPOSITORIES.md`](https://github.com/liberusoftware/documentation/blob/main/architecture/REPOSITORIES.md) | The README standard |
| [`architecture/API.md`](https://github.com/liberusoftware/documentation/blob/main/architecture/API.md) | Where the GraphQL surface belongs |
| [`standards/`](https://github.com/liberusoftware/documentation/tree/main/standards) | 36 documents, all binding |
| [`boilerplate-laravel`](https://github.com/liberusoftware/boilerplate-laravel) | The named reference implementation — consulted wherever a standard was ambiguous |

**29 of the 36 standards were audited.** The seven excluded are `REACT.md`, `VUE.md`, `NUXT.md`, `FLUTTER.md`, `REACT-NATIVE.md`, `INERTIA.md` and `MOBILE.md` — flavours this repository has no code for, ruled out of scope during charting. `README.md` in that directory is an index rather than a standard.

### 1.2 Method

Every claim in this document is either a counted measurement of the tree or a `file.php:line` citation. Where a finding rested on a judgement call, the judgement is stated rather than folded into the finding.

Four research documents carry the underlying evidence and are cited from the relevant chapters. They live in [`docs/research/`](./research/):

| Document | Covers |
| --- | --- |
| [`standards-gap-audit.md`](./research/standards-gap-audit.md) | One section per standard: requirement, measured evidence, gap, severity, mechanical-vs-structural |
| [`app-to-module-inventory.md`](./research/app-to-module-inventory.md) | All 906 files bucketed as mapped / split / unmappable |
| [`foundation-module-matrix.md`](./research/foundation-module-matrix.md) | One row per foundation module with per-cell citations |
| [`packaging-mechanism.md`](./research/packaging-mechanism.md) | How `modules/` + `composer-installer` + `module-manager` actually work |

### 1.3 What "conformant" is measured against

**Parity is the default.** A deliberate behavioural loss requires an ADR — see [chapter 7](#7-deviations-and-the-adr-index). A gap without an ADR is a finding, never a silent exemption.

Two things this audit deliberately did **not** do. It did not assess feature completeness against the catalogue — a module absent from this repository is not a finding, it is unbuilt work carried by the execution epics. And it did not rank findings by remediation cost; the ordering lives in the migration plan.

---

## 2. The structural finding

### 2.1 There is no domain layer

Everything else in this document is easier to read once this is stated plainly. Counted at `2d1024c`:

| | Count |
| --- | --- |
| Filament classes | **105** |
| Files in `app/Models/` | **99** |
| Controllers | **39** |
| Services | **28** |
| Policies | **17** |
| Interfaces (`app/Interfaces/`) | **3** — container bindings of them: **0** |
| FormRequests | **2** |
| Actions, read models, view models, value objects | see below |
| PHP enums anywhere in `app/` | **0** |
| `declare(strict_types=1);` in `app/` | **2 of 400** |
| …in `tests/` (201) and `database/` (161) | **0** and **0** |
| `app/Http/Resources/` | the directory does not exist |

`app/Actions/` holds 22 files, all of them Fortify, Jetstream and Socialstream scaffolding — not domain actions. The only immutable DTO in the codebase is `Services/Shipping/CarrierRate.php`.

The standards assume a layer between persistence and presentation. There isn't one, so **models and Filament resources absorb the work that `SERVICES.md`, `CONTRACTS.md`, `CLASSES.md` and `DOMAIN-DRIVEN-DESIGN-PATTERNS.md` assign elsewhere.** Nineteen of the 29 per-standard findings are downstream of that single absence, which is why organising this document by standard was rejected: most sections would say "conformant" and the finding that explains the rest would be buried in the middle.

The three consequences that matter most:

- **Authorization gaps are systemic rather than incidental.** There is no single place where one check covers more than one caller, so every caller has to remember — and 105 Filament classes plus 25 of 39 controllers did not.
- **The workflow lives in the controller.** `CheckoutController.php:115-373` is one 259-line method containing inventory checks, subtotal, shipping premium, coupon re-resolution, a VAT reverse-charge decision, pro-rata tax, an 8-table transaction, gateway branching, three order state transitions, mail, dropship queueing and download grants.
- **Nothing mechanically prevents recurrence.** No `pint.json`, no static analysis, no architecture tests, no Composer test scripts. The missing architecture suite is what let all of this accumulate silently, and is the cheapest thing to add that stops it growing.

### 2.2 A Laravel 13 application on a Laravel 10 skeleton

`composer.json` requires `laravel/framework: ^13.23` on PHP 8.5. The skeleton underneath is Laravel 10: a legacy `bootstrap/app.php`, three Kernel/Handler classes, a providers array in `config/app.php:158-176`, and no `bootstrap/providers.php`.

This is not cosmetic. `config/app.php:176` registers `TeamServiceProvider`, which binds **eleven `FamilyTree365\LaravelGedcom\*` classes to `App\Models\{Person,Family,Addr,…}`** on every boot. Neither side exists — `familytree365/*` is not a dependency and none of those models is defined. It is genealogy wreckage from a fork, harmless only because `bind()` is lazy.

### 2.3 The packaging target does not exist here at all

No `modules/`, no `themes/`, no `liberusoftware/*` package, and no `liberusoftware/composer-installer` — which `MODULES.md` §6.1 makes a prerequisite for any `liberu-module` package. The reference application's `app/` holds five files; this one holds 400.

There is, however, a hand-rolled attempt: `app/Modules/` is 11 files and 1,095 lines including `Support/ExternalModuleLoader.php`, which scans directories (`:35`), parses `vendor/composer/installed.json` (`:47`) and `require_once`s by path (`:86`, `:118`). That is a manual PSR-4 class scanner, forbidden by name at `MODULES.md:193` and again in `PHP.md`, `FILAMENT.md` and `LIVEWIRE.md`. **It serves zero modules** — no class implements `ModuleInterface` and `config/modules.php:66` enables none.

### 2.4 What is already conformant

Recorded because it reprices the remediation, and because a findings document that only lists faults misrepresents the codebase.

- **Mass assignment is clean.** 92 models declare `$fillable`; **zero** declare `$guarded = []`.
- **Money at rest is `decimal(10,2)` everywhere.** No float columns. The floating-point problem is in PHP (`Currency.php:43,62,70`), not in the schema.
- **Migration discipline.** 122 migrations, 176 foreign keys, 115 indexes, and a `down()` on all 122.
- **All 28 form templates carry `@csrf`.** All five `{!! !!}` sites are justified — JSON-LD hardened with every `JSON_HEX_*` flag, Blade-escaped attribute bags, Fortify's own SVG.
- **No Blade template queries the database** — checked across all 140.
- **Job payload hygiene.** All three jobs pass identifiers rather than models, bound their attempts, and `DispatchDropshippingOrder::failed()` is a real compensating action.
- **Interfaces exist at the two genuine variation points and nowhere speculatively.**
- `Api/ProductController.php:26` escapes `LIKE` wildcards and explains why. `AppPanelProvider.php:60-70` enables `strictAuthorization()` *and documents which resources would fail without it*. `main.yml:92-138` smoke-tests the Docker image before publishing and names the two production faults that motivated the check.

The patterns the standards ask for already exist in this repository. They are just not the default: `Http/Livewire/CreateTeam.php:16-19` delegates to an action while `Filament/App/Pages/EditTeam.php:33-47` reimplements the same operation inline; `SalesOverviewWidget.php:15-16` calls a service while `TopProductsWidget.php:21-42` writes its own SQL.

---

## 3. Findings per standards document

Ranked within severity. Full per-standard detail — requirement, evidence, gap, severity, mechanical-vs-structural class — is in `docs/research/standards-gap-audit.md`.

### 3.1 Critical

| # | Standard | Finding |
| --- | --- | --- |
| C1 | `API.md`, `MODELS.md` | **No tenant scoping outside Filament.** `App\Traits\IsTenantModel` is a `team()` `BelongsTo` relation and nothing else — no global scope, no `booted()` hook. Tenancy is configured only on the two Filament panels. See [chapter 6](#6-security-findings) |
| C2 | `FILAMENT.md` | **Zero authorization across 105 Filament classes.** `grep` for `canViewAny`/`canAccess`/`authorize`/`Gate::` returns no matches. Only 17 of 99 model files have a policy, and `AuthServiceProvider.php:15-17` is an empty stub, so no route consults them |
| C3 | `CONTROLLERS.md` | **The checkout workflow lives in the controller** (§2.1). Across all controllers: 28 of 39 query Eloquent directly, 46 inline validation sites against 2 FormRequests (both `authorize() { return true; }`), and **25 of 39 have no authorization check at all** |
| C4 | `DATABASE.md` | **Released migrations edited repeatedly** — `create_invoices_table` in 4 commits, `create_products_table` in 4, `create_product_categories_table` in 4, ~15 more with 2 each, and one renamed after release (`71a899f`). 40 of 122 are guarded by `Schema::hasTable`, which is the symptom |
| C5 | `LARAVEL.md` | **Laravel 13 on a Laravel 10 skeleton**, with `TeamServiceProvider` binding 11 nonexistent classes (§2.2) |
| C6 | `CONTROLLERS.md` | **Four registered routes are guaranteed 500s.** `routes/web.php:188-191` registers the product-compare routes; all four controller methods are commented out at `Frontend/ProductController.php:222-259` |

### 3.2 High

**`API.md`** — no versioning, no `app/Http/Resources/`, no OpenAPI, no RFC 9457 error shape; 145 `response()->json()` calls serialize Eloquent models directly. No route in `api.php` is named. Three leak fields outright: `Api/WebhookEndpointController.php:19` returns the signing `secret`, `PaymentMethodController` returns raw `details`, `ChatController` returns raw customer PII.

**`SERVICES.md`** — 28 `NounService` classes, 4 with constructors, 22 of 98 methods untyped, zero authorization, 3 of 28 opening a transaction. External calls are unadaptered: `ShippingService.php:111` hits a hardcoded literal endpoint with no timeout and returns `null` on failure. `->retry(` appears **nowhere** in `app/`.

**`MODELS.md`** — models carry the domain. `Product.php` is 423 lines that send mail (`:242`), open a two-table transactional stock invariant (`:395`) and resolve services from the container (`:190`); `:336-364` fires per-call aggregate queries, so a 24-product grid is ~48 queries. `Order::transitionTo()` throws, derives payment status, writes audit, fires webhooks and generates an invoice in one method.

**`FILAMENT.md`** — beyond C2: `App/Resources/Orders/OrderResource.php:44-66` exposes `payment_status` as a free `Select` beside an editable `total_amount`, bypassing `Order::TRANSITIONS` entirely. `Admin/Pages/ChatAgentDashboard.php:60-74` and all four entry points of `Admin/Pages/DropxlImport.php` are ungated public Livewire endpoints. `MenuResource` is registered twice inside one panel. `app/Filament/Resources/` (149 lines) is discovered by neither provider, and `App/Widgets/SocialLinksWidget.php` never loads because `AppPanelProvider.php:89` points at a directory that does not exist.

**`JOBS.md` / `QUEUES.md`** — 1 of 3 jobs sets `$tries`; none sets `$timeout`, `$backoff` or `$maxExceptions`. `SendWebhookDelivery.php:14` hand-rolls `MAX_ATTEMPTS` and re-dispatches itself, so its failures never reach `failed_jobs` or Horizon. `after_commit` is `false` on all four connections (`config/queue.php:42,51,62,71`) and `dispatchAfterCommit` is never used, while two checkout paths dispatch webhooks from inside open transactions. `CheckoutService.php:102-107` has an untransacted ordering race that makes `DispatchDropshippingOrder`'s own guard drop supplier orders silently.

**`TESTING.md`** — 1,209 test methods and 1,949 assertions is real volume, but there is no `Architecture/`, `Contract/`, `Security/` or `Migration/` suite, no Pest, no Composer scripts, and `phpunit.xml:15-19` has no `<exclude>`, so the coverage denominator is not the standard's scope. The missing architecture suite is why every structural finding here could accumulate silently.

**`PINT.md`** — no `pint.json` (the reference implementation has one), no CI step, no Composer script. The standard's single hard gate — *a PR fails when formatting changes are required* — does not exist.

**CI** (`REPOSITORIES.md` §6.3, `CI.md`) — no formatting, no static analysis, no architecture rules, no `composer validate`. Actions on floating tags. No `release.yml`, so the production coverage gate can never fire. `latest` is pushed to Docker Hub on every `main`. The repository ships a `Dockerfile` and `docker-compose.yml` but has **no `docker.yml`** — the workflow filename §6.3 builds badge URLs from. `main.yml` is already internally `name: Docker`; the fault is the filename plus a duplicated test job.

**`DATABASE.md`, seeders** — 10 of 13 writing seeders are non-idempotent, zero use `upsert()`, zero are environment-aware, and `DatabaseSeeder.php:24-31` runs `DummyDataSeeder` inside the baseline chain, so `db:seed` in production creates sample products.

### 3.3 Three faults that are small, mechanical, and shipping today

Called out separately because none needs the migration plan and each has a live cost:

1. **`database/seeders/UserSeeder.php:36`** — `$this->command->info("Admin password: {$adminPassword}");`, and `install.yml:85` runs `db:seed --force`, so a generated admin password lands in a CI log on every push.
2. **`DropxlService.php:23` and `SubscriptionController.php:21`** read API keys via `env()` **inside constructors**. Under `config:cache` these silently become `''` and `null`.
3. **`error_log` is committed at the repository root** — 2,637 bytes of PHP fatals leaking the deployment path `/home/liberu/projects/ecommerce-laravel` in every line. Still present at the time of writing.

### 3.4 Notable oddities

- **`app/database/seeders/MenuSeeder.php`** — 15 lines with no `<?php` tag, no namespace and no class. PHP parses it as inline HTML, so `php -l` reports it clean. The body is an unfinished generated fragment with `// ... existing code ...` placeholders, and it duplicates a real `database/seeders/MenuSeeder.php`.
- **`app/Providers/SocialstreamServiceProvider.php` is never registered** — absent from `config/app.php`, and a repository-wide grep matches only its own definition. All 8 files in `app/Actions/Socialstream/` are therefore dead code; Socialstream runs on package defaults.
- **`SiteSettingsService` is broken.** `:12,21` read `config('site-settings.cache_key')` and `config('site-settings.cache_duration')`; there is no `config/site-settings.php`, so both are `null`. Nothing calls the service either.
- **Permission naming is inconsistent before any module is involved.** `config/filament-shield.php:100-104` declares `separator: ':'`, `case: 'pascal'` (→ `View:Product`) while `app/Policies/ProductPolicy.php:18` checks flat `view_any_product`. Both fail `roles-permissions`' `PermissionRegistry`, which *throws* on anything not matching `{module}.{resource}.{action}`.
- **There is no `LICENSE` file.** `composer.json:9`, the README's License section and the README badge all declare MIT, and the README links `LICENSE` — which does not exist. Not fixed here, because writing one means naming a copyright holder and year, and that is the repository owner's statement to make, not an editorial one.
- **`AssignDefaultTeam` is registered nowhere** — not in `config/app.php`, neither panel's middleware, nor `bootstrap/`. It would auto-create a personal team per user; it never runs.

### 3.5 Translations

`TRANSLATIONS.md` is 36 lines and this repository fails its first rule.

`lang/` contains one thing: 27 published `filament-shield` files under `lang/vendor/`. There is no `lang/en/`. All **215** `__()` call sites in Blade use English copy as the key — `__('API Token Permissions')` — against §Rules' *"never use mutable English copy as a public key"*. Filament adds ~148 hardcoded `->label('...')` literals against 3 `__()` calls.

Two facts reprice this considerably. **181 of them are in the stock Jetstream/Socialstream views that stay in the host**, and **the commerce storefront has zero** — so the modules being extracted carry no strings at all. The strings must be authored, not migrated. And **`config/app.php` has no `supported_locales` key**, which `localization-core` reads; `'locale' => 'en'` and `'fallback_locale' => 'en'` are declared.

Fleet-wide context: `boilerplate-laravel`'s host has **no `lang/` at all**, and none of its 40 modules ships one either. **No package in the fleet has ever shipped a translation catalogue.**

---

## 4. The module-catalog mapping

### 4.1 Coverage

906 files, every one in exactly one bucket. Migrations, views and tests inherit the bucket of the code they cover.

| Area | Files | Mapped | Split | Unmappable |
| --- | --- | --- | --- | --- |
| `app/` | 400 | 332 (83%) | 39 | 29 |
| `database/migrations/` | 122 | 115 | 0 | 7 |
| `database/factories/` + `seeders/` | 38 | 38 | 0 | 0 |
| `resources/views/` | 140 | 87 | 0 | 53 |
| `routes/` | 5 | 3 | 2 | 0 |
| `tests/` | 201 | 160 | 32 | 9 |
| **Total** | **906** | **735** | **73** | **98** |

One caveat governs every placement: **the 105 per-module specs under `projects/ecommerce/core/` are a uniform package-contract template** — composer name, DDD plan, persistence plan, verification plan — byte-identical but for three header lines, carrying no capability detail. Every placement therefore rests on the `ECOMMERCE.md` §3–§16 and `BOILERPLATE.md` §3 **catalogue tables**, not on the specs. The same is true of the 28 foundation specs; only `BOILERPLATE.md` §§4–14 is normative.

### 4.2 The 98 "unmappable" files were an artifact

The inventory was given two catalogues. The documentation carries **thirteen**. Re-checked against all of them, most of the unmappable set has a clear owner:

| Cluster | Files | Owning product | Catalogue row |
| --- | --- | --- | --- |
| Live support chat | 11 + 3 migrations + 4 tests + 1 view | **CRM** | `chat-and-bots`, `unified-conversations`, `omnichannel-service`, `contact-center` |
| Article / Page / FAQ | 11 + 2 migrations + tests | **CMS** | `pages`, `editorial-content`, `knowledge-base` |
| SEO settings + sitemap | 2 + 1 migration + views + tests | **CMS** | `seo`, `sitemaps` |
| Contact form | 2 + view + test | **CMS** or **CRM** | `form-builder` / `forms-and-surveys`, `lead-capture` |

**But CMS and CRM are documentation-only.** The `liberusoftware` organisation holds 300 repositories and the only application repositories are `accounting-erp-laravel`, `boilerplate-laravel` and this one. Neither product's package estate exists.

So that code **stays in the host as named debt** — tracked as [CMS](https://github.com/liberusoftware/ecommerce-laravel/issues/942) and [CRM](https://github.com/liberusoftware/ecommerce-laravel/issues/943) — and moves when those repositories exist. Extracting it now would have this effort found two products it has no mandate over, defining `cms-core` and `crm-core` boundaries from a single consumer's needs: the speculative extraction `MODULES.md` §4 r4 exists to prevent. **This is a bounded, deliberate deviation from the target shape**: the host will carry roughly 30 files it should not own, and that is the accepted cost.

The 39 generic Blade primitives plus `resources/views/layouts/` go to **`theme-support`** under `THEMES.md` — `BOILERPLATE.md` §14 discusses foundation UI in prose but its §3 catalogue has no row for it, while the reference fleet already ships `theme-support`, `theme-default`, `theme-dark` and `theme-support-livewire`.

Genuinely homeless, and deleted: `app/Http/Middleware/ScreeningDataEncryptor.php` (encrypts `background_check_status`, `credit_report_status`, `rental_history_status` — none of which exist anywhere in this schema; property-rental boilerplate leftover) and `app/database/seeders/MenuSeeder.php` (§3.4).

**The `reminder_settings` table stays.** No code reads it, but *nothing reads it* and *nothing needs it* are different claims, and it may hold rows in a production database. `MODULES.md` §4 r13 — disabling or uninstalling never silently deletes data — is written for exactly this case.

### 4.3 The 73 split files

Single files whose responsibilities straddle several modules. The worst:

| File | Modules |
| --- | --- |
| `Services/CheckoutService.php` | **Six.** Promotions (`resolveCouponDiscount`), Reservations (`reserveStock`), Payments (`capturePayment`), Digital Fulfillment (`grantDownloads`), Dropshipping (`queueDropship`), on a Checkout base |
| `Models/Product.php` | Catalog · Pricing · Inventory Ledger · Digital Assets · Product Types. `ProductVariant` repeats it |
| `Models/Order.php` | Orders · Payments · Fulfillment · Refunds. `OrderItem` adds Digital Fulfillment |
| `Models/User.php` | Identity · Commerce Customers · Organizations and Teams · Saved Lists |
| `Models/Team.php` | Organizations and Teams · Commerce Core |
| `Services/ShippingService.php` | Shipping · Carrier Operations · Dropshipping |
| `Services/TaxCalculator.php` | Tax · Pricing (`getPriceWithTax`/`displayPrice` are display pricing) |
| `Models/InventoryItem.php` | Inventory Ledger · Multi-Source Inventory · **Cross-Border** (it carries HS codes) |
| `Http/Controllers/Frontend/ProductController.php` | Catalog · Search and Discovery · Product Comparison · Back-in-Stock |
| `GraphQL/StorefrontSchema.php` | Catalog · Cart · Checkout · Orders · API Access |
| `routes/web.php` | ~18 modules across 102 routes. `routes/api.php`: ~10 |

**A god model stays whole in its owning module.** Another module extends it **through its own tables, keyed by the owning model's identifier** — never by adding columns or relations to a model it does not own. Splitting a god model into several Eloquent models over one table makes ownership unenforceable; keeping them all in `ecommerce-core` recreates the god model one layer down.

### 4.4 `Team` is the tenant; `ecommerce-core` owns `Store` and `Channel`

The highest-stakes contested placement, and the one that shaped the migration plan.

`StoreResource` binds `$model = Team::class` with `$modelLabel = 'Store'` and `$isScopedToTenant = false` — the same row is both the boilerplate tenant and the commerce store, with no `Store` model or `stores` table anywhere.

**`Team` stays the foundation tenant**, owned by `organizations-teams`. `ECOMMERCE.md` §3 gives Commerce Core *"stores, channels"*, so **`ecommerce-core` gets its own `Store` and `Channel`**, with a `Store` belonging to a `Team`. `Team` doubling as the store would put commerce semantics inside `organizations-teams`, which §4 r6 forbids, and would foreclose one-team-many-storefronts before anyone had ruled it out.

The vocabulary these terms now carry is in [`CONTEXT.md`](../CONTEXT.md).

### 4.5 The GraphQL surface belongs to the host

`architecture/API.md` answers both halves of the question the inventory left contested. **§5.2 permits GraphQL outright** for read-heavy multi-resource clients, so nothing is lost and no ADR is owed. **§4 then places it in the host composition layer** — *"cross-module endpoints belong to the host application's composition layer and delegate to each owning module"* — settling it as neither API Access nor Sales Channels.

The absence of a `graphql` flavour is a packaging argument, not grounds for deletion: the surface sits outside the flavour system. There is no schema-assembly problem because nothing is assembled — the host authors the whole schema and resolvers delegate downward, so **no module knows GraphQL exists**.

### 4.6 The foundation matrix

28 modules. **14 adopt, 8 adopt-but-thin, 4 not yet, 2 no.**

| Verdict | n | Modules |
| --- | --- | --- |
| **Yes** | 14 | Application Core, Module Manager, Identity, 2FA, Jetstream Bridge, Organizations/Teams, Roles/Permissions, Localization, Currency Context, Audit, API Access, Analytics Core, Observability, Settings |
| **Yes, thin** (contract only) | 8 | Sessions/Devices, Profiles, Notifications, Webhooks, Integrations, Import/Export, Scheduler/Queues, Developer Experience |
| **Not yet** | 4 | Files/Media, Search, Google Analytics, Meta Server-Side Tracking |
| **No** | 2 | Feature Flags, Activity and Comments |

The "thin" column is not a judgement about quality — **the installed modules are small**: 208 source files across 40 packages. `webhooks` is 4 files (signer, retry schedule, secret vault, provider) with no model, job or command; `notifications` is 3, `api-access` 3, `feature-flags` 2, `sessions-devices` 2. For most rows the honest verdict is *adopt the contract, keep the implementation*, not *delete this directory*.

Five rows move real code:

- **Module Manager** — deletes all 11 files / 1,095 lines of `app/Modules/`, plus `ModuleCommand.php`, `Module.php` and `config/modules.php`. A deletion, not a migration (§2.3).
- **Settings** — near-exact match. `modules/settings/src/Settings/SiteSettings.php` declares the same 12 typed properties in the same order as `app/Settings/GeneralSettings.php`, plus `active_theme`, under group `'site'` instead of `'general'`. Needs a group-rename migration, and lets us delete the *second*, broken settings system here (§3.4).
- **Jetstream Bridge** — supersedes 5 of 6 `app/Actions/Fortify/` files and `DeleteUser.php`. It has **no counterpart for the other 8** team actions; `organizations-teams` offers only `AcceptInvitation`/`InviteMember`/`TransferOwnership`, different shapes.
- **Identity + `identity-socialstream`** — supersedes 7 of 8 `app/Actions/Socialstream/` files, `ConnectedAccount`, `ConnectedAccountPolicy`, and `app/Filament/Admin/Resources/Users/*` via `identity-core-filament`.
- **Roles/Permissions** — supersedes `Role`/`Permission`, the `PermissionRegistrar` wiring at `AppServiceProvider.php:22-24`, `config/permission.php`, and the permission strings in all 17 policies.

**Two divergences adoption would silently lose**, each with an ADR:

- `app/Http/Middleware/SecurityHeaders.php:19-28` ships a 9-directive `Content-Security-Policy-Report-Only`. The `application` module's version explicitly declines to ship any CSP, and uses `X-Frame-Options: DENY` where this repository uses `SAMEORIGIN`. → [ADR 0002](./adr/0002-dropping-the-content-security-policy.md)
- `app/Providers/AppServiceProvider.php:31-46` sets `Password::defaults()` to `min(12)->mixedCase()->numbers()->symbols()` with a production-only HIBP `uncompromised()` check. **No foundation module has an equivalent.** → [ADR 0003](./adr/0003-dropping-the-password-policy.md)

**Two prerequisites and one skew:** no `liberusoftware/*` package and no `composer-installer` is installed here; `spatie/laravel-permission` is `^8.3` here against `^7.0` in the reference application, and the module is unverified against v8. Money also violates `BOILERPLATE.md` §11 — `Currency.php:43,62,70` types conversion as `float` and `orders.total_amount` is a bare `integer` with no currency column, against a `Money` that is `readonly (int $minorAmount, Currency $currency)`. Adoption there is a data migration across every money column.

### 4.7 Contested placements, deliberately not decided

Three disputes remain inside the Ecommerce catalogue: customer segments and A/B tests (Personalization vs Commerce Customers vs Merchandising Intelligence), EU VAT reporting — OSS, EC Sales List, VIES (Tax vs Cross-Border vs Reporting), and the taxonomy models (PIM vs Categories and Navigation).

**A contested placement is settled by whichever rival module is extracted first**, which states the boundary in its ADR and notifies the rival's epic. Deciding them now would rule on boundaries before either module exists, and there is nothing to adjudicate on — the per-module specs are a template (§4.1). At extraction time the code is in front of you and a wrong call costs a directory move.

---

## 5. Duplicate stacks

Seven pairs were reported by the inventory. **Three are not duplicates.**

### 5.1 Not duplicates

| Pair | Why not |
| --- | --- |
| `RecommendationService` (82 lines) / `ProductRecommendationService` (230) | A read/generator split of one module — one serves `Frontend/ProductController`, the other is driven by the `GenerateProductRecommendations` command. Both belong to Recommendations; rename to say so |
| Products / Reports / Settings across both Filament panels | **`MODULES.md` §5.6 requires it**: a `-filament` package "presents only its matching domain module, covers **all panels** that module requires". The real work is deduplicating the shared schema *inside* that one package — choosing a panel is what §5.6 forbids |
| Two `MenuResource` classes | A 13-line wrapper over `biostate/filament-menu-builder`'s `BaseMenuResource` and a 76-line hand-written resource on the same model. Keep the wrapper — but **diff the hand-written one against the package base first**, so anything it does that the base doesn't is known before it goes |

### 5.2 Real duplicates

**D1 — Tax.** `TaxCalculator` (171 lines, 8 references including `CheckoutController`, `Product` and `HeadlessCheckoutService`) beats `TaxService` (125 lines, one reference: its own test). **Not a straight delete** — "referenced only by its own test" means nobody has checked whether it holds a rule the live engine lacks, and deleting tax logic unread is the kind of thing that surfaces a quarter later in a VAT return. Diff, merge, then delete.

**D2 — Permission seeders.** `PermissionsSeeder` is 17 lines and referenced by nothing; `PermissionsTableSeeder` is 1,394 lines and is what `DatabaseSeeder` runs. Delete the former.

**D3 — Cart.** `CartController` (148 lines) is session-backed and mirrors to the persistent cart on login; `Api/CartController` (102 lines) writes `CartItem` rows directly, stamping `'session_id' => $item->session_id ?? 'api'`. That sentinel is the tell that the schema is fighting its second writer. **One persistent store**; the session path becomes a guest identifier on the same store and the `'api'` sentinel is backfilled to a real value.

**Existing guest carts are not truncated.** `AbandonedCart` and the recovery-campaign models mean those rows feed a live feature — deleting them silently deletes what a running campaign is about to email people about.

**D4 — Reviews and Ratings.** The expensive one. Two complete parallel stacks, both alive, both maintained as recently as July 2026 (detailed-rating and vote columns were added to *each* in parallel), and **both present in `GdprExportService` and `GdprErasureService`** — so both hold real personal data.

| | `reviews` / `ratings` (retiring) | `product_reviews` / `product_rating` (surviving) |
| --- | --- | --- |
| identity | `user_id` → `users` | `customer_id` → `customers` |
| body | `review` (text) | `comments` (text) |
| score | `rating` (integer, inline) | separate `product_rating` table |
| moderation | **`approved`** (default false) | — |
| verified purchase | — | `is_verified_purchase` |
| served by | `ReviewController`, `RatingController` | `Product`, `Customer`, Filament resources, policies, the recommender |

`ProductReview`/`ProductRating` survive — that side owns more of the graph, so it is the cheaper repoint, and `is_verified_purchase` is a real capability the other lacks. Two things the merge must carry, recorded in [ADR 0008](./adr/0008-reviews-and-ratings-merge.md):

1. **`approved` is ported.** Dropping it would silently turn the public listing from moderated to unmoderated — an abuse surface, not a preference.
2. **A `Customer` is backfilled** for every user with reviews but no `Customer` record. Dropping unmappable reviews would delete user-authored content the GDPR paths treat as personal data; keeping both `user_id` and `customer_id` would leave the module with two identity columns and no rule about which is authoritative.

### 5.3 Sequencing

**All four merges happen before their owning module is extracted.** A module extracted around two competing stacks has to publish a public surface for both and then break it when they merge; and each merge is a data migration against the host's schema, which is materially easier while the tables still belong to the host rather than to a package.

Also noted by the audit but not in the seven: 15 duplicate concepts more broadly — `User`/`Customer`, two inventory systems, `tax_total`/`tax_amount`.

---

## 6. Security findings

Four issues, one root cause. **These are live data exposures, not correctness bugs** — the deciding fact is that deployment is **shared, not one instance per merchant**, settled while deciding merchant resolution. Under one-deployment-per-merchant the whole class dissolves; it does not hold.

### 6.1 The root cause

```php
// app/Traits/IsTenantModel.php — the whole file
trait IsTenantModel
{
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
```

A relation and nothing else. No global scope, no `booted()` hook. Tenancy is configured **only** on the two Filament panels (`AdminPanelProvider.php:118`, `AppPanelProvider.php:123`), so every query outside a panel is unscoped.

`team_id` does exist — on 16 tables, from two migrations' table arrays, `nullable()->constrained()->default(1)`. **Nothing reads it.** `team_id` appears in no file under `app/Http/Controllers/Api/`.

### 6.2 The four issues

| # | Surface | Requires | Exposes |
| --- | --- | --- | --- |
| [#939](https://github.com/liberusoftware/ecommerce-laravel/issues/939) | Authenticated REST | any Sanctum token | Every merchant's catalogue and collections. Any global `admin` additionally reads/writes products, returns (customer PII) and webhook endpoints across merchants |
| [#950](https://github.com/liberusoftware/ecommerce-laravel/issues/950) | Anonymous GraphQL | **nothing** | Every merchant's products and collections in one request, including `inventoryCount` — competitor stock levels, published anonymously |
| [#952](https://github.com/liberusoftware/ecommerce-laravel/issues/952) | Anonymous Blade storefront **and sitemap** | **nothing** | Every merchant's products to visitors *and to search engines* |
| [#958](https://github.com/liberusoftware/ecommerce-laravel/issues/958) | Filament panels | panel access | **Undetermined** — see §6.4 |

**#952 is the primary surface** and the one with the distinct harm: the other two leak *on request*, the sitemap leaks *on crawl*. It hands search engines the canonical URLs of every merchant's products from a single sitemap, which persists after the fix until the index is re-crawled, and is a duplicate-content defect besides.

On #939's severity: `config/permission.php:125` sets `'teams' => false`, so `admin` and `super_admin` are **global roles, not per-team ones**. An admin of merchant A edits merchant B's products.

Two availability defects on the GraphQL route, recorded here rather than filed: `collections`, `Collection.products` and `orders` return **unbounded lists** — `QueryDepth(10)` and `QueryComplexity(200)` do not bound them, since `collections { products { id name } }` is depth 3 and returns the entire catalogue once per collection — and there is **no execution timeout**. `throttle:api` limits request count, not per-request cost. `Collection.products` is also an N+1.

### 6.3 The data is mis-attributed at rest, as well as unscoped at read

**No application code writes `team_id` anywhere.** Grepping `app/` finds only `current_team_id` on users and one `team_user` pivot query. The only writers are Filament's tenancy and the `default(1)`. **So every row created by the API, a frontend controller, a seeder or a factory silently became team 1.**

A `team_id = 1` row therefore means one of two indistinguishable things. Every such row is **unverified until positively attributed**, and what cannot be attributed is **quarantined, not assigned** — under a real tenancy boundary a wrong attribution is a cross-merchant leak that the global scope will then enforce and hide. Quarantine is recoverable; a confident wrong assignment is not.

### 6.4 Six of forty-seven tenancy pairings are coherent

`IsTenantModel` is used by **37 models**. Only **6** sit on a table that carries `team_id` — `products`, `product_categories`, `customers`, `orders`, `collections`, `coupons`. The other **31** resolve `->team` to `where team_id = ?` against a table that does not have the column. Separately, **10** of the 16 tables that *do* carry `team_id` have no model using the trait; most declare `team()` inline instead.

Two of the 31 reach a tenant-scoped Filament panel today — `DiscountResource` and `MenuResource`, neither setting `$isScopedToTenant = false`. **The outcome cannot be determined from this repository**: there is no `.env` and no database here.

- If Filament applies the scope, listing either resource raises an unknown-column error and the page is broken.
- If Filament skips scoping when the relation cannot resolve, both list **every tenant's rows** — a fourth data-isolation breach.

**Someone with a running instance should open `/admin/discounts` and record which.** Both outcomes need fixing; only one is urgent. This is stated as undetermined rather than guessed because the two answers carry different severities and there is no evidence here to choose between them.

Adjacent but not a defect: `app/Filament/Resources/CustomerSegmentResource.php` has the identical shape, but sits in `app/Filament/Resources/` — a directory neither panel discovers. It is registered nowhere.

### 6.5 Why the fix has the shape it has

**Scope at the model, not the caller.** A global scope on the tenant trait is the single change that closes every route at once, rather than 10 controllers — and then 105 Filament classes — each remembering. Scoping at the caller is #939's original failure.

**An anonymous request has no team**, so the scope needs a merchant-resolution mechanism before it can exist. That mechanism, and the sequencing consequence it forces, are in [`MIGRATION_PLAN.md` wave 1.5](./MIGRATION_PLAN.md#wave-15--stores-channels-and-the-tenant-scope).

---

## 7. Deviations and the ADR index

Ten ADRs. The admission rule is **a deviation from the Liberu documentation, or a deliberate loss of behaviour** — not "significant decision". Every decision this effort made was significant; an ADR per decision would be meeting minutes.

| # | Decision | Kind |
| --- | --- | --- |
| [0001](./adr/0001-coverage-floor-for-extracted-modules.md) | 80% coverage floor for extracted modules | deviation — `MODULES.md` §24's `--min=100` |
| [0002](./adr/0002-dropping-the-content-security-policy.md) | Foundation adoption drops the CSP | behaviour loss |
| [0003](./adr/0003-dropping-the-password-policy.md) | Foundation adoption drops the breach-check password policy | behaviour loss |
| [0004](./adr/0004-no-module-prefix-in-package-names.md) | Package names and namespaces drop the `module-` prefix | deviation — `MODULES.md` §9 |
| [0005](./adr/0005-bare-version-tags.md) | Release tags are bare, not `v`-prefixed | deviation — `CI.md` §Release policy |
| [0006](./adr/0006-late-bound-host-model-resolution.md) | Modules resolve host models late and never import them | extension |
| [0007](./adr/0007-events-and-identifiers-across-product-boundaries.md) | Events and stable identifiers only across product boundaries | extension |
| [0008](./adr/0008-reviews-and-ratings-merge.md) | Reviews/ratings merge keeps moderation, backfills customers | behaviour loss avoided |
| [0009](./adr/0009-vendor-rename-to-liberusoftware.md) | Vendor rename `liberu-eccommerce` → `liberusoftware` | deviation — naming |
| [0010](./adr/0010-modules-and-themes-are-gitignored.md) | `/modules` and `/themes` are gitignored | deviation — `THEMES.md` §3.2 |

**Deviations 0001, 0004, 0005 and 0010 each have an upstream issue against the document they deviate from** (chapter 8). A reader hitting one of those ADRs needs to know the disagreement is filed rather than forgotten.

### 7.1 Deferrals recorded rather than waved away

Not deviations, but decisions that give something up. Each is here so nobody rediscovers it as a surprise:

- **Per-merchant wording is not supported.** Translation overrides are `vendor:publish` file-based only. A runtime `Channel`-keyed layer would put a database lookup behind every `__()` on the storefront hot path, and no catalogue module names the capability.
- **RTL stays unexercised.** The `en`-only decision leaves it untested, which is precisely the failure mode that got the translations question filed. `rtl_locales` is declared in `localization-core`'s config and nothing exercises it.
- **The host keeps the auth, profile and teams views indefinitely** — 21 files, as `THEMES.md` §5's application fallback. `identity-core`, `profiles` and `organizations-teams` ship no views at all.
- **The schema stays permanently mixed.** New tables carry the module prefix; ~122 existing tables keep their bare names. Renaming them during extraction would put a schema-wide rename in the same waves as a live data-isolation fix.
- **No aggregate `liberusoftware/ecommerce` metapackage.** `MODULES.md` §9 lists one; nothing in the fleet has ever published one. Recorded as a §9 finding, not a deviation.
- **Roughly 30 CMS/CRM files stay in the host** (§4.2), until those products have repositories.

---

## 8. Upstream gaps

Every deviation above is also a disagreement with something upstream, and disagreements that live only in this repository's ADRs become this repository's permanent problem. Eighteen issues across four repositories, plus one per foundation package for the missing view layer.

### 8.1 `liberusoftware/documentation`

| Gap |
| --- |
| **§9's `module-` prefix on presentation adapters contradicts all 40 published packages.** `module-blog-filament` the repository publishes `liberusoftware/blog-filament`. → ADR 0004 |
| **`CI.md` §Release policy's `v1.2.3` example contradicts the fleet's workflow triggers**, which are `['[0-9]+.[0-9]+.[0-9]+']`. → ADR 0005 |
| **`THEMES.md` §3.2 forbids gitignoring `/themes`** and names an ADR + migration plan as the only instrument for changing it. → ADR 0010 |
| **`MODULES.md` §30's definition of done is circular** — one bullet requires the repository whose creation it is gating |
| **`MODULES.md` §24's `--min=100` is unreachable for code extracted from an untested host.** → ADR 0001 |
| **`ECOMMERCE.md` names six modules "Commerce …" inside the Ecommerce product**, producing `module-ecommerce-commerce-core` stutter repositories |
| **`BOILERPLATE.md` is physically triplicated** — the full `## Canonical Application Foundation Scope … ## 20` run appears three times (lines 3–329, 330–656, 657–982) under one H1 |
| **`THEMES.md` §5's module-default-view step is unexercised fleet-wide.** `theme-default`'s views directory holds one `.gitkeep`, and no foundation module has a `resources/views` at all |

### 8.2 `liberusoftware/.github`

| Gap |
| --- |
| `package-tests.yml` to invoke Composer scripts rather than binaries, and upload to Codecov |
| Tagged releases, so callers can pin instead of `@main` |
| `package-compatibility.yml` to gain the declared min/current PHP × Laravel matrix |
| A fourth reusable workflow for themes' `visual.yml` (`THEMES.md` §18.1). **No theme in the fleet has one** |

### 8.3 `liberusoftware/boilerplate-scripts`

| Gap |
| --- |
| The nightly dependency-graph check command — whole-graph acyclicity in the host is a *lagging* detector, so a nightly `fleet`-driven `composer.json` check runs ahead of it and **files an issue rather than only reddening a cron** |
| The `fleet promote` command — the eight-step operation in [`MODULE_DEVELOPMENT.md` §6.2](./MODULE_DEVELOPMENT.md#62-what-promotion-does) |
| **`bin` is wrong.** `publish-components` was deleted in `a7461a4` but is still declared; `fleet`, `measure-coverage` and `set-coverage-thresholds` are undeclared |

Both command contributions are **timeboxed with a host-script fallback**. A third party does not get a veto over this schedule.

### 8.4 `liberusoftware/boilerplate-laravel`

| Gap |
| --- |
| Confirm the committed `modules/` tree — **726 tracked files**, while the same code is also installed from VCS — is monorepo residue rather than intended vendoring. Its `release.yml` guards the duplication with `git diff --exit-code --stat -- modules themes`, a check that exists only because the duplication does |

### 8.5 The fleet

| Gap |
| --- |
| **`v`-prefixed tags bypassed `install.yml` and `compatibility.yml`.** `module-analytics-core`'s `v1.0.4` published having silently skipped both release gates |
| **The repository generator.** ~1,930 provisioned stubs across seven products carry: a `tests.yml` badge with no `.github/` directory, a `Latest release` badge against repositories with no releases, four unqualified claims per README about a repository containing one Markdown file, and a `composer require` line that is the repository slug — provably a paste, since `module-crm-crm-core`'s README reads `# Crm: Crm Core Core Module`. §6.3 and §7 forbid all four |
| **One issue per foundation package for the missing view layer** — the §5 chain cannot resolve a module default view for a package that ships none |

### 8.6 One number that frames all of it

**~1,930 module repositories exist across seven products, and not one product-module package is published anywhere.** Every name form 404s on Packagist. The 414 ecommerce stubs contain a single generated `README.md` each — no `composer.json`, no `src/`, no `module.json`, no `.github/`.

This does not change the sequencing — boundaries are still proven in the monorepo before code moves — only the mechanics: promotion pushes into an existing repository rather than creating one. Provisioning defects are tracked in [The 414 provisioned ecommerce module repositories](https://github.com/liberusoftware/ecommerce-laravel/issues/956).

---

## 9. What this document does not cover

- **Feature completeness against the catalogue.** 105 modules are specified; a fraction exist here. That is unbuilt work, carried by the execution epics, not a conformance finding.
- **The five out-of-scope flavours** — `react`, `vue`, `nuxt`, `flutter`, `react-native`. A separate frontend programme.
- **The actual distribution of `team_id` across environments.** It cannot be read from a repository with no `.env` and no database. [The tenant-distribution checklist](https://github.com/liberusoftware/ecommerce-laravel/issues/944) carries the queries and needs a human at a real database; it gates the wave-2 backfill.
- **Remediation ordering.** [`MIGRATION_PLAN.md`](./MIGRATION_PLAN.md).
