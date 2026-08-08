# Standards gap audit: `ecommerce-laravel` vs the Liberu `standards/` documents

Resolves the research question in [issue #927](https://github.com/liberusoftware/ecommerce-laravel/issues/927).

**Scope.** Every standard in [`liberusoftware/documentation/standards/`](https://github.com/liberusoftware/documentation/tree/main/standards) except the seven presentation flavors the issue puts out of scope (`REACT`, `VUE`, `NUXT`, `FLUTTER`, `REACT-NATIVE`, `INERTIA`, `MOBILE`). `README.md` is an index, not a standard, and is not audited. That leaves 29 sections below.

**Method.** Standards read from the `liberusoftware/documentation` repository. Evidence read from this repository at commit `2d1024c`. Where a standard is ambiguous, `/home/tom/code/boilerplate-laravel` — the documentation's named reference implementation — was used to resolve intent.

**This is an audit, not a plan.** Findings are stated with evidence and classified. Remediation sequencing is decided elsewhere.

## How to read this

**Severity**

| Level | Meaning |
| --- | --- |
| Critical | Data exposure, money correctness, or a released contract is at risk today. |
| High | A standard's central requirement is not met and the gap compounds with every change. |
| Medium | A required practice is absent but the blast radius is contained. |
| Low | Hygiene, naming, or documentation debt. |

**Gap class**

- **Mechanical** — a lint rule, a config file, a rename, a codemod. Volume is the only cost.
- **Structural** — the code is shaped wrong. Fixing it means moving behavior across layer boundaries, and tests must move with it.

---

## Repository baseline

| Metric | Value |
| --- | --- |
| PHP files under `app/` | 400 |
| PHP files under `tests/` | 201 |
| PHP files under `database/` | 161 |
| Blade templates under `resources/views/` | 140 |
| Files under `app/` with `declare(strict_types=1);` | **2 of 400** |
| Files under `tests/` with `declare(strict_types=1);` | **0 of 201** |
| Files under `database/` with `declare(strict_types=1);` | **0 of 161** |
| `enum` declarations in `app/` | **0** |
| `final class` declarations in `app/` | 2 |
| `readonly` usages in `app/` | 13 |
| Interfaces in `app/Interfaces/` | 3 |
| Container bindings of those interfaces | **0** |
| Models | 90 |
| Policies | 17 |
| Controllers | 39 |
| FormRequests | **2** |
| Services | 24 |
| Jobs | 3 |
| Filament classes | 105 |
| Livewire components | 5 |
| Migrations | 122 (176 foreign keys, 115 indexes) |
| Application-owned translation catalogues in `lang/` | **0** (only `lang/vendor/filament-shield/`) |
| `pint.json` | **absent** |
| Static-analysis tool in `composer.json` | **absent** |
| Composer `test` / `test:coverage` scripts | **absent** |
| `modules/` and `themes/` directories | **absent** |

The shape of that table is the headline finding on its own. 105 Filament classes and 90 models against 24 services, 3 jobs, 2 FormRequests and 3 interfaces. The standards assume a domain layer sits between persistence and presentation; here there is essentially none, so Filament resources, controllers and models absorb the work the standards assign to services, actions and contracts.

---

## PHP.md — PHP 8.5 standard

**Requires.** `declare(strict_types=1);` in new executable PHP files. Typed properties, parameters, returns. Constrained values as enums or value objects. Immutable DTOs and `readonly` where mutation is not required. Composer autoloading only — "never scan directories to invent an autoloader."

**This repo.**

- `declare(strict_types=1);` appears in **2 of 400** files under `app/` — `app/Models/Permission.php` and `app/Models/Role.php`. Zero of 201 files under `tests/`. Zero of 161 under `database/`.
- **Zero enums exist anywhere in the application.** There is no `app/Enums`, and `grep` for an `enum` declaration under `app/` returns nothing. The single most constrained value in an e-commerce domain — order status — is ten string constants plus a hand-maintained adjacency array at `app/Models/Order.php:17-59`, re-validated by an `in_array` at `app/Models/Order.php:142`.
- No value objects. Money is a bare `float` end to end (see DATABASE.md and MODELS.md below). The `TESTING.md` standard's own worked example is a `Money` value object; this repo has none.
- Untyped properties on injected collaborators: `app/Http/Controllers/CheckoutController.php:22-28` declares four `protected $shippingService;`-style properties with no type, then assigns them in a hand-written constructor at `:30-35` instead of using promotion.
- Implicit nullable parameter — removed behavior in PHP 8.4+ — at `app/Modules/Support/ExternalModuleLoader.php:44`: `public function loadFromVendor(string $vendorPath = null)`.
- **A hand-rolled autoloader exists.** `app/Modules/Support/ExternalModuleLoader.php` scans directories (`:35`), parses `vendor/composer/installed.json` to recover PSR-4 maps (`:47`), and `require_once`s files it finds by path at `:86` and `:118`. This is the exact practice PHP.md forbids by name, and FILAMENT.md §4 and LIVEWIRE.md §5 forbid again.
- `app/database/seeders/MenuSeeder.php` is a 15-line file with **no `<?php` tag, no namespace, and no class** — the first line is `public function run()`. PHP parses it as inline HTML, so `php -l` reports no error and nothing catches it. Its body is an unfinished generated fragment containing `// ... existing code ...` placeholders at `:4` and `:10`. It is dead, and it is the only file in the repository containing that placeholder.

**Gap.** The strict-types baseline is effectively absent (0.5% adoption). Enums and value objects, which the standard names as the mechanism for constrained values, are not used at all. A forbidden filesystem autoloader ships in `app/`.

**Severity.** High. **Class.** The `declare` sweep is *mechanical* (Pint's `declare_strict_types` rule plus one review pass for the fallout). Enums and value objects for status and money are *structural* — every string comparison and float arithmetic site changes with them. `ExternalModuleLoader` is *structural* (delete or replace with Composer discovery). The broken `MenuSeeder.php` is a *mechanical* deletion.

---

## PSR.md — PHP Standards Recommendations

**Requires.** PSR-12 mandatory for source, tests, migrations, configuration classes. PSR-4 autoloading with stable namespaces and no runtime filesystem scanning. `declare(strict_types=1);` unless a documented bootstrap constraint prevents it. One class per file. `PascalCase` classes, `camelCase` methods. PSR-3 `LoggerInterface`, PSR-20 `ClockInterface` for time-sensitive services, PSR-18 `ClientInterface` for substitutable outbound HTTP.

**This repo.**

- PSR-4 is declared correctly in `composer.json` (`App\` → `app/`, plus `Database\Factories\` and `Database\Seeders\`), but `app/database/seeders/MenuSeeder.php` sits at a path that would map to the namespace `App\database\seeders` — lowercase segments, and the file declares no namespace at all. It resolves through neither map.
- Runtime filesystem scanning is present — `ExternalModuleLoader`, cited above. PSR.md §"Package boundaries" requires "no runtime filesystem scanning" explicitly.
- PSR-12 conformance is unverified because no formatter runs in CI (see PINT.md). Observable breaches include `app/Traits/IsTenantModel.php:1`, which has trailing whitespace after `<?php` and no terminating newline.
- PSR-20 `ClockInterface` is used nowhere. Time-sensitive domain logic calls the wall clock directly — for example `now()` inside `database/seeders/UserSeeder.php:25` and date arithmetic throughout the analytics services. The standard singles out "expiry, retries, invitations, settings, and security" as the cases that need it; coupon and gift-card expiry are exactly that.
- PSR-3 `LoggerInterface` is never type-hinted; services reach for the `Log` facade instead (3 occurrences across `app/Services/`).
- PSR-18 `ClientInterface` is never used; outbound HTTP is the `Http` facade in 4 services, so no transport substitution seam exists at the boundary the standard names.

**Gap.** PSR-4 is broadly honored with one broken file. PSR-12 is unenforced rather than known-conformant. The interoperability PSRs the standard calls out for replaceable boundaries (3, 18, 20) are unused at exactly the boundaries that are replaceable.

**Severity.** Medium. **Class.** PSR-12 is *mechanical*. Adopting PSR-20/PSR-18 at the provider boundaries is *structural* but small — it lands with the payment/carrier adapters that already exist.

---

## PINT.md — Laravel Pint standard

**Requires.** Pint pinned in dev dependencies. **`pint.json` at the repository root, committed with the lock file.** The strictest supported preset. Format owned source, tests, migrations, seeders, factories, configuration, commands, providers. Run Pint before committing **and in CI** — "a pull request fails when formatting changes are required."

**This repo.**

- `laravel/pint: ^1.13` is in `require-dev` (`composer.json`).
- **There is no `pint.json`.** The reference implementation has one — `/home/tom/code/boilerplate-laravel/pint.json` pins the `laravel` preset plus three explicit rules. This repo runs whatever Pint's built-in default is, which is not a reviewed configuration decision.
- **Pint never runs in CI.** All four workflows (`.github/workflows/install.yml`, `main.yml`, `security.yml`, `tests.yml`) were read; none invokes `pint`. `tests.yml:47` runs `php artisan test`; `main.yml:50` runs `phpunit`; `security.yml:42` runs `composer audit`; nothing formats or checks formatting.
- No `composer lint` or `composer format` script exists in `composer.json`.

**Gap.** The formatter is installed, unconfigured, and unenforced. The standard's central claim — that formatting is a required quality gate — is not true here.

**Severity.** Medium. **Class.** Mechanical. Copy `pint.json` from the reference implementation, add one CI step, absorb one large formatting commit. The standard explicitly asks for that commit to be kept separate from behavioral changes.

---

## LARAVEL.md — Laravel 13 standard

**Requires.** Latest stable Laravel 13 supported by the lock file. Domain rules in modules and application services; "controllers, Livewire components, Filament resources ... orchestrate them." Route model binding, form requests or dedicated validators, policies/gates, middleware deliberately. Resolve team/tenant context **before** protected queries and mutations — "UI route guards are not authorization." Transactions for local invariant changes. Configuration in `config/`.

**This repo.**

- `composer.lock` resolves `laravel/framework` to `v13.23.0`. The version is current.
- **The application skeleton is not.** `bootstrap/app.php` is the pre-Laravel-11 file: it constructs `Illuminate\Foundation\Application` directly and singleton-binds `App\Http\Kernel` (`:29`), `App\Console\Kernel` (`:34`), and `App\Exceptions\Handler` (`:39`). All three legacy classes still exist. There is **no `bootstrap/providers.php`**; providers are listed in a `config/app.php` `providers` array at `:158-179`. The reference implementation at `/home/tom/code/boilerplate-laravel/bootstrap/providers.php` uses the modern structure. This is Laravel 13 running a Laravel 10 composition root.
- Middleware is configured in `app/Http/Kernel.php` (95 lines) rather than `bootstrap/app.php`'s `withMiddleware()`.
- Tenant context is **not** resolved before protected queries outside Filament — see the Critical finding under API.md.
- Domain rules are not in application services. They are in controllers (`CheckoutController`), models (`Product`, `Order`), and Filament classes. Detailed under CONTROLLERS.md, MODELS.md, and FILAMENT.md.
- `app/Providers/TeamServiceProvider.php` is registered in production at `config/app.php:176` and its `register()` method binds **twelve classes that do not exist**: `FamilyTree365\LaravelGedcom\Utils\BatchData` and eleven siblings (`:25-35`) mapped onto `App\Models\Person`, `App\Models\Family`, `App\Models\Addr` and so on. Neither side exists — `familytree365/laravel-gedcom` is absent from `composer.json` and `composer.lock`, and `app/Models/` contains no `Person.php`, `Family.php`, `Addr.php`, or `BatchData.php`. This is genealogy-application wreckage left behind by a fork, still loaded on every request. It does not fatal only because `bind()` with a string concrete is lazy.

**Gap.** The framework version is compliant; the application composition is three major versions stale, and one registered provider is entirely dead cross-domain code.

**Severity.** High. **Class.** Structural. The skeleton migration touches bootstrap, kernels, exception handling, and middleware registration together. Deleting `TeamServiceProvider` is mechanical and independent.

---

## GUIDELINES.md — Liberu coding guidelines

**Requires.** Identify the owning module, public contract, and tenant boundary before coding. Dependencies point inward toward stable contracts. Strict types, constructor injection, immutable value objects, small focused classes. Validate and authorize at the server boundary. Make retries, idempotency, transactions, tenancy, and audit events explicit for mutations. **"Do not commit secrets, private data, generated credentials, unexplained snapshots, or unrelated formatting changes."** Descriptive nouns for domain concepts, verbs for actions, kebab-case route identifiers.

**This repo.**

- **A production error log is committed to the repository root.** `error_log` is tracked by git (confirmed via `git ls-files`), 2,637 bytes of PHP fatal errors from July 2025, and it leaks the deployment's absolute filesystem path: `/home/liberu/projects/ecommerce-laravel/app/Filament/Admin/Resources/CategoryResource.php`. This is the "unexplained snapshot" the guideline names.
- There is no module ownership anywhere to identify. No `modules/` directory, no `themes/` directory. The reference implementation has both.
- Constructor injection is the minority pattern: **4 of 24** service classes declare a constructor. The rest are stateless method bags reaching for facades — `DB` (5 files), `Http` (4), `Log` (3), plus `Session`, `Notification`, `Hash`, `Cookie`, `Cache`, `Auth`.
- Service-locator resolution in presentation code: `app/Filament/Admin/Pages/DropxlImport.php:48` and `:67` call `app(DropxlService::class)` inside methods rather than injecting the collaborator.

**Gap.** The tracked `error_log` is a direct, single-line violation of an explicit prohibition. The injection and module-ownership guidance is broadly unmet.

**Severity.** Medium overall; the committed log is a discrete High-confidence finding. **Class.** Deleting `error_log` and gitignoring it is *mechanical*. Constructor injection across 20 services is *structural*.

---

## ADOPTION.md — Progressive delivery standard

**Requires.** The same quality baseline at every deployment size: PHP 8.5/Laravel 13, locked dependencies, strict server-side validation, **authorization policies**, secure secret handling, documented updates. Own migrations, constraints, indexes, retention, export, deletion, backups, recovery in the module that owns the data. Test public actions, permissions, invalid input, tenant boundaries, jobs/events, migrations, and the highest-risk failure paths. Document what a simpler profile does not provide.

**This repo.**

- PHP 8.5 and Laravel 13 are declared and locked. Dependencies are locked. That part holds.
- `"minimum-stability": "beta"` in `composer.json` — the application accepts beta releases of any dependency, which undercuts "locked dependencies" as a stability claim.
- Authorization policies exist for **17 of 90** models. Coverage of the protected surface is partial (see FILAMENT.md).
- No backup or restore procedure is documented. No runbooks (`docs/runbooks` does not exist). No retention, export, or deletion policy per data owner — though `GdprExportService` and `GdprErasureService` exist, nothing documents what they cover.
- No operating profile is recorded anywhere, so "document what a simpler profile does not provide" has no artifact.

**Gap.** The infrastructure-independent parts of the baseline — policies, tested tenant boundaries, documented recovery — are the parts missing, and those are precisely the ones the standard says do not scale down.

**Severity.** Medium. **Class.** Structural (policy coverage, tenant tests) plus documentation debt.

---

## CONTRIBUTING.md — contribution workflow

**Requires.** PHP 8.5/Laravel 13. PSR-12 and PSR-4, strict typing where compatible, typed signatures, dependency injection, small actions, explicit contracts. Authorize every boundary server-side. Writes transactional, queued work idempotent, migrations reversible or with a documented recovery path. Run formatting, linting, tests, static analysis, architecture checks, security checks appropriate to the change. A pull request "must identify changed public contracts and include tests or explain why tests are not applicable."

**This repo.**

- **There is no `CONTRIBUTING.md` in this repository**, and no `SECURITY.md`, and no `CHANGELOG.md`. `.github/` contains only `issue_template.md` and the four workflows — no pull request template.
- All 122 migrations define a `down()` method, so reversibility holds.
- Static analysis and architecture checks cannot be run — neither is configured (see TESTING.md, CI.md).

**Gap.** The contribution contract is not published to contributors, and half the checks it mandates have no implementation in the repository.

**Severity.** Low. **Class.** Mechanical.

---

## CLASSES.md — Classes standard

**Requires.** One cohesive responsibility, explicit dependencies, stable names, observable contract. Small immutable value objects, focused actions, queries, policies, adapters, domain services. **Constructor injection and explicit visibility/types; avoid service-locator calls and static mutable state.** Cheap constructors; I/O in named methods. Make invalid state difficult to create. "Separate domain rules, application orchestration, persistence, transport, and presentation."

**This repo.**

- Service-locator calls in presentation: `app/Filament/Admin/Pages/DropxlImport.php:48`, `:67` — `app(DropxlService::class)`.
- Static resolution instead of the container: `app/Factories/PaymentGatewayFactory.php:12` — `public static function create(string $gateway): PaymentGatewayInterface`, and `app/Factories/CarrierRateFactory.php:16` likewise. Both are the substitution seams that should be container bindings; instead callers reach a static method.
- Business behavior as a static method on a Filament resource: `app/Filament/Admin/Resources/Products/ProductResource.php:247` — `protected static function export(Collection $records)` builds a CSV, classifies stock levels at `:261`, writes to `storage_path('app/public/...')` at `:266`, and returns a download.
- Layer separation fails at every layer, documented in the sections below: domain rules in controllers (CONTROLLERS.md), in models (MODELS.md), and in Filament (FILAMENT.md).
- 24 services with 98 public methods; **76 have declared return types, 22 do not.**
- No value objects. `app/Support/` contains one class, `EuVat.php`.

**Gap.** The layering rule the standard closes on — "separate domain rules, application orchestration, persistence, transport, and presentation" — is not observed anywhere in the application.

**Severity.** High. **Class.** Structural.

---

## CONCERNS.md — Concerns standard

**Requires.** Traits for small, cohesive cross-cutting behavior with a clear contract. Prefer composition when behavior has meaningful collaborators or lifecycle. Narrow, namespaced, documented, safe across multiple owners. **"Do not use traits to hide authorization, queries, transactions, external calls, mutable global state, or unexpected boot hooks."** Before adding a trait, show at least two real owners.

**This repo.**

- `app/Traits/` holds exactly one trait, `IsTenantModel.php`, used by **37 models**. Two real owners is satisfied many times over.
- Its entire body is a single `team(): BelongsTo` relation. It declares no global scope, no boot hook, no fail-closed behavior. It is a naming claim, not an enforcement mechanism: a model that `use`s `IsTenantModel` is not tenant-scoped by anything. The scoping happens only inside the Filament admin panel, only when `Features::hasTeamFeatures()` is on (`app/Providers/Filament/AdminPanelProvider.php:118`). Every query outside that panel is unscoped — see the Critical finding under API.md.
- `app/Modules/Traits/` holds `Configurable.php` and `HasModuleHooks.php`, supporting the unused module system.
- The file has a PSR-12 defect at `app/Traits/IsTenantModel.php:1` (trailing space after `<?php`, no final newline).

**Gap.** The trait itself is compliant with CONCERNS.md — it is narrow and hides nothing. The defect is that its name promises tenancy the codebase does not deliver, which is a tenancy finding rather than a concerns finding.

**Severity.** Low as a concerns violation. **Class.** Mechanical.

---

## CONTRACTS.md — Contracts standard

**Requires.** Contracts for boundaries that are genuinely replaceable — "not for every class." Small, capability-focused, typed, framework-neutral. Document authorization, tenancy, error, consistency, idempotency, lifecycle semantics. **"Prefer immutable DTOs and explicit result/error types over leaking ORM models or framework internals."** **"Test the contract against the concrete adapter and meaningful alternate/fake implementations."**

**This repo.**

- Three interfaces exist, all in `app/Interfaces/` (not `app/Contracts/`): `PaymentGatewayInterface.php`, `CarrierRateInterface.php`, `Orderable.php`.
- Two of the three are legitimate by the standard's own test — real substitution with multiple implementations. `PaymentGatewayInterface` is implemented by `app/Services/PaymentGateways/PayPalGateway.php:14` and `StripeGateway.php:10`. `CarrierRateInterface` is implemented by `app/Services/Shipping/EasyPostCarrier.php:18`. **The repo got this call right.**
- But neither is bound in the container. `grep` across `app/Providers/` finds one application binding — `ModuleManager` at `app/Providers/AppServiceProvider.php:17` — and the rest are Fortify/Jetstream/Filament response classes. Substitution happens through static factories instead.
- **No contract test suite exists.** There is no `tests/Contract/` directory. The standard requires "a real implementation, a deterministic test fake, contract tests, and migration guidance." None of the four is present for either interface.
- The remaining 22 services have no interface, and return untyped arrays that leak provider shapes. `app/Services/DropxlService.php` returns `['success' => bool, 'data' => mixed]` arrays consumed structurally at `app/Filament/Admin/Pages/DropxlImport.php:51-59` — the caller reaches through `$data['data'] ?? $data['categories'] ?? ...`, so the provider's response shape is the contract.
- ORM models leak through every API endpoint — see API.md.

**Gap.** Where contracts exist they are well-chosen but untested, unbound, and undocumented. Where results cross boundaries they are untyped arrays carrying provider internals.

**Severity.** Medium. **Class.** Structural — result types and contract suites are new code that changes call sites.

---

## SERVICES.md — Services standard

**Requires.** One clear responsibility per service, verb-based action names for use cases. **Inject contracts and collaborators; keep framework adapters at the boundary.** Authorize before protected reads/mutations, establish tenant context, make transaction scope explicit. **Return typed results or purpose-built read models.** **"Keep external calls behind provider-neutral adapters with timeouts, retries, rate limits, reconciliation, and audit evidence."** Avoid god services and hidden queries.

**This repo.** 24 services, 3,211 lines.

| Service | LOC |
| --- | --- |
| `app/Services/AnalyticsService.php` | 271 |
| `app/Services/DropxlService.php` | 244 |
| `app/Services/ProductRecommendationService.php` | 230 |
| `app/Services/GdprExportService.php` | 201 |
| `app/Services/CheckoutService.php` | 201 |
| `app/Services/ChatService.php` | 200 |

- **Naming.** All 24 are `NounService`. The standard asks for "a verb-based action name where it performs a use case." Not one is verb-named.
- **Injection.** 4 of 24 declare a constructor. Collaborators arrive via facades — `DB` (5 files), `Http` (4), `Log` (3), `Session`, `Notification`, `Hash`, `Cookie`, `Cache`, `Auth`. The framework adapter is not at the boundary; it is threaded through the service body.
- **Authorization.** No service calls `Gate`, `authorize`, or a policy. Authorization, where it happens, happens above them.
- **Return types.** 22 of 98 public methods have none.
- **External calls are not behind adapters.** `app/Services/ShippingService.php:111` calls a hardcoded literal endpoint with no timeout, no retry, and no adapter:
  ```php
  $response = Http::get('https://api.address-verifier.com', [
      'address' => $address,
      'api_key' => config('services.address_verifier.api_key'),
  ]);
  ```
  The method is annotated at `:109-110` as "a placeholder implementation," and returns `null` on failure at `:120` — a sentinel, which PHP.md forbids for failures that hide data loss. `ViesService` (`:51`) does set a configurable timeout; it is the exception.
- **Duplicate responsibility.** `ProductRecommendationService.php` (230 lines) and `RecommendationService.php` (82 lines) coexist. `TaxCalculator.php` (171) and `TaxService.php` (125) coexist. `CheckoutService.php` (201) and `HeadlessCheckoutService.php` (175) coexist. Ownership of each use case is ambiguous.

**Gap.** The service layer is a set of stateless helper bags with facade-hidden dependencies, no authorization, partial typing, and no provider isolation — which is the "dumping ground" the standard opens by ruling out.

**Severity.** High. **Class.** Structural.

---

## CONTROLLERS.md — Controllers standard

**Requires.** "Controllers are thin HTTP adapters ... they do not contain domain workflows." One method per route/use case with **explicit route names** and middleware. **Validate through form requests or dedicated validators**, authorize through policies/gates, resolve tenant context before access. Delegate writes to actions/services and reads to purpose-built queries. **"Avoid database queries, provider SDK calls, hidden side effects, and cross-module orchestration in controllers."**

**This repo.** 39 controllers, 3,744 lines. **2 FormRequests** in `app/Http/Requests/`.

The clearest violation is `app/Http/Controllers/CheckoutController.php` — 411 lines that run the entire checkout workflow inside the HTTP layer:

| Line | What the controller does |
| --- | --- |
| `:117` | `Validator::make($request->all(), [...])` — inline validation, not a FormRequest |
| `:153`, `:245` | `Product::find($productId)` — direct persistence access, inside a loop |
| `:275` | `DB::transaction(function () use (&$order, ...))` — a 15-argument closure owning the transaction boundary |
| `:279` | `Order::create([...])` — the aggregate is constructed here |
| `:324`, `:332`, `:344` | `$order->transitionTo(...)` — order state machine driven from the controller |
| `:341` | `$order->update(['transaction_id' => ...])` — post-payment write |
| `:352` | `Notification::route('mail', $order->customer_email)` — side effect dispatched from HTTP |

The controller imports `DB`, `Notification`, `Session`, and `Validator` facades directly (`:14-18`).

- **`routes/api.php` names zero of its 39 routes.** `routes/web.php` carries 90 `->name()` calls against 87 route definitions, so the web side is fully named and compliant; the API side is not.
- **Four routes point at methods that do not exist.** `routes/web.php:188-191` registers `products.addToCompare`, `products.compare`, `products.removeFromCompare`, and `products.clearCompare` against `ProductController`. All four method bodies are commented out at `app/Http/Controllers/Frontend/ProductController.php:222-259`. Every one of those routes is a guaranteed 500.
- No controller calls `$this->authorize()`. Authorization is either middleware-level or absent — `routes/api.php:37` carries a comment conceding that "every method role-checks in the controller — there is no `role:` middleware alias here."

**Gap.** The layer the standard defines as "thin HTTP adapter" owns validation, persistence, transactions, the order state machine, payment orchestration, and notification dispatch. And four registered routes are dead.

**Severity.** Critical (the dead routes are live 500s; the checkout workflow is unprotected by any reusable action boundary). **Class.** Structural. The dead-route removal is *mechanical* and independent.

---

## MODELS.md — Models standard

**Requires.** Models represent persistence-owned data and its local mapping rules. Guarded/validated assignment, explicit casts, relationships, scopes, database constraints. **"Keep business invariants in domain/application boundaries when they span records, workflows, permissions, or providers."** **"Avoid hidden queries in accessors, serialization, views, jobs, and authorization checks; prevent N+1 behavior intentionally."**

**This repo.** 90 models, 6,960 lines.

**What is right.** Mass assignment is handled well — **92 models declare `$fillable` and zero declare `$guarded = []`.** Casts are declared and use `decimal:2` for money columns consistently (`app/Models/Order.php:104-109`, `app/Models/GiftCard.php:32-33`, and 20+ more).

**What is not.**

- **Business workflow on the model.** `app/Models/Product.php:242` — `notifyBackInStockSubscribers(): void` queries pending subscriptions, constructs notifications, dispatches mail through `Notification::route('mail', ...)` at `:253`, and mutates subscriber state at `:255`. That spans records, a workflow, and a provider — the three conditions the standard names for moving it out.
- **Transactional invariant on the model.** `app/Models/Product.php:395` — `adjustInventory(int $delta, string $reason): bool` opens `DB::transaction` at `:397`, performs a guarded conditional decrement at `:399-401` to hold stock at or above zero, refreshes, and writes an `InventoryLog` row at `:412`. The guard is correct, but it is a two-table invariant living on an Eloquent model and it returns `false` as a sentinel for a business rule violation rather than raising.
- **Hidden queries by design.** Four methods each fire a fresh aggregate query per call, with no memoization and no `$with`:
  - `app/Models/Product.php:336` `hasVariants()`
  - `app/Models/Product.php:346` `getTotalInventory()` — `$this->variants()->sum(...)`
  - `app/Models/Product.php:355` `getLowestPrice()` — `$this->variants()->min(...)`
  - `app/Models/Product.php:364` `getHighestPrice()` — `$this->variants()->max(...)`

  `getLowestPrice()` calls `hasVariants()` first, so rendering one price is two queries; a 24-product grid is ~48. This is the N+1 the standard says to prevent intentionally.
- **The order state machine lives on the model.** `app/Models/Order.php:41-59` holds a `TRANSITIONS` adjacency array; `:142` enforces it with `in_array($status, self::TRANSITIONS[$from] ?? [], true)`. Correct logic, wrong owner — and it is driven directly from `CheckoutController` (`:324`, `:332`, `:344`) and from `app/Jobs/DispatchDropshippingOrder.php:117`.
- **Relationship methods lack return types.** `app/Models/Product.php:65` `category()`, `:70` `collections()`, `:75` `tags()`, `:91` `cartItems()`, `:96` `orderItems()` and others declare none. Scopes are untyped too: `:260` `scopeWithTag($query, Tag $tag)`.
- **Dead code in an accessor.** `app/Models/Product.php:85-89` — `getImageUrlAttribute()` returns the raw column and carries the real implementation commented out beneath it.
- Money is `decimal:2` in the database and cast, but arrives in PHP as a float and is computed as one — `app/Services/ShippingService.php:104` rounds to two places specifically because "float weight math otherwise leaks e.g. 0.30000000000000004," which is the codebase acknowledging the problem in a comment rather than fixing the type.

**Gap.** Persistence mapping is genuinely good. Business invariants, workflows, and provider calls that the standard places at domain boundaries are on the models instead, and the read path is N+1 by construction.

**Severity.** High. **Class.** Structural.

---

## DATABASE.md — Database implementation standard

**Requires.** A module owns its tables, indexes, foreign keys, constraints, migrations. Deterministic, reversible migrations. Never edit a released migration. **Seeders: "Separate baseline/reference seeders from demo/sample seeders. Production deployments run only the required baseline set."** **"Use stable keys and upserts for reference data so rerunning a seeder does not duplicate records."** Factories with explicit named states. Constraints for invariants, indexes for observed access paths, transactions for local consistency. **"Prevent N+1 queries and hidden database access in views, serializers, policies, jobs, and accessors."**

**This repo.** 122 migrations, 176 foreign key definitions, 115 index definitions, `down()` on all 122.

**What is right.** Money columns are `decimal(10,2)` throughout — `database/migrations/2023_09_28_030001_create_product_variants_table.php:16`, `2024_10_10_000008_create_discounts_table.php:17`, `2026_02_16_000001_add_tax_amount_to_orders.php:15`, and 20+ more. Exchange rates use `decimal(10,6)`. Nothing money-shaped is stored as a float. Reversibility is universal.

**What is not.**

- **Demo data is in the production seeding chain.** `database/seeders/DatabaseSeeder.php:24-31` calls, in one list: `PermissionsTableSeeder`, `RolesSeeder`, `DefaultTeamSeeder`, `UserSeeder`, **`DummyDataSeeder`**, `MenuSeeder`. `DummyDataSeeder` pulls in `database/seeders/DummyData/` — `CarProductSeeder`, `ProductSeeder`, `ProductCollectionSeeder`, and more. Running `php artisan db:seed` on a production install creates sample products. The standard's separation requirement is not implemented.
- **Seeders are not idempotent.** Only 5 of 12 use `firstOrCreate`/`updateOrCreate`/`upsert`. `database/seeders/UserSeeder.php:22` calls `User::create([...])` with a fixed `admin@example.com`; a second run hits a unique constraint. (Credit where due: `:21` generates a random 12-character password and `:34` prints it rather than hardcoding one.)
- **Two competing permission seeders.** `PermissionsSeeder.php` and `PermissionsTableSeeder.php` both exist; only the latter is wired in. Ownership of reference data is ambiguous.
- **A seeder outside the seeder tree.** `app/database/seeders/MenuSeeder.php` — the broken no-`<?php` fragment described under PHP.md — shadows the real `database/seeders/MenuSeeder.php` by name.
- Only **5 migrations mention `team_id`** across 122, against 37 models using `IsTenantModel`. The tenant column is not consistently present, so tenant scoping could not be enforced at the database level even if the application tried.
- N+1 is present in accessors (MODELS.md) and in the Filament export path (`app/Filament/Admin/Resources/Products/ProductResource.php:257` reads `$record->category->name` per row with no eager load).

**Gap.** Schema and migration discipline is the strongest area of the repository. Seeding discipline is the weakest — demo fixtures ship in the baseline chain and reruns are unsafe.

**Severity.** Medium; the seeder chain alone is High. **Class.** Mechanical — split the chain, convert `create()` to `firstOrCreate()`, delete the duplicate and the broken file.

---

## OBJECT-ORIENTED-PROGRAMMING.md — OOP standard

**Requires.** Encapsulation, composition, polymorphism at genuine variation points, dependency inversion. Invariants with the object that owns them. Interfaces where consumers need substitution; concrete classes where abstraction adds nothing. Shallow, intentional inheritance. **"Separate domain policy, application orchestration, infrastructure adapters, and presentation concerns."** **"Keep domain objects independent of Laravel."**

**This repo.**

- Polymorphism is used at the two real variation points — payment gateways and shipping carriers — and nowhere speculatively. By the standard's "choose an abstraction because a real boundary requires it" test, this is correct restraint.
- But dependency inversion is not completed: the abstractions are resolved by static factories (`app/Factories/PaymentGatewayFactory.php:12`, `app/Factories/CarrierRateFactory.php:16`) rather than injected, so consumers depend on a concrete factory rather than on the interface.
- **There are no domain objects independent of Laravel.** Every behavior-bearing class in the application extends or depends on a framework base: `Model`, `Controller`, `Resource`, `Page`, `Component`, `Seeder`. `app/Support/EuVat.php` is the only framework-free class in `app/`.
- Invariants sit away from their owners: stock non-negativity is on `Product` (`:395`), order transitions are on `Order` (`:142`), checkout consistency is in `CheckoutController` (`:275`).
- Inheritance is shallow throughout — no deep hierarchies were found.

**Gap.** Variation points and inheritance depth are handled well. The layer separation clause and "domain objects independent of Laravel" are unmet across the board.

**Severity.** High. **Class.** Structural.

---

## DOMAIN-DRIVEN-DESIGN-PATTERNS.md — DDD patterns standard

**Requires.** Bounded contexts/modules around cohesive business capabilities. Entities and aggregates for identity and invariants, **value objects for constrained values**, domain services for cohesive rules. **Application actions/services to coordinate a use case.** Read models/query objects for optimized reads. Publish domain events after committed local changes. Keep aggregates small, transactions local, and "never expose private persistence as a cross-module contract."

**This repo.**

- **There are no bounded contexts.** No `modules/` directory. `app/Modules/` contains a module *runtime* — `ModuleManager`, `BaseModule`, `ModuleInterface`, four lifecycle events, and `ExternalModuleLoader` — but zero modules are defined by it. It is scaffolding for a capability that was never used, and it carries the forbidden filesystem autoloader.
- **There are no value objects.** Money, currency, SKU, address, VAT number, and order status are all primitives. This is the same finding as PHP.md, restated here because DDD names value objects as the mechanism for constrained values.
- **There are no application actions.** `app/Actions/` contains only vendor scaffolding — `Fortify/`, `Jetstream/`, `Socialstream/`. No domain use case has an action class. The use cases live in controllers and Filament pages.
- **There are no read models or query objects.** Reads are `Model::query()` built inline in controllers (`app/Http/Controllers/Api/ProductController.php:21`) and in Filament table definitions.
- Domain events: `app/Modules/Events/` holds four module-lifecycle events. There are no business domain events — no `OrderPaid`, no `StockDepleted`. Order state changes fire nothing.
- Private persistence *is* the cross-boundary contract: every API endpoint serializes Eloquent models directly (see API.md).

**Gap.** None of the tactical patterns the standard governs are present, and the strategic unit it is built on — the bounded context — does not exist. The standard's own caution ("do not introduce tactical patterns without a domain or lifecycle problem they solve") means this is not automatically wrong for a small app; but the repository has 90 models, 24 services, and a payments/inventory/tax/GDPR surface, which is squarely the lifecycle problem the patterns exist for.

**Severity.** High. **Class.** Structural — this is the root that most other structural findings hang from.

---

## JOBS.md — Jobs standard

**Requires.** **Pass stable identifiers and immutable values, not live models.** Establish tenant/team context explicitly and fail closed when absent. Safe to retry — idempotency keys, unique jobs, deduplication, or compensating actions. **Define backoff, timeout, max attempts, dead-letter behavior, alerting, and operator recovery.** Dispatch after commit. Redact secrets from payloads and logs.

**This repo.** 3 jobs.

**What is right — and this is the best-implemented area in the repository.**

- All three pass identifiers, not models. `app/Jobs/SendWebhookDelivery.php:22-27` uses promoted `public int $endpointId, public int $orderId, public string $event, public int $attempt = 1`. `app/Jobs/DispatchOutboundWebhook.php:17` uses `public int $orderId, public string $event`. `app/Jobs/DispatchDropshippingOrder.php:31` takes `int $orderId, string $supplierId`.
- Bounded attempts: `DispatchDropshippingOrder.php:26` sets `public int $tries = 3`; `SendWebhookDelivery.php:20` defines `private const MAX_ATTEMPTS = 3`.
- Missing records are handled: `SendWebhookDelivery.php:30-34` looks up both records and returns quietly if either is absent or the endpoint is inactive.
- Retry semantics are deliberate and documented. `DispatchDropshippingOrder.php:99-101` explains that transient failures let the exception propagate so the queue retries, while `failed()` at `:107-117` compensates by transitioning the order to `supplier_failed`. That is a compensating action, which is exactly what the standard asks for.
- The outbound HTTP call sets a timeout: `SendWebhookDelivery.php:56` — `->timeout(10)`.

**What is missing.**

- No `$backoff` on any job. No `$timeout` property (only the HTTP client timeout). No `ShouldBeUnique`, no idempotency key, no deduplication — a retried `SendWebhookDelivery` re-POSTs.
- No explicit queue assignment; everything lands on `default`.
- No tenant context is established. `SendWebhookDelivery::handle()` loads `WebhookEndpoint` and `Order` by ID with no team scoping and no fail-closed check — the standard requires failing closed when tenant context is absent.
- No dispatch-after-commit (`afterCommit()`) anywhere.
- **Zero tests reference `App\Jobs`.** The standard requires testing "retries, duplicate delivery, missing records, revoked access, dependency outages, and recovery."

**Gap.** Payload hygiene, bounded attempts, and compensation are right. Backoff, timeout, uniqueness, tenant context, and the entire test surface are absent.

**Severity.** Medium. **Class.** Mechanical for the job properties; structural for tenant context and idempotency.

---

## QUEUES.md — Queues standard

**Requires.** Select queues by workload, priority, tenant isolation, operational ownership. **Set explicit connection, queue, timeout, retry, backoff, `tries`, and `maxExceptions`.** Idempotent and observable delivery with correlation IDs. Do not enqueue uncommitted assumptions. Monitor age, throughput, failure rate, retries, dead letters, saturation; document safe replay and discard procedures. The standard explicitly permits a database-backed queue for small installations.

**This repo.**

- `laravel/horizon: ^5.47` is a production dependency and `app/Providers/HorizonServiceProvider.php` is registered — so the operational topology the standard reaches for at SME scale is installed.
- No job sets a queue, connection, backoff, timeout, or `maxExceptions` (see JOBS.md). Only `tries` on one job.
- No correlation IDs on any dispatch or log line.
- `phpunit.xml` sets `QUEUE_CONNECTION=sync` for tests, which is correct in isolation but means no test ever exercises queued execution, retry, or failure.
- **No replay or discard runbook exists.** `docs/runbooks` does not exist. The standard names this as required in every profile.
- No monitoring of queue age, failure rate, or dead letters is documented, despite Horizon being present to provide it.

**Gap.** The infrastructure is installed and the per-job configuration the standard requires is absent, as is the operational documentation it requires in every profile.

**Severity.** Medium. **Class.** Mechanical (job properties, correlation IDs) plus documentation.

---

## API.md — API implementation standard

**Requires.** **Version public contracts.** Document authentication, permissions, tenancy, rate limits, validation, pagination, errors, idempotency, concurrency. **"Return purpose-built resources/read models and RFC 9457-compatible errors."** **"Authorize before protected queries and mutations; redact sensitive fields by policy and context."** Make external callbacks verifiable, replay-safe, deduplicated. Contract-test references from the versioned schema.

**This repo.** 39 API routes in `routes/api.php`, 10 API controllers.

- **No versioning.** Routes are `prefix('products')`, `prefix('orders')`, `prefix('cart')` (`routes/api.php:38-98`). No `/v1`. A breaking change has nowhere to go.
- **No API resources.** `app/Http/Resources/` **does not exist.** All ten API controllers serialize models straight to JSON via `response()->json` — 63 call sites, led by `Api/CollectionController.php` (17) and `Api/ProductController.php` (10). Every private column, including any future one, is a public field by default. This is the "expose private persistence as a cross-module contract" that DDD, MODELS, and API all forbid.
- **No RFC 9457 errors.** Error bodies are ad-hoc `['message' => ...]` JSON.
- **No route names** on any of the 39 routes.
- **Critical — cross-tenant read exposure.** `app/Http/Controllers/Api/ProductController.php:21` opens `Product::query()` with no tenant constraint, then applies user-supplied filters at `:23-42` and paginates. `Product` declares `use IsTenantModel;` at `app/Models/Product.php:22`, so products *are* tenant-owned data. `IsTenantModel` adds no global scope (`app/Traits/IsTenantModel.php`), and tenant scoping is configured only inside the Filament admin panel (`app/Providers/Filament/AdminPanelProvider.php:118`). Any authenticated Sanctum token therefore reads every tenant's product catalogue. The same unscoped pattern recurs across the API controllers.
- The repo does defend one thing well: `Api/ProductController.php:26-27` escapes `LIKE` wildcards with `addcslashes($search, '%_\\')` and documents why. Injection is handled; authorization is not.
- No rate limits are declared on API routes. No idempotency keys. No optimistic concurrency.
- `routes/api.php:37` carries a comment conceding role checks happen inside controller methods because no `role:` middleware alias exists — authorization is per-method and unverifiable at the route table.
- Webhooks: `app/Jobs/SendWebhookDelivery.php` is outbound and signs its payload; inbound `StripeWebhookController.php` (113 lines) exists. No replay protection or deduplication was found on the inbound path.
- No OpenAPI document. `docs/API_COLLECTIONS.md` is prose, not a schema, so nothing can be contract-tested or drift-checked.

**Gap.** The API is an unversioned, unscoped, resource-less projection of the ORM. The tenant-scoping gap is exploitable today.

**Severity.** **Critical.** **Class.** Structural.

---

## VIEWS.md — Views standard

**Requires.** **"Pass typed/purpose-built view models or explicit props; do not depend on ambient database state."** Cover loading, empty, error, unauthorized, offline, validation, and success states. Escape by default, sanitize rich content through a shared service, **localize all user-facing copy**. Keep layout regions, slots, test IDs, semantic landmarks, and extension points stable.

**This repo.** 140 Blade templates.

- No view models exist. Views receive `compact()`-style arrays and raw Eloquent models — `app/Http/Controllers/CheckoutController.php:56-60` passes `['cart' => $cart, 'shippingMethods' => ..., 'isGuest' => ...]` where `$shippingMethods` is a model collection.
- Ambient database state is reached from within views through the lazy accessors on `Product` (`getLowestPrice()`, `getTotalInventory()`) — a query fires during render, per row.
- Localization is partial: **215 `__()` calls across 140 templates**, but no application translation catalog exists to back them (see TRANSLATIONS.md).
- Empty/error/unauthorized state coverage is not systematic; no shared component for them was found in `resources/views/components/`.

**Gap.** Views depend on ambient database state through model accessors, and receive untyped arrays rather than view models.

**Severity.** Medium. **Class.** Structural.

---

## BLADE.md — Blade standard

**Requires.** Components small, explicit, **escaped by default, and independent of ambient database queries**. Layouts, slots, components, translation strings, locale-aware formatting, semantic HTML. `@csrf`, safe URL generation, authorization directives for presentation only with policy checks in the server action. Keep domain mutations in authorized application actions.

**This repo.**

**What is right.**

- **Every form has `@csrf`.** All 28 templates containing `<form` were checked individually; none is missing it.
- **Unescaped output is used five times and every one is defensible.** `resources/views/auth/register.blade.php:50` wraps `__()` with parameter interpolation; `components/input.blade.php:5` and `components/checkbox.blade.php:4` render `$attributes->merge(...)`, which Blade escapes internally; `products/show.blade.php:219` emits JSON-LD hardened with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`; `profile/two-factor-authentication-form.blade.php:42` renders Fortify's own QR SVG. This is careful work.

**What is not.**

- `@php` blocks appear in 12 templates — logic in the template layer.
- Ambient queries during render, via the `Product` accessors — the standard's "independent of ambient database queries" clause. This is the same defect as VIEWS.md and MODELS.md, surfacing here as a render-time cost.
- Translation keys are used but unbacked (TRANSLATIONS.md).

**Gap.** Escaping and CSRF discipline are genuinely good. Ambient queries and inline `@php` logic are the outstanding defects.

**Severity.** Low. **Class.** Mechanical for `@php` extraction; the ambient-query fix belongs to MODELS.md.

---

## THEMES.md — Theme architecture

**Requires.** Themes as independently versioned packages installed to `/themes/{theme-name}` via `liberusoftware/composer-installer`. A manifest contract, parent chains, design tokens, documented cascade-layer order, Filament customization through supported theme hooks rather than vendor edits. Localization and RTL support with logical CSS properties. Accessibility. **"Do not edit an installed `/themes/{theme-name}` copy directly in an application."**

**This repo.**

- **There is no `themes/` directory and no theme package.** `liberusoftware/composer-installer` is not a dependency. The reference implementation at `/home/tom/code/boilerplate-laravel/themes/` has the structure.
- Presentation lives directly in `resources/views/` and `tailwind.config.js` with no token layer, no manifest, no parent chain, and no documented cascade-layer order.
- No RTL support and no logical-property usage was found.
- Filament styling is default; no theme hooks are used, but equally no vendor files are edited — the prohibition is not violated.

**Gap.** The standard's entire subject is absent. Nothing is *violated* so much as unimplemented — but that means there is no seam for multi-brand presentation, which is what the standard exists to provide.

**Severity.** Low for this repository as it stands today; High if multi-brand presentation is on the roadmap. **Class.** Structural.

---

## TRANSLATIONS.md — Translation standard

**Requires.** **Namespaced stable keys such as `modules.billing.invoices.status.paid`; never use mutable English copy as a public key.** Keep user-visible strings out of domain classes and controllers. ICU/Laravel-compatible pluralization with named variables; never concatenate translated fragments. Format dates, numbers, currencies through the shared locale context. CI checks key uniqueness, placeholder parity, missing keys, locale coverage.

**This repo.**

- **`lang/` contains only `lang/vendor/filament-shield/`.** There is no application translation catalog at all — no `lang/en/`, no `lang/en.json`. Every one of the **215 `__()` calls in Blade** passes an English sentence as the key and falls through to itself. The standard's "never use mutable English copy as a public key" is the exact pattern in use.
- **Filament is not translated.** 148 `->label('...')` string literals across `app/Filament/`, against **3** `__()` calls. FILAMENT.md §13 requires "user-facing labels, help and notifications are translated."
- User-visible strings are embedded in controllers, which TRANSLATIONS.md forbids by name — `app/Http/Controllers/CheckoutController.php:45` returns `->with('error', 'Your cart is empty')`, and `app/Models/Order.php:138` (`transitionTo`) is handed untranslated English audit notes from `CheckoutController:324`, `:332`, `:344` (`'Invalid payment information'`, `'Payment failed: ...'`, `'Payment captured'`).
- No locale negotiation, no fallback chain, no RTL support, no locale-aware currency formatting service.
- No CI translation checks.

**Gap.** The application is monolingual with English strings as cache keys, and roughly a third of the user-facing surface (Filament) is not wrapped for translation at all.

**Severity.** Medium. **Class.** Mechanical in volume (extract 215 + 148 strings to namespaced keys), structural in that a locale context service does not exist.

---

## FILAMENT.md — Filament 5 architecture

**Requires.** Resources depend on "domain contracts, queries, DTOs and actions rather than embedding business workflows." **"Mutations call authorized domain actions; they do not bypass invariants with ad hoc model writes."** Queries enforce tenant scope at their authoritative boundary. Actions authorize on the server. Bulk operations bounded, queued when necessary, idempotent, auditable. **"User-facing labels, help and notifications are translated."** Widgets respect panel, user, tenant, timezone, locale, currency context. Resources belong in `module-*-filament` packages installed to `/modules`; the root application owns panels and plugin selection only.

**This repo.** 105 Filament classes, 4,817 lines, across **three parallel namespaces**: `app/Filament/Admin/`, `app/Filament/App/`, and `app/Filament/Resources/`.

- **The packaging model is entirely absent.** No `module-*-filament` package, no `/modules` directory, no plugin classes. Every resource lives in the root application's `App\Filament\` namespace, which the standard's ownership table (§3) assigns to modules and explicitly denies to the root application ("Must not own: reusable module resources or duplicated package UI").
- **Duplicated resources.** `app/Filament/Admin/Resources/Products/ProductResource.php` (271 lines) and `app/Filament/App/Resources/Products/ProductResource.php` (169 lines) are two separate implementations of the same resource for two panels. `app/Filament/Resources/CustomerSegmentResource.php` sits in a third, panel-less namespace.
- **Business logic in actions.**
  - `app/Filament/Admin/Resources/Products/ProductResource.php:188-190` and `:194-210` — inventory adjustment invoked directly against the model (`$record->adjustInventory(...)`) with the below-zero failure surfaced as a Filament notification at `:198`. No domain action, no authorization check, no audit event.
  - `app/Filament/Admin/Resources/Products/ProductResource.php:227` → `:247-269` — `static::export()` builds a CSV, applies stock **classification rules** at `:261` (`In Stock` / `Low Stock` / `Out of Stock`), reads `$record->category->name` per row at `:257` with no eager load, writes to `storage_path('app/public/'.$filename)` at `:266` with a **predictable, non-tenant-scoped filename** (`inventory_report_YYYY-MM-DD.csv`) inside the publicly served disk, then returns a download. The bulk action is unbounded and unqueued.
  - `app/Filament/Admin/Pages/DropxlImport.php` (199 lines) — a supplier import workflow run inside a Filament page: `app(DropxlService::class)` service-locator calls at `:48` and `:67`, provider response shapes destructured in the page at `:51-59` and `:76-79`, and `->action(fn () => $this->importAllProducts())` at `:187` / `importByCategory()` at `:196` performing bulk writes with no authorization, no queue, no idempotency, and no rate limit.
  - `app/Filament/Resources/CustomerSegmentResource.php:125` and `.../Pages/EditCustomerSegment.php:19` — `->action(fn (...) => $record->calculateMembers())` runs an unbounded segment recomputation synchronously in the request.
- **Raw SQL in widgets.** `app/Filament/Admin/Widgets/TopProductsWidget.php:34-35` uses `DB::raw('SUM(order_items.quantity)...')` and `DB::raw('SUM(order_items.price * order_items.quantity)...')`; `app/Filament/Admin/Widgets/CustomerGrowthWidget.php:17-18` likewise. Neither is tenant-scoped, timezone-aware, or currency-aware — `CustomerGrowthWidget` groups by `DATE(created_at)` in database-server time.
- **No authorization anywhere in Filament.** `grep` for `canViewAny`, `canAccess`, or `authorize` across `app/Filament/` returns **zero matches**. Access rests entirely on policy auto-discovery, and **only 17 of 90 models have a policy**. Resources including `Discounts`, `TaxClasses`, `Stores`, `Menus`, `Pages`, `ChatConversations`, and `CustomerSegment` have no corresponding policy file in `app/Policies/`.
- **Labels are not translated** — 148 literals vs 3 `__()` calls.
- `app/Providers/AuthServiceProvider.php:15-17` has an empty `$policies` array and a commented-out `Gate` import, so no explicit registration or default-deny gate exists.
- Only **6 test files reference Filament** against 105 Filament classes.

**Gap.** Filament is not an adapter over a domain here; it is one of the places the domain lives. Combined with zero explicit authorization and partial policy coverage, several admin operations are effectively ungated.

**Severity.** **Critical** (ungated privileged mutations plus the world-readable export path). **Class.** Structural.

---

## LIVEWIRE.md — Livewire 4 architecture

**Requires.** Components accept and normalize presentation inputs, maintain minimal interaction state, **invoke authorized application actions and queries**, and map results into typed view state. They must not implement domain invariants, perform unbounded queries during render, or treat navigation visibility as authorization. Public state minimal, typed, serializable — no privileged DTOs. **Every action validates untrusted state and authorizes server-side.** Reauthorize records after hydration and before mutation. Components belong in `module-*-livewire` packages under a bounded namespace.

**This repo.** 5 components, split across two namespaces:

- `app/Livewire/` — `ChatWidget.php`, `ShoppingCart.php`, `CartCount.php`
- `app/Http/Livewire/` — `InvoicePdf.php`, `CreateTeam.php`

- **The namespace split is itself a violation** of §3's bounded-namespace requirement; `app/Http/Livewire/` is the Livewire 2 convention and should not coexist with `app/Livewire/`.
- No `module-*-livewire` package exists (same absence as FILAMENT.md).
- Livewire 4 is correctly required (`livewire/livewire: ^4.0`).
- The surface is small enough that the exposure is limited, but none of the components delegates to an application action — there are none to delegate to.
- Filament resources are Livewire components at runtime, so every Filament finding above (unauthorized actions, unbounded synchronous work, untranslated labels) is also a Livewire finding under §11 and §13.

**Gap.** Low volume, but the namespace is unbounded and split, and the authorize-then-act discipline §13 requires has no target to call.

**Severity.** Low in isolation; the Filament findings carry the real weight. **Class.** Mechanical for the namespace merge; structural for delegation.

---

## TESTING.md — Liberu testing standard

**Requires.** **Pest 5 declared under `require-dev` with a `^5.0` constraint** as the documentation baseline; "do not require `phpunit/phpunit` separately when Pest supplies the compatible PHPUnit runtime." Suites for Unit, Feature, **Contract, Integration, Architecture, Compatibility, Migration, Security, Performance**, plus `Fixtures/`, `Fakes/`, `Pest.php`. **Architecture tests "blocking `App\` coupling, domain-to-presentation dependencies, provider SDK leakage, and cross-module private-table access."** Security tests for "unauthenticated, wrong tenant/site/team, insufficient role/permission, revoked token/session, hidden field, mass assignment, direct-object reference." **Target 100% line coverage of meaningful owned PHP**; a lower threshold "is a migration state, not a policy," raised deliberately. Stable Composer scripts: `composer test`, `test:unit`, `test:feature`, `test:coverage`, `test:parallel`. **"Avoid assertion-free 'does not crash' tests."**

**This repo.** 199 test files, 2,362 assertions.

- **Pest is not installed.** `composer.json` requires `phpunit/phpunit: ^13.0` directly — the arrangement the standard rules out. The reference implementation requires `pestphp/pest: ^5.0` and `pestphp/pest-plugin-laravel: ^5.0`.
- **Only two suites exist.** `phpunit.xml:8-13` declares `Unit` and `Feature`. `tests/` contains `Unit/` (77 files), `Feature/` (122), `TestCase.php`, `CreatesApplication.php` — and nothing else. **No `Contract/`, `Integration/`, `Architecture/`, `Compatibility/`, `Migration/`, `Security/`, `Performance/`, `Fixtures/`, `Fakes/`, or `Pest.php`.**
- **No architecture tests exist**, so none of the layering violations catalogued in this audit is mechanically prevented from recurring. This is the single highest-leverage gap in the testing section.
- **No composer scripts.** `composer.json` `scripts` contains only Laravel's post-install hooks. Neither `composer test` nor any of the four other named commands exists. The reference implementation defines `"test": "vendor/bin/pest"`.
- **Coverage gates below policy and not framed as a ratchet.** `codecov.yml` sets project `target: auto` with a 0.5% regression threshold and patch `target: 70%`. TESTING.md §13 requires 100% of meaningful owned PHP, with anything lower documented as a migration state moving toward it. Nothing records the intent to raise it.
- **Coverage by area is uneven** (file-count proxies):

  | Area | Classes | Test files referencing it |
  | --- | --- | --- |
  | Filament | 105 | 6 |
  | Policies | 17 | 2 |
  | Jobs | 3 | **0** |
  | Services | 24 | 19 |
  | Models | 90 | 42 |

  The two areas with the least coverage — Filament and policies — are the two carrying the Critical authorization findings.
- **Assertion-free tests exist**, which the standard names directly:
  - `tests/Unit/ExampleTest.php:14` — `$this->assertTrue(true);`
  - `tests/Feature/SupplierFailureNotificationTest.php:31` — `$this->assertTrue(true);`
  - `tests/Unit/ABTestingServiceTest.php:106` — `$this->assertTrue(true);`
- **Skips without owner or expiry**, which §16 requires: `tests/Unit/ABTestingServiceTest.php:127` — `markTestSkipped('No assignment created (session ID mismatch)')` conceals a real defect. `tests/Feature/SocialstreamRegistrationTest.php:54,71,75` skip on configuration.
- **The coverage denominator is wrong for the standard's scope.** `phpunit.xml:15-19` declares `<source><include><directory>app</directory></include></source>` with **no `<exclude>`**. §13 defines the measured scope as "meaningful owned PHP" and excludes "configuration-only files that declare values without behavior" and test support code. With no exclusion list, config-only providers and settings classes sit in the denominator, so the reported percentage is not comparable to the standard's target in either direction.
- Queue behavior is never exercised — `phpunit.xml:31` pins `QUEUE_CONNECTION=sync`.

**Gap.** The suite is substantial in volume and conventional in shape, but it is missing the seven suite types that carry the standard's boundary guarantees, has no architecture enforcement, no Pest, no composer scripts, and a coverage gate roughly 30 points below policy with no ratchet.

**Severity.** High. **Class.** Mechanical for Pest, scripts, and suite scaffolding; structural for architecture, contract, and security suites.

---

## FRONTEND-TESTING.md — JavaScript presentation testing

**Requires.** Unit tests for pure formatters and state machines; component tests asserting accessible roles/names and loading/empty/denied/failure states; contract tests for API paths and RFC 9457 error mapping; browser or device tests for critical authenticated journeys; accessibility tests for keyboard/focus; production build tests. Run formatting, linting, type checking, and the critical browser suite in CI.

**This repo.**

- `package.json` exists with Vite, Tailwind, and PostCSS. **There is no JavaScript test runner, no test script, and no test files.**
- No linting or type checking of frontend assets in any workflow.
- No browser or accessibility tests.
- Partial credit: `.github/workflows/main.yml:100-127` smoke-tests the Docker image by asserting `php artisan --version` runs and that **every asset named in the Vite manifest actually exists in the image**, with a documented explanation at `:88-95` of the two production faults that check was written to catch. That is a genuine production build test in the spirit of the standard's last bullet, and it is the best-reasoned CI step in the repository.

**Gap.** The stack is server-rendered Blade + Livewire, so most of this standard's surface is thin — but critical authenticated journeys (checkout, payment) have no browser evidence at any layer, which THEMES.md §18 and TESTING.md §10 also require.

**Severity.** Low. **Class.** Structural (a browser suite is new infrastructure).

---

## CI.md — Continuous integration and delivery

**Requires.** Every PR and every push to `main` runs the required validation suite. Required gates: **`composer validate`**, locked dependency installation, **formatting**, **static analysis**, **architecture rules**, unit/feature/integration/API-contract/browser tests, OpenAPI validation, frontend lint/type-check/build, dependency and secret scans, coverage with no unreviewed regression. Production release gated on 100% release-scope coverage. **Pin third-party actions to full-length commit SHAs.** Least-privilege permissions. Canonical workflows: `install.yml`, `tests.yml`, `docker.yml`, `deploy-staging.yml`, `release.yml`.

**This repo.** Four workflows: `install.yml`, `main.yml`, `security.yml`, `tests.yml`.

**What is right.**

- `permissions: contents: read` is set at workflow level in `tests.yml:9`, `main.yml:9`, `security.yml:16` — least privilege, as required.
- `security.yml:42` runs `composer audit --locked` and `:81` runs `npm audit --omit=dev --package-lock-only`, on push, PR, and a weekly schedule. Dependency scanning is covered.
- `main.yml` builds the Docker image, **smoke-tests it, and only then pushes** (`:97-137`), with the reasoning documented inline at `:88-95`. The comment in `security.yml:20-25` similarly documents why `php-insights` and `phpcpd` were removed rather than left silently broken. This is unusually honest CI maintenance.

**What is missing.**

- **No formatting check.** Pint never runs (PINT.md).
- **No static analysis.** No PHPStan/Larastan is installed or invoked. The reference implementation ships `/home/tom/code/boilerplate-laravel/phpstan.neon` at a measured level 5 with a documented ratchet policy.
- **No architecture rules.** Nothing exists to run.
- **No `composer validate`.**
- **No OpenAPI validation** (no schema exists).
- **No frontend lint, type check, or test.**
- **Actions are not pinned to SHAs.** `actions/checkout@v4`, `shivammathur/setup-php@v2`, `actions/cache@v4`, `codecov/codecov-action@v5`, `docker/build-push-action@v6` are all floating tags. CI.md requires "full-length commit SHAs."
- **No `release.yml` and no `deploy-staging.yml`.** There is no protected-tag release gate, no production environment protection, no coverage gate on release. `main.yml` pushes `latest` to Docker Hub on every push to `main` (`:118`), which is closer to the continuous-production-deploy the standard prohibits than to the artifact-then-approve model it prescribes.
- Coverage upload sets `fail_ci_if_error: false` (`tests.yml:57`), so a coverage upload failure is silent.
- Two workflows run the test suite redundantly with different runners — `tests.yml:47` uses `php artisan test`, `main.yml:50` uses `./vendor/bin/phpunit`.

**Gap.** Four of the six required gate categories — formatting, static analysis, architecture, `composer validate` — have no implementation, and the release path the standard is built around does not exist.

**Severity.** High. **Class.** Mechanical. Every missing gate is a config file plus a workflow step; the reference implementation supplies most of them.

---

## DOCUMENTATION.md — Liberu documentation standard

**Requires.** A defined structure: README, tutorials, how-to guides, concepts, reference, **ADRs in `/docs/adr`**, **runbooks in `/docs/runbooks`**, `CHANGELOG.md`. Sentence-case headings. **must**/**should**/**may** used precisely. Active voice, present tense for released behavior, planned features marked explicitly. Tested, copy-pasteable examples. Descriptive link text. CI documentation checks.

**This repo.** `README.md` (309 lines) plus 13 files in `docs/` (4,451 lines).

- **No `docs/adr/`.** No architecture decision records exist, so none of the structural choices this audit describes — the legacy skeleton, the three Filament namespaces, the unused module runtime, the missing service layer — has a recorded rationale or supersession path.
- **No `docs/runbooks/`.** QUEUES.md and ADOPTION.md both require operational recovery documentation in every profile.
- **No `CHANGELOG.md`**, no `CONTRIBUTING.md`, no `SECURITY.md`. DOCUMENTATION.md §10 requires linking vulnerability reporting to `SECURITY.md`; there is nowhere to link.
- Filenames are `SCREAMING_SNAKE_CASE` (`WOOCOMMERCE_FEATURES.md`, `STABLE_RELEASE_TASKS.md`) and the set is uncategorized — no tutorial/how-to/concept/reference separation.
- The `docs/` set mixes registers the standard says to keep apart: `IMPLEMENTATION_SUMMARY.md` (612 lines) and `STABLE_RELEASE_TASKS.md` (340 lines) are progress narratives, not reference documentation, and progress narratives go stale silently.
- `docs/SECURITY_REVIEW.md` (142 lines) exists but is a point-in-time review, not the `SECURITY.md` disclosure policy the standard requires.
- No CI documentation checks — no link checking, no example testing.

**Gap.** Documentation volume is high and structure is absent. The two document types the standard treats as load-bearing for operations and architecture — runbooks and ADRs — are the two that do not exist.

**Severity.** Medium. **Class.** Mechanical.

---

## Summary

### Ranked findings

| # | Standard | Finding | Severity | Class |
| --- | --- | --- | --- | --- |
| 1 | API | `Api/ProductController.php:21` queries `Product::query()` unscoped; `Product` is `IsTenantModel` but the trait adds no global scope. Any Sanctum token reads every tenant's catalogue. Pattern repeats across API controllers. | **Critical** | Structural |
| 2 | FILAMENT | Zero `canViewAny`/`canAccess`/`authorize` in 105 Filament classes; only 17 of 90 models have a policy. Privileged mutations (inventory adjust, supplier import, segment recompute) run ungated. | **Critical** | Structural |
| 3 | CONTROLLERS | `CheckoutController.php` (411 lines) owns validation `:117`, persistence `:153`, transaction `:275`, aggregate creation `:279`, order state machine `:324-344`, and mail `:352`. | **Critical** | Structural |
| 4 | CONTROLLERS | `routes/web.php:188-191` registers four routes whose controller methods are commented out at `Frontend/ProductController.php:222-259` — four live 500s. | **Critical** | Mechanical |
| 5 | FILAMENT | `ProductResource.php:247-269` writes `inventory_report_<date>.csv` to the public disk with a predictable, non-tenant-scoped filename; unbounded, unqueued, unauthorized bulk export. | **High** | Structural |
| 6 | API | No versioning, no `app/Http/Resources/`, no RFC 9457 errors; 63 `response()->json` sites serialize Eloquent models directly. | **High** | Structural |
| 7 | PHP / DDD | Zero enums and zero value objects application-wide. Order status is 10 string constants + an adjacency array; money is a float. | **High** | Structural |
| 8 | PHP / PSR | `declare(strict_types=1)` in 2 of 400 `app/` files, 0 of 201 tests, 0 of 161 database files. | **High** | Mechanical |
| 9 | TESTING | No Pest, no `Contract`/`Integration`/`Architecture`/`Security`/`Migration` suites, **no architecture tests**, no composer scripts. Nothing prevents any structural finding here from recurring. | **High** | Mixed |
| 10 | CI | No formatting, no static analysis, no architecture rules, no `composer validate`; unpinned actions; no `release.yml`; `latest` pushed to Docker Hub on every `main`. | **High** | Mechanical |
| 11 | LARAVEL | Laravel 13.23 running a Laravel 10 skeleton — legacy `bootstrap/app.php`, three Kernel/Handler classes, `config/app.php` providers array, no `bootstrap/providers.php`. | **High** | Structural |
| 12 | LARAVEL | `TeamServiceProvider` registered at `config/app.php:176` binds 12 nonexistent genealogy classes (`FamilyTree365\LaravelGedcom\*` → `App\Models\Person`); neither side exists. | **High** | Mechanical |
| 13 | SERVICES | 24 `NounService` classes, 4 with constructors, 22 of 98 methods untyped, zero authorization, external calls unadaptered (`ShippingService.php:111`). Three duplicated responsibilities. | **High** | Structural |
| 14 | MODELS | `Product.php:242` sends mail; `:395` owns a two-table transactional invariant; `:336-364` fire per-call aggregate queries (N+1 by design). | **High** | Structural |
| 15 | CLASSES / OOP | No layer separation anywhere; static factories instead of container bindings; one framework-free class in `app/`. | **High** | Structural |
| 16 | DDD | No bounded contexts, no actions, no read models, no domain events. `app/Modules/` is an unused runtime for zero modules. | **High** | Structural |
| 17 | DATABASE | `DatabaseSeeder.php:24-31` runs `DummyDataSeeder` in the baseline chain; 7 of 12 seeders non-idempotent; duplicate permission seeders. | **High** | Mechanical |
| 18 | PHP / PSR | `ExternalModuleLoader.php:35,47,86,118` scans directories and `require_once`s by path — a hand-rolled autoloader, forbidden by name in three standards. | **Medium** | Structural |
| 19 | TRANSLATIONS | No application `lang/` catalog; 215 English sentences used as keys; Filament 148 literals vs 3 `__()`. | **Medium** | Mechanical |
| 20 | CONTRACTS | Interfaces well-chosen but unbound, untested, and undocumented; no `tests/Contract/`; provider array shapes leak as contracts. | **Medium** | Structural |
| 21 | PINT | No `pint.json` (reference implementation has one); Pint never runs in CI; no lint script. | **Medium** | Mechanical |
| 22 | DOCUMENTATION | No `docs/adr/`, no `docs/runbooks/`, no `CHANGELOG.md`/`CONTRIBUTING.md`/`SECURITY.md`; 4,451 lines of uncategorized docs. | **Medium** | Mechanical |
| 23 | JOBS / QUEUES | No `$backoff`, `$timeout`, `maxExceptions`, queue assignment, uniqueness, correlation IDs, or tenant context; zero job tests; no replay runbook. | **Medium** | Mixed |
| 24 | GUIDELINES | `error_log` committed at repo root, leaking the deployment path `/home/liberu/projects/ecommerce-laravel`. | **Medium** | Mechanical |
| 25 | VIEWS | No view models; views depend on ambient DB state through model accessors. | **Medium** | Structural |
| 26 | PHP / DATABASE | `app/database/seeders/MenuSeeder.php` — 15 lines, no `<?php`, no class, `// ... existing code ...` placeholders; invisible to `php -l`. | **Medium** | Mechanical |
| 27 | ADOPTION | No backup/restore procedure, no retention policy, no recorded operating profile; `"minimum-stability": "beta"`. | **Medium** | Mixed |
| 28 | TESTING | Assertion-free tests at `ExampleTest.php:14`, `SupplierFailureNotificationTest.php:31`, `ABTestingServiceTest.php:106`; ownerless skip at `ABTestingServiceTest.php:127`. | **Low** | Mechanical |
| 29 | LIVEWIRE | Components split across `app/Livewire/` and `app/Http/Livewire/`; unbounded namespace. | **Low** | Mechanical |
| 30 | THEMES | No `themes/`, no theme package, no design tokens, no RTL. | **Low** | Structural |
| 31 | CONCERNS | `IsTenantModel` is compliant as a trait but names a guarantee it does not enforce. | **Low** | Mechanical |
| 32 | BLADE | `@php` logic in 12 templates; ambient queries at render. | **Low** | Mechanical |
| 33 | CONTRIBUTING | No `CONTRIBUTING.md`, `SECURITY.md`, or PR template in the repository. | **Low** | Mechanical |
| 34 | FRONTEND-TESTING | No JS test runner, lint, or type check; no browser evidence for checkout. | **Low** | Structural |

### By standard

| Standard | Severity | Dominant class |
| --- | --- | --- |
| API | Critical | Structural |
| FILAMENT | Critical | Structural |
| CONTROLLERS | Critical | Structural |
| PHP | High | Mixed |
| LARAVEL | High | Structural |
| SERVICES | High | Structural |
| MODELS | High | Structural |
| CLASSES | High | Structural |
| OBJECT-ORIENTED-PROGRAMMING | High | Structural |
| DOMAIN-DRIVEN-DESIGN-PATTERNS | High | Structural |
| TESTING | High | Mixed |
| CI | High | Mechanical |
| PSR | Medium | Mixed |
| PINT | Medium | Mechanical |
| GUIDELINES | Medium | Mechanical |
| ADOPTION | Medium | Mixed |
| DATABASE | Medium (seeders High) | Mechanical |
| CONTRACTS | Medium | Structural |
| JOBS | Medium | Mixed |
| QUEUES | Medium | Mechanical |
| VIEWS | Medium | Structural |
| TRANSLATIONS | Medium | Mechanical |
| DOCUMENTATION | Medium | Mechanical |
| BLADE | Low | Mechanical |
| LIVEWIRE | Low | Mechanical |
| THEMES | Low | Structural |
| CONCERNS | Low | Mechanical |
| CONTRIBUTING | Low | Mechanical |
| FRONTEND-TESTING | Low | Structural |

### What the repository does well

Recording this matters, because an audit that reports only failures misprices the remediation.

- **Mass assignment** — 92 models declare `$fillable`; **zero** declare `$guarded = []`.
- **Money at rest** — `decimal(10,2)` everywhere; nothing money-shaped is a float column. The problem is the in-PHP type, not the schema.
- **Migrations** — 122 migrations, 176 foreign keys, 115 indexes, `down()` on all 122.
- **CSRF** — all 28 templates containing a form have `@csrf`; no exceptions.
- **Output escaping** — only five `{!! !!}` sites, every one justified (JSON-LD hardened with all four `JSON_HEX_*` flags, Blade-escaped attribute bags, Fortify's own SVG).
- **SQL injection** — `Api/ProductController.php:26` escapes `LIKE` wildcards with `addcslashes` and documents why.
- **Jobs** — all three pass identifiers rather than models, bound their attempts, handle missing records, and `DispatchDropshippingOrder::failed()` implements a real compensating action.
- **Abstraction restraint** — interfaces exist at the two genuine variation points (payment gateways, carriers) and nowhere speculatively, which is what CONTRACTS.md and OOP.md actually ask for.
- **CI honesty** — `main.yml` smoke-tests the Docker image before pushing it and documents the two production faults that check was written to catch; `security.yml` documents why two broken jobs were removed rather than leaving them red.
- **Documented deviations** — `phpunit.xml:29-36` explains, in place, why `SOCIALSTREAM_PROVIDERS` is enumerated for tests but empty by default in production. GUIDELINES.md asks for exactly this rather than implicit behavior.

### Two observations for whoever writes the migration plan

**The mechanical findings are cheap and mostly pre-solved.** `pint.json`, `phpstan.neon`, Pest, and `bootstrap/providers.php` all exist in `/home/tom/code/boilerplate-laravel` and can be lifted. Findings 4, 8, 12, 17, 21, 24, 26, 28, 29, 31, 32, and 33 are deletions, config files, or codemods.

**The structural findings share one root.** Findings 1, 2, 3, 5, 6, 13, 14, 15, and 16 are all the same absence viewed from different layers: there is no application action layer, so every caller — controller, Filament action, Livewire component, job — reaches for the model directly. That is also why the authorization gaps are systemic rather than incidental: there is no single place where an authorization check would cover more than one caller. Finding 9 (no architecture tests) is what allowed all of it to accumulate silently, and is the cheapest thing to add that stops it growing.
