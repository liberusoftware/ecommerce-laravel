# Standards gap audit: `ecommerce-laravel` vs the Liberu standards documents

Resolves [#927](https://github.com/liberusoftware/ecommerce-laravel/issues/927) (part of #925).

**Sources.** The 36 documents in [`liberusoftware/documentation/standards/`](https://github.com/liberusoftware/documentation/tree/main/standards),
read at their current `main` revision. `REACT.md`, `VUE.md`, `NUXT.md`, `FLUTTER.md`, `REACT-NATIVE.md`,
`INERTIA.md` and `MOBILE.md` are out of scope per the issue; this repo ships no JavaScript SPA layer, so
they have no surface here anyway. Evidence is from this repository at commit `2d1024c`, cross-checked
against `liberusoftware/boilerplate-laravel` where the standard is ambiguous — the documentation names it
the reference implementation.

**Method.** Every claim below cites a file and line in this repository or a counted grep over it. Where a
number is given it was measured, not estimated. No fixes are proposed: this is an audit, the migration
plan is decided elsewhere (#925).

**Severity scale.**

| Severity | Meaning |
| --- | --- |
| **Critical** | The standard's central rule is inverted, or the gap is a security/correctness exposure. |
| **High** | The standard is substantially unmet across most of the surface it governs. |
| **Medium** | Partially met; the gap is real but localised or has a mechanical fix. |
| **Low** | Mostly met; isolated deviations. |

**Gap class.** *Mechanical* = a lint rule, a config file, a rename, a codemod. *Structural* = the code is
shaped wrong; fixing it means moving behaviour between layers.

---

## Repository baseline

Measured over the working tree:

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
| Application-owned translation catalogues in `lang/` | **0** (only `lang/vendor/filament-shield/`) |
| `pint.json` | **absent** |
| Static-analysis tool in `composer.json` | **absent** |
| Composer `test` / `test:coverage` scripts | **absent** |

`app/` file counts by directory: `Filament/` 105, `Models/` 99, `Http/` 61, `Services/` 28,
`Actions/` 22, `Policies/` 17, `Providers/` 12, `Modules/` 11, `Console/` 10, `Notifications/` 9,
`Listeners/` 5, `Livewire/` 3, `Jobs/` 3, `Interfaces/` 3, `Exceptions/` 3, everything else 1–2.

The shape of that table is itself the headline finding: 105 Filament files and 99 models against 28
services, 3 jobs and 3 interfaces. The standards assume a domain layer sits between persistence and
presentation. Here there is essentially none, so Filament resources and models absorb the work the
standards assign to services, actions and contracts.

---

## PHP.md — PHP 8.5 language standard

**Requires.** `declare(strict_types=1);` in new executable PHP files. Typed properties, parameters and
return values; constrained values represented "with enums or value objects". Immutable DTOs and
`readonly` state where mutation is not required. Composer autoloading, "never scan directories to invent
an autoloader". Secrets kept out of source.

**This repo.**

- `declare(strict_types=1);` appears in **2 of 400** files under `app/`, **0 of 201** under `tests/`,
  **0 of 161** under `database/`. The two are `app/Models/Role.php:3` and `app/Models/Permission.php:3`.
- **Zero enums exist in the entire application.** Order status, payment status, shipment status,
  product status and every other constrained value is a bare string or int column read straight off the
  model. The standard names enums as the required representation for constrained values.
- `app/database/seeders/MenuSeeder.php` is a 15-line file with **no `<?php` tag, no `namespace`, and no
  class declaration** — it is a bare method body starting at line 1 with `public function run()`,
  containing `// ... existing code ...` placeholder comments at lines 4 and 10. It sits inside the
  PSR-4 root `app/` that `composer.json` maps to `App\`. This is not loadable PHP.
- **A hand-rolled autoloader**, which the standard forbids by name ("never scan directories to invent
  an autoloader"): `app/Modules/Support/ExternalModuleLoader.php:86` and `:118` both
  `require_once` a file resolved from a filesystem walk, as do `app/Modules/ModuleManager.php:216` and
  `:245`. `architecture/MODULES.md:193` forbids the same thing, and PSR.md's package-boundary rules
  require "no runtime filesystem scanning" (see #926 — the whole subtree is unused).

**Gap.** The language baseline the standard defines is essentially absent. Strict types are not a
convention here; they are two accidents.

**Severity: Critical.** **Class: mechanical for `strict_types` (a Rector rule over 762 files, though it
will surface a long tail of real type coercion bugs); structural for enums and value objects** — adding an
`OrderStatus` enum means finding every string comparison against `'pending'` scattered through Filament,
Blade and models.

---

## PSR.md — PHP-FIG interoperability and PSR-12 baseline

**Requires.** PSR-12 mandatory for "PHP source, tests, migrations, configuration classes, and package
examples". `declare(strict_types=1);` in new executable files. Four spaces, no tabs, no trailing
whitespace, soft 120-character line limit, one class per file. PSR-4 autoloading with "no runtime
filesystem scanning". PSR-3 `LoggerInterface`, PSR-18 `ClientInterface`, PSR-20 `ClockInterface` at the
boundaries where they apply — PSR-20 is called out specifically for "expiry, retries, invitations,
settings, and security".

**This repo.**

- Tabs: 0 files. Closing `?>` tags: 0. CRLF: 0. Those parts hold.
- Trailing whitespace: **45 files** across `app/`, `tests/`, `database/`, `config/`, `routes/`.
- Lines over 120 characters: **67 lines across 38 files** in `app/` alone.
- `strict_types`: see PHP.md above — 2/400.
- PSR-4: `app/database/seeders/MenuSeeder.php` breaks the `App\` PSR-4 map (lowercase path segments,
  no namespace, no class). `app/Http/Livewire/` and `app/Livewire/` coexist as two namespaces for the
  same concern.
- Runtime filesystem scanning and `require_once`-by-path:
  `app/Modules/Support/ExternalModuleLoader.php:86,118`, `app/Modules/ModuleManager.php:216,245` (#926).
- PSR-20 `ClockInterface`: **0 occurrences**. Every expiry, retry and scheduling decision reads the wall
  clock directly, which is also why time-dependent behaviour cannot be tested deterministically
  (TESTING.md §7 "Freeze time and seed randomness when outcomes depend on them").

**Gap.** The whitespace half of PSR-12 is broadly satisfied by accident; the declarative half
(`strict_types`, one-class-per-file, PSR-4 integrity) is not. The PSR interop table is unused.

**Severity: High.** **Class: mechanical** for whitespace/line length/PSR-4 (Pint plus one file deletion);
**structural** for PSR-20 adoption, which requires injecting a clock into every service that currently
calls `now()`.

---

## PINT.md — formatting as a required quality gate

**Requires.** Pint pinned in Composer dev dependencies (satisfied: `laravel/pint: ^1.13`). A `pint.json`
"at the repository root, committed with the lock file", at "the strictest supported preset/configuration
for the repository". Pint run "before committing **and in CI**". "A pull request fails when formatting
changes are required." Reviewers must treat "unexplained formatter exclusions" as defects.

**This repo.**

- **There is no `pint.json`.** The reference implementation has one:
  `/home/tom/code/boilerplate-laravel/pint.json` declares `"preset": "laravel"` plus three explicit rule
  overrides. This repo has no configuration at all, so Pint would silently run the default preset — the
  standard's "maximum strictness" requirement has no expression here.
- **Pint never runs in CI.** `.github/workflows/tests.yml` has no Pint step; nor does
  `.github/workflows/main.yml`, `install.yml` or `security.yml`. The only PHP steps are
  `composer install`, `key:generate` and `php artisan test`.
- `composer.json:58-69` defines no `format`, `lint` or `pint` script, so there is no repository-blessed
  command either. The boilerplate at least defines `"test": "vendor/bin/pest"`.
- Consequence, measurable: 45 files carry trailing whitespace and 38 carry over-long lines. Nothing
  stops them.

**Gap.** The standard's one hard gate — "a pull request fails when formatting changes are required" — is
entirely absent, and the configuration file the standard requires does not exist.

**Severity: High.** **Class: mechanical.** One `pint.json`, one CI step, one bulk-format commit.

---

## LARAVEL.md — Laravel 13 application conventions

**Requires.** "Keep domain rules in modules and application services; controllers, Livewire components,
Filament resources, and Inertia pages orchestrate them." Route model binding, form requests, policies,
middleware used deliberately. "Resolve team/tenant context before protected queries and mutations; UI
route guards are not authorization." Query objects/read models for complex reads; transactions for local
invariant changes. "Dispatch events after commit, make retryable jobs idempotent, and use queues for work
that exceeds the request budget."

**This repo.** See the CONTROLLERS, FILAMENT, MODELS and JOBS sections below for the detailed evidence;
in summary the first sentence of the standard is inverted. Domain rules live in Filament resources,
controllers and models. There is no module layer at runtime — `app/Modules/` is 1,095 lines of unused
scaffolding (#926) and modules ship no `extra.laravel.providers` (#928), so the "modules and application
services" the standard points at do not exist as a place to put anything.

Version baseline is correct: `composer.json:11` `"php": "^8.5"`, `:19` `"laravel/framework": "^13"`,
`:26` `"livewire/livewire": "^4.0"`, `:15` `"filament/filament": "^5.0"`.

Two structural findings specific to this standard, beyond the layering:

- **The application is Laravel 13 running a Laravel 10 skeleton.** `bootstrap/app.php` is the pre-11
  style — it manually constructs `new Illuminate\Foundation\Application(...)` and binds the three
  kernels — and all three legacy classes still exist: `app/Http/Kernel.php`, `app/Console/Kernel.php`,
  `app/Exceptions/Handler.php`. There is **no `bootstrap/providers.php`**; providers are listed in
  `config/app.php:158-176` via the deprecated `'providers' => ServiceProvider::defaultProviders()->merge([...])`
  array. "Application composition in the root application" is therefore expressed in a shape the current
  framework no longer uses, and every upgrade guide's instructions do not apply to this repository.
- **A registered provider binds classes that do not exist.** `config/app.php:176` registers
  `App\Providers\TeamServiceProvider::class`, whose `boot()` (`app/Providers/TeamServiceProvider.php:25-35`)
  makes 11 `bind()` calls mapping `FamilyTree365\LaravelGedcom\*` models onto `App\Models\*`
  equivalents. Neither side exists in this application — it is genealogy-package wiring inherited from a
  sibling Liberu product, resolved on every boot.

**Gap.** Correct framework versions, a two-major-versions-stale skeleton, and inverted layering.

**Severity: Critical.** **Class: structural.**

---

## CI.md — continuous integration and delivery

**Requires.** `tests.yml` must run "formatting, static analysis, architecture checks, unit and feature
tests, API/OpenAPI checks, security checks, and coverage". Required gates must cover "`composer validate`,
locked dependency installation, formatting, static analysis, and architecture rules". Third-party actions
"pinned to full-length commit SHAs". A `release.yml` gated on a protected `v*.*.*` tag with production
environment approval. The production gate requires 100% line coverage of release scope.

**This repo.** Four workflows: `install.yml`, `main.yml`, `security.yml`, `tests.yml`.

| Required gate | Present? | Evidence |
| --- | --- | --- |
| `composer validate` | No | absent from all four workflows |
| Locked install | Partial | `composer install` used, but no `--no-dev`/lock verification step |
| Formatting (Pint) | **No** | no Pint step anywhere |
| Static analysis | **No** | no PHPStan/Larastan/Psalm in `composer.json` at all |
| Architecture checks | **No** | no `tests/Architecture/` suite, no Pest arch plugin |
| Unit + feature tests | Yes | `tests.yml` `php artisan test --coverage-clover=coverage.xml` |
| API/OpenAPI checks | **No** | no OpenAPI schema exists in the repo |
| Security checks | Partial | `security.yml` exists |
| Coverage | Partial | uploaded to Codecov; see below |
| Actions pinned to SHA | **No** | `tests.yml` uses `actions/checkout@v4`, `shivammathur/setup-php@v2`, `actions/cache@v4`, `codecov/codecov-action@v5` — all floating tags |
| `release.yml` | **No** | does not exist; no tag-triggered workflow, no `production` environment |

Coverage: `codecov.yml` sets `project.target: auto / threshold: 0.5%` and `patch.target: 70%`. The
standard's release gate is 100% of release scope and states a sub-100% threshold "is a migration state,
not a policy" — that is defensible as a migration state, but there is no recorded intent to raise it.
Separately `tests.yml` sets `fail_ci_if_error: false` on the Codecov upload, so a failed upload is silent.

`main.yml` runs `./vendor/bin/phpunit --no-coverage` while `tests.yml` runs `php artisan test` — two
different invocations of the same suite in two workflows.

One thing this repo does *better* than the standard's minimum: `main.yml:92-138` builds the Docker image,
smoke-tests it (`artisan --version` plus a vite-manifest asset check) and only then pushes. That is real
deployment verification and the commentary explains two production faults it caught.

**Gap.** Three of the standard's named required gates (formatting, static analysis, architecture) do not
exist. There is no release workflow, so there is no path by which the production gate could ever run.

**Severity: High.** **Class: mechanical** — every missing gate is a workflow step plus, for static
analysis and architecture tests, a dev dependency. The findings those gates then report will be
structural.

---

## TESTING.md — test ownership, suites, coverage

**Requires.** Pest 5 is "the documentation baseline"; new modules/applications declare `pestphp/pest ^5.0`
under `require-dev`, and an existing PHPUnit suite "follows the same suites, isolation rules, and
coverage policy until it upgrades". Layout with `Unit/`, `Feature/`, `Contract/`, `Integration/`,
`Architecture/`, `Compatibility/`, `Migration/`, `Security/`, `Performance/`, `Fixtures/`, `Fakes/`,
`Pest.php` — "create only suites the repository needs", but §8 then *requires* architecture tests,
migration/upgrade tests, security tests and contract tests. Stable Composer scripts: `composer test`,
`test:unit`, `test:feature`, `test:coverage`, `test:parallel`. Freeze time and seed randomness. 100% line
coverage of meaningful owned PHP as the target.

**This repo.**

- 201 test files, 1,209 test methods, 1,949 assertion calls. The suite is not thin — this is the
  healthiest area of the repository.
- Suites present: `tests/Unit/`, `tests/Feature/` (with `Api/`, `Auth/`, `Filament/`, `Frontend/`
  subdirectories). `phpunit.xml:7-14` registers exactly those two.
- **Missing suites the standard names as required in §8:** `Architecture/`, `Contract/`, `Security/`,
  `Migration/`. Architecture tests are the ones that would have caught most of the other findings in
  this document — §8 asks specifically for "architecture tests blocking `App\` coupling,
  domain-to-presentation dependencies, provider SDK leakage, and cross-module private-table access".
- **Pest is not installed.** `composer.json:42` requires `phpunit/phpunit: ^13.0` directly. Under §3
  that is tolerated for an existing suite, but the reference implementation
  (`/home/tom/code/boilerplate-laravel/composer.json`) has `pestphp/pest ^5.0` and
  `pestphp/pest-plugin-laravel ^5.0`, so this repo is off the documented baseline.
- **No Composer test scripts at all.** `composer.json:58-69` has only Laravel's four lifecycle hooks.
  The standard requires `composer test` and friends; the boilerplate defines `"test": "vendor/bin/pest"`.
  Contributors must know `php artisan test` from context, and the two CI workflows disagree about which
  command is canonical.
- `phpunit.xml:15-18` declares `<source><include><directory>app</directory>` with **no `<exclude>`**, so
  the coverage denominator includes config-only providers and non-executable files that §13 explicitly
  excludes from "meaningful owned PHP". The reported percentage is therefore not comparable to the
  standard's scope.
- **Vacuous and conditionally-skipped tests.** §6 rules out "assertion-free 'does not crash' tests
  unless survival itself is the documented contract"; two tests assert only `$this->assertTrue(true);`
  — `tests/Unit/ABTestingServiceTest.php:106` ("// No exception thrown") and
  `tests/Feature/SupplierFailureNotificationTest.php:31`. §16 requires a quarantined test to carry an
  owner, linked issue and removal date; `tests/Unit/ABTestingServiceTest.php:127`
  `$this->markTestSkipped('No assignment created (session ID mismatch)');` sits inside an `if`/`else`, so
  the test passes green whether or not the behaviour it names occurred.
- `phpunit.xml:29-36` carries a well-documented `SOCIALSTREAM_PROVIDERS` env block explaining a real
  production decision. That is exactly the kind of documented deviation the standards ask for.

**Gap.** Good volume, wrong shape: the four suites that prove boundaries rather than behaviour are
absent, and the repository has no stable command interface.

**Severity: Medium.** **Class: mechanical** for the Composer scripts, the coverage `<exclude>` list and
scaffolding the suite directories; **structural** for what an architecture suite would then fail on.

---

## DOCUMENTATION.md — documentation ownership and structure

**Requires.** Repository layout: `README.md`, `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md`,
`LICENSE.md`, and `docs/` containing `index.md`, `getting-started.md`, `configuration.md`,
`architecture.md`, `concepts/`, `guides/`, `reference/`, `adr/`, `runbooks/`, `upgrades/`,
`troubleshooting.md`. ADRs are the source of truth for architectural decisions and live in `/docs/adr`;
runbooks in `/docs/runbooks`. Sentence-case headings. "Do not claim support, coverage, security, or
deployment capability without current evidence" (CONTRIBUTING.md, same rule).

**This repo.**

- Root: only `README.md`. **No `CHANGELOG.md`, no `CONTRIBUTING.md`, no `SECURITY.md`, no `LICENSE.md`**
  — despite `README.md:9` displaying an MIT licence badge and `composer.json:8` declaring
  `"license": "MIT"`.
- `docs/` contains 13 flat files, all SCREAMING_SNAKE_CASE: `ARCHITECTURE_DIAGRAM.md`,
  `WOOCOMMERCE_FEATURES.md`, `SYSTEM_ARCHITECTURE.md`, `CHAT_SYSTEM.md`, `ANALYTICS_GUIDE.md`,
  `ANALYTICS.md`, `SHOPIFY_MAGENTO_FEATURES.md`, `API_COLLECTIONS.md`, `MODULAR_ARCHITECTURE.md`,
  `QUICK_REFERENCE.md`, `SECURITY_REVIEW.md`, `STABLE_RELEASE_TASKS.md`, `IMPLEMENTATION_SUMMARY.md`.
- **No `docs/index.md`**, so nothing indexes those 13 files. **No `docs/adr/`** — every architectural
  decision in this repository is undocumented. **No `docs/runbooks/`**, **no `docs/upgrades/`**.
- Duplicated sources of truth, which §2.3 forbids: `ARCHITECTURE_DIAGRAM.md`, `SYSTEM_ARCHITECTURE.md`
  and `MODULAR_ARCHITECTURE.md` all describe the architecture; `ANALYTICS.md` and `ANALYTICS_GUIDE.md`
  both describe analytics.
- Unevidenced claims in `README.md`: line 34 calls the platform "modular, production-ready", line 36 says
  "The codebase follows Laravel best practices". `MODULAR_ARCHITECTURE.md` documents a module system that
  #926 established is 1,095 lines of dead scaffolding. The Codecov badge at `README.md:28` links to a
  coverage report whose gate is 70% on patch, not the standard's release scope.

**Gap.** Five required root documents missing, no index, no ADRs, no runbooks, three overlapping
architecture documents, and README claims that the code does not support.

**Severity: High.** **Class: mechanical** to create the missing files; **structural** in that the ADR
absence means the migration plan (#925) has nowhere canonical to land, and the README claims cannot be
made true by editing the README.

---

## TRANSLATIONS.md — localisation

**Requires.** "Use namespaced stable keys such as `modules.billing.invoices.status.paid`; never use
mutable English copy as a public key." "Keep user-visible strings out of domain classes and controllers;
pass translation keys and interpolation data to the presentation boundary." Format dates, numbers and
currencies through a shared locale context. Support RTL with logical CSS properties. CI checks key
uniqueness, placeholder parity, missing keys and locale coverage.

**This repo.**

- `lang/` contains **one directory: `lang/vendor/filament-shield/`**, which is a published vendor
  catalogue covering ~20 locales for a third-party plugin. There is **no `lang/en/`, and no
  application-owned catalogue of any kind**.
- Consequently every user-facing string in 140 Blade templates, 105 Filament files and the notification
  and mail classes is hardcoded English literal. There are no keys, so the "never use mutable English
  copy as a public key" rule is not so much violated as unreachable.
- No locale negotiation, no RTL handling, no shared currency/date formatting service.
- No CI check for any of the above, because there is no catalogue to check.

**Gap.** The standard is unimplemented end to end. The repository ships translations for a vendor
plugin's admin labels and nothing for its own product.

**Severity: High.** **Class: structural.** Extracting strings is mechanical per-string, but deciding the
key namespace, the locale context owner and the currency/date formatting boundary is a design decision
the standard assigns to a module that does not exist here.

---

## FRONTEND-TESTING.md — JavaScript presentation testing

**Requires.** Applies to "React, Vue, Nuxt, Inertia, React Native, Expo, and Vite presentation
packages". Unit tests for formatters and state machines, component tests, contract tests, browser tests
for critical authenticated journeys, accessibility tests, production build tests.

**This repo.** There is no JavaScript presentation package — the front end is Blade plus Livewire plus
Alpine, with Vite only as an asset bundler (`vite.config.js`, `package.json`). Most of this standard has
no surface here and that is correct, not a gap.

Two clauses do apply and are unmet:

- **Production build tests**: `main.yml:88-118` verifies the vite manifest's assets exist in the Docker
  image, which is genuine build verification, but `install.yml:88-91` is the only place `npm run build`
  runs on a PR and nothing asserts on its output there.
- **Browser tests for critical authenticated journeys**: none exist. Checkout — the highest-risk journey
  in an e-commerce application — has no browser-level evidence. `tests/Feature/Frontend/` covers it at
  the HTTP layer only.

**Gap.** Narrow, and mostly out of scope by construction.

**Severity: Low.** **Class: mechanical.**

---

## ADOPTION.md — progressive delivery

**Requires.** Every installation, including personal: "supported PHP 8.5/Laravel 13 baseline, locked
dependencies, strict server-side validation, authorization policies, secure secret handling, HTTPS in
production, and documented updates". "Keep modules independently installable and use their public
contracts; do not copy domain logic into an application or presentation adapter." Own migrations,
retention, export, deletion, backups and recovery in the module that owns the data. Document what a
simpler profile does not provide. Personal profile evidence: "successful fresh install, update, backup
restore, export, authentication/authorization checks, and documented limits".

**This repo.**

- PHP 8.5 / Laravel 13 baseline: met (`composer.json:11,19`).
- Locked dependencies: `composer.lock` is committed, but `composer.json:90` sets
  `"minimum-stability": "beta"`, which admits pre-release dependencies into a repository the README
  calls "production-ready".
- "Do not copy domain logic into an application or presentation adapter": violated throughout — see the
  FILAMENT and CONTROLLERS sections.
- Fresh install evidence: `install.yml` genuinely proves migrate + seed + `npm ci` + build against MySQL
  8.0. That is the personal-profile fresh-install evidence the standard asks for, and it is real.
- **No backup/restore procedure, no export procedure, no documented update path, no documented limits**
  anywhere in `docs/` or `README.md`. `k8s/` and `docker-compose.yml` exist but no `docs/runbooks/`.
- No profile is declared. The standard asks each release to "record the chosen profile".

**Gap.** Install is proven; operate and recover are not documented at all.

**Severity: Medium.** **Class: mechanical** (documentation and one restore rehearsal), except the
domain-logic-in-adapters clause which is structural and already counted under FILAMENT.

---

## CONTRIBUTING.md — contribution workflow and quality gates

**Requires.** "Follow PSR-12 and PSR-4, Laravel conventions, strict typing where compatible, typed
signatures, dependency injection, small actions, and explicit contracts." "Make writes transactional,
queued work idempotent, events observable, and migrations reversible or accompanied by a documented
recovery path." Run "formatting/linting, tests, static analysis, architecture checks, and security
checks appropriate to the change". A PR "must identify changed public contracts and include tests or
explain why tests are not applicable".

**This repo.**

- **There is no `CONTRIBUTING.md` in the repository**, and no `.github/PULL_REQUEST_TEMPLATE.md`, so none
  of the above is communicated to a contributor.
- Strict typing: 2/400 (see PHP.md). Explicit contracts: 3 interfaces (see CONTRACTS.md). Static analysis
  and architecture checks: not installed (see CI.md).
- The rules the standard states are therefore unenforced by tooling *and* unstated in the repository.

**Gap.** Complete.

**Severity: Medium.** **Class: mechanical** for the file itself; the gates it would describe are covered
under CI.md and PINT.md.

---

## GUIDELINES.md — daily coding, review, naming, security

**Requires.** "Keep dependencies pointed inward toward stable contracts; domain code must not depend on
UI frameworks." "Use strict types, explicit names, constructor injection, immutable value objects where
useful, and small focused classes." "Validate and authorize at the server boundary." "Make retries,
idempotency, transactions, tenancy, audit events, and failure recovery explicit for mutations." "Prefer
semantic HTML, keyboard access, visible focus, localization, RTL support, reduced motion, and WCAG 2.2 AA
behavior." Naming: "singular class names, plural collection names, stable kebab-case package/route
identifiers"; "keep framework-specific code at the edge".

**This repo.** This standard is the umbrella over most of the others, and it fails on the same evidence:
strict types 2/400, no immutable value objects, no enums, zero localisation, no RTL, domain logic inside
Filament and controllers rather than at the edge. Two additional naming findings specific to this
document:

- `app/Http/Livewire/` and `app/Livewire/` both exist — the same concept under two names, which breaks
  "explicit names" and the one-home rule.
- `app/database/seeders/` uses lowercase path segments inside a StudlyCase PSR-4 root.
- **A production log file is committed at the repository root.** `error_log` (2,637 bytes) contains PHP
  fatal errors from July 2025 and leaks the deployment path in every line:
  `/home/liberu/projects/ecommerce-laravel/app/Filament/Admin/Resources/CategoryResource.php on line 16`.
  The standard's rule is "do not commit secrets, private data, generated credentials, unexplained
  snapshots"; an infrastructure path in a public repository is exactly the internal identifier
  DOCUMENTATION.md §10 asks to be redacted. It also references a resource path that no longer exists.

**Gap.** Aggregate of the above.

**Severity: High.** **Class: structural.**

---

## CONTROLLERS.md — thin HTTP adapters

**Requires.** "Controllers are thin HTTP adapters. They translate a request into an authorized
application action and a response; they do not contain domain workflows." One controller method per
route/use case with "explicit route names and middleware". "Validate through form requests or dedicated
validators, authorize through policies/gates." "Delegate writes to actions/services and reads to
purpose-built queries/read models." "Avoid database queries, provider SDK calls, hidden side effects,
and cross-module orchestration in controllers." "Keep request handling short enough to review in one
pass."

**This repo.** 39 controllers, 3,744 lines.

*Domain workflows in controllers.* `app/Http/Controllers/CheckoutController.php:115-373` —
`processCheckout()` is 259 lines containing the entire order workflow: inventory verification
(`:152-157`), subtotal (`:160-162`), a hardcoded dropship shipping premium
(`:182 $premium = $request->has('dropship') ? (float) config('shipping.drop_shipping_premium', 2.00) : 0.0;`),
coupon re-resolution (`:211-222`), a VAT reverse-charge decision (`:226-227`), a pro-rata discount factor
for tax (`:242`), the order total
(`:262 $totalAmount = max(0, $subtotal - $discountAmount + $shippingCost + $taxAmount);`), a
`DB::transaction` (`:275-308`), payment-gateway branching (`:317-337`), compensating stock release
(`:323`, `:331`), three order state transitions (`:324`, `:332`, `:344`), mail (`:352-353`), dropship
queueing (`:356-362`) and download grants (`:365`). This is the most valuable business process in an
e-commerce application and it lives in an HTTP handler.

Nine more carry domain rules: `StripeWebhookController.php:78-79` computes refund money
(`$refunded = (float) (($charge->amount_refunded ?? 0) / 100); $fully = $refunded >= (float) $order->total_amount;`)
and `:103-112` implements a private order state machine; `Api/OrderRefundController.php:22` computes the
refundable balance; `InventoryController.php:32-39` does stock arithmetic behind its own sentinel
(`:14 private const NEGATIVE = 'NEGATIVE_INVENTORY';`); `Api/ReturnRequestController.php:13-14` encodes
return eligibility as a controller constant; `Api/CartController.php:43-52` and `CartController.php:30-45`
each implement cart-merge and oversell rules — the same rules, twice, in two controllers;
`ReviewController.php:24-30,85-91` owns duplicate-review and vote-tally rules;
`RatingController.php:35-54` computes rating aggregates; `PaymentMethodController.php:85-90` enforces the
one-default-per-user invariant in a private controller method called from three actions.

*Database queries.* **28 of 39 controllers** query Eloquent/DB directly.
`Frontend/ProductController.php:94-118` builds an entire search query in the controller;
`CheckoutController.php:153` and `:246` each run `Product::find($productId)` inside a loop — two N+1s on
the checkout path; `DownloadController.php:75-82` and `:106-111` run two `whereHas` entitlement queries
that *are* the authorization decision.

*Validation.* **46 inline validation sites across 21 controllers** (30 `$request->validate(`, 16
`Validator::make(`) against **2 FormRequest classes** in `app/Http/Requests/`, consumed by exactly two
actions (`RatingController.php:12`, `ReviewController.php:20`). Both FormRequests return
`authorize() { return true; }` (`RatingRequest.php:15`, `ReviewRequest.php:16`), so they carry no
authorization either. `CheckoutController.php` mixes both idioms in one class (`:75`, `:117`). Two sites
bypass their own validator and mass-assign raw input: `SiteSettingController.php:50`
`$setting->update($request->all());` and `:69` `SiteSetting::create($request->all());`.

*Authorization.* **`authorize()`, `Gate::`, `->can()` and `can:` middleware appear 2 times in total
across all of `app/Http/` and `routes/`.** The universal idiom is instead a hardcoded role-string check,
`abort_unless(...)` appearing 24 times, e.g.
`abort_unless($request->user()?->hasRole(['super_admin', 'admin']), 403);` at
`Api/CollectionController.php:64,124,170,221,261`, `Api/DropshippingController.php:27,50,78,106`,
`Api/ProductController.php:96,141,195` and twelve more.

17 policies exist in `app/Policies/` — `OrderPolicy` alone defines 12 methods — but
`app/Providers/AuthServiceProvider.php:15-17` is an empty stub (`protected $policies = [ // ];`), `boot()`
at `:22-25` is `//`, and `Gate::` is commented out at `:5`. No route or controller consults them, so they
are reachable only through Filament's convention-based auto-discovery. **25 of 39 controllers have no
authorization check of any kind**, including `DownloadController`, `PaymentMethodController`,
`SubscriptionController` and `WishlistController`.

Two explicit ownership comparisons — exactly the anti-pattern the standard names:
`CheckoutController.php:380` `$ownsOrder = $order->user_id !== null && $request->user()?->id === $order->user_id;`
and `Api/ReturnRequestController.php:40`.

*Provider SDK in a controller.* `SubscriptionController.php:21` `Stripe::setApiKey(env('STRIPE_SECRET'));`
in the constructor, then `:26` `$plans = Plan::all();` calls Stripe's live API from the action.
`viewAvailableSubscriptions` is routed publicly (`routes/web.php:141`), so an unauthenticated request
reaches the Stripe account. The same file drives Cashier inline at `:40-41`, `:57`, `:69` while
delegating the PayPal equivalents to a service at `:87`, `:99`, `:110` — two orchestration styles in one
class.

*Routes.* All 90 web routes are named; **all 40 API routes are unnamed** (`routes/api.php` contains zero
`->name(` calls). Admin gating for API mutations lives in controllers rather than middleware, and
`routes/api.php:37` documents the omission in a comment.

*Dead code.* `Frontend/ProductController.php:125-141` and `:147-259` are commented-out method bodies —
113 lines, 43% of the file. Five routes at `routes/web.php:188-191` point at four of those deleted
methods and will throw on any request.

**Gap.** The standard's opening sentence is inverted across the whole HTTP layer.

**Severity: Critical.** **Class: structural.**

---

## API.md — API implementation standard

**Requires.** "The API layer is an adapter, not a second domain model." Version public contracts; document
authentication, permissions, tenancy, rate limits, validation, pagination, errors, idempotency,
concurrency. "Return purpose-built resources/read models and RFC 9457-compatible errors." "Authorize
before protected queries and mutations; redact sensitive fields by policy and context." "Make external
callbacks verifiable, replay-safe, deduplicated, observable, and reconcilable." "Generate or
contract-test references from the versioned schema."

**This repo.**

- **`app/Http/Resources/` does not exist. Zero `JsonResource` subclasses in the application.** There are
  **145 `response()->json(` calls across 27 controllers**, every one serialising a raw model, a raw
  paginator, or a hand-built array.
- **No versioning.** `routes/api.php` has no `v1` prefix and no version negotiation. **All 40 API routes
  are unnamed.**
- **No OpenAPI schema exists**, so "generate or contract-test references from the versioned schema" has
  nothing to reference. `docs/API_COLLECTIONS.md` is a hand-written substitute that nothing verifies.
- **Sensitive-field leakage.** `Api/WebhookEndpointController.php:19`
  `return response()->json(WebhookEndpoint::latest()->paginate(15));` returns the model whole, and that
  model carries the signing `secret` written at `:40` `'secret' => 'whsec_'.Str::random(40),`. The route
  is staff-gated (`:17 $this->authorizeStaff($request);`), so this is not unauthenticated exposure — but
  the standard requires fields to be "redact[ed] … by policy and context", and a signing secret has no
  read use case at all. Because there is no Resource class, there is nowhere to express that.
  `ChatController.php:52,78,101,114,133,195,212` return raw `ChatConversation` rows with customer PII.
  `PaymentMethodController.php:14,36,58,63,79` return raw payment-method models including their `details`
  column. `routes/api.php:28-30` returns the `User` model straight from a route closure.
- **Inconsistent response contracts.** At least three incompatible shapes coexist, sometimes in one file:
  `Api/OrderController.php:24` returns a bare paginator while `:41` returns `{success, data}`;
  `Api/CollectionController.php:35-51` hand-copies a `{data, links, meta}` envelope while `:56` in the
  same action returns a bare paginator when `per_page` is absent.
- **No RFC 9457 Problem Details** anywhere; errors are ad-hoc `{'error' => '...'}` JSON.
- **Unscoped tenant reads.** `Api/ProductController.php:21` `$query = Product::query();` and
  `Api/CollectionController.php:22` `QueryBuilder::for(ProductCollection::class)` open an unfiltered
  query. `Product` uses `IsTenantModel`, but that trait adds only a `belongsTo(Team::class)` relation and
  **no global scope** (`app/Traits/IsTenantModel.php:11-14`), so any valid Sanctum token reads every
  team's catalogue. The standard requires authorization "before protected queries", and LARAVEL.md
  requires tenant context to be resolved "before protected queries and mutations".
- Webhooks *are* signature-verified (`StripeWebhookController.php:25-32`, `PaypalWebhookController.php:34`)
  — that part is right — but there is no replay window, no delivery-ID deduplication and no
  reconciliation job.

**Gap.** There is no API adapter layer; the API is the model layer with `response()->json()` around it.

**Severity: Critical.** **Class: structural**, and it is a live data-exposure finding, not only a
layering one.

---

## SERVICES.md — application services

**Requires.** "Give each service one clear responsibility and a verb-based action name where it performs
a use case." "Inject contracts and collaborators; keep framework adapters at the boundary." "Authorize
before protected reads/mutations, establish tenant context, and make transaction scope explicit."
"Return typed results or purpose-built read models." "Keep external calls behind provider-neutral
adapters with timeouts, retries, rate limits, reconciliation, and audit evidence."

**This repo.** 28 service files, 3,503 lines.

- *Size:* nothing exceeds 300 lines or 10 public methods, so there are no god-services by size. The
  problem is the reverse — the layer is too *thin*: 28 services against 39 controllers and 105 Filament
  files, so most use cases have no service to call and the logic settles in the adapter instead.
- *Injection:* **only 6 of 28 services declare a constructor, and none of the 6 type-hints an
  interface.** Five constructor parameters exist in the whole directory and all five are concrete classes
  (`CheckoutService.php:32 private CouponService $couponService`; `HeadlessCheckoutService.php:24-29`,
  four concretes). The other 22 reach collaborators through static factories or facades.
- *Framework adapters at the boundary:* **56 facade static calls across 15 service files.**
  `AnalyticsService.php` alone makes 18 `DB::` calls
  (`:32,33,36,37,58,70,98,106,107,125,134,143,145,155,167,178,180,221`) — it is a query layer wearing a
  service's name. `ChatService.php:22,25,26,50` calls `Auth::` inside the service, so it reads ambient
  identity rather than receiving it.
- *Authorization inside services:* none. Every `abort_unless` in the codebase is in a controller.
- *Transaction scope:* **only 3 of 28 services open a transaction.** The unguarded multi-record writers
  include the two that matter most: `CheckoutService.php:130 reserveStock()` writes `order_items`
  (`:133`), decrements `products.inventory_count` (`:143`) and inserts an `InventoryLog` (`:149`) in a
  per-line loop with no transaction — a mid-loop failure leaves a prefix of the cart reserved. Its
  compensating twin `CheckoutService.php:167 releaseStock()` (`:175`, `:177`) is equally unguarded, so a
  partial release double-counts stock. Also `ChatService.php:16,65,94` (two-table writes each) and
  `ProductRecommendationService.php:148-177` (bulk rebuild).
- *Typed results:* **one DTO exists in the entire application** — `app/Services/Shipping/CarrierRate.php:11-18`,
  fully `readonly`, and it is correct. Everything else crossing a service boundary is an untyped `array`:
  all three `PaymentGatewayInterface` methods, all `AnalyticsService` returns, all
  `DropshippingService`/`DropxlService` returns.
- *Provider-neutral adapters with timeouts and retries:* **`->retry(` appears zero times in `app/`.**
  Timeouts exist at 3 of 8 outbound call sites (`Shipping/EasyPostCarrier.php:33` configurable,
  `ViesService.php:51` configurable, `Jobs/SendWebhookDelivery.php:56` hardcoded) and are absent from
  `DropshippingService.php:45,81,111`, `DropxlService.php:47,68`, `ShippingService.php:111` and both
  payment gateways. `ShippingService.php:111` calls a placeholder endpoint
  (`Http::get('https://api.address-verifier.com', [`) with no timeout at all.
- *Secrets:* `DropxlService.php:22-23` reads `env('DROPXL_API_URL')` and `env('DROPXL_API_KEY')` in its
  constructor. Under `php artisan config:cache` — the documented production step — `env()` returns the
  default, so the API key silently becomes `''`. `config/dropshipping.php:33-41` already defines a
  `dropxl` block this bypasses. `SubscriptionController.php:21` has the same fault with
  `env('STRIPE_SECRET')`, where the cached value is `null`.

**Gap.** The service layer exists but is thin, untyped at its edges, unauthorised, mostly untransacted,
and coupled to facades rather than contracts.

**Severity: High.** **Class: structural**, except the `env()`-in-constructor sites, which are mechanical
and are also a live production bug.

---

## CONTRACTS.md — stable boundaries for replaceable behaviour

**Requires.** "Add one when substitution, testing, integration, or versioned extension is real — not for
every class." Small, capability-focused, typed, framework-neutral. "Prefer immutable DTOs and explicit
result/error types over leaking ORM models or framework internals." "Test the contract against the
concrete adapter and meaningful alternate/fake implementations."

**This repo.** Four interfaces exist in total.

| Interface | Methods | Implementations | Verdict |
| --- | --- | --- | --- |
| `app/Interfaces/PaymentGatewayInterface.php:5` | 3 | 2 (`StripeGateway.php:10`, `PayPalGateway.php:76`) | Real substitution — the one justified contract |
| `app/Interfaces/CarrierRateInterface.php:7` | 1 | 1 (`Shipping/EasyPostCarrier.php:18`) | Speculative — a single implementation |
| `app/Interfaces/Orderable.php:5` | 2 | 2 (`Product.php:19`, `ProductCollection.php:12`) | **Dead** — nothing type-hints it anywhere |
| `app/Modules/Contracts/ModuleInterface.php:5` | 10 | 2, one an anonymous class at `ModuleManager.php:293` | Part of the unused module scaffolding (#926) |

- **None of the four is bound in a service provider.** The only container registrations in
  `app/Providers/` are `AppServiceProvider.php:17` (a concrete→concrete singleton) and third-party
  Fortify/Jetstream bindings. Resolution instead goes through static service-locator factories:
  `app/Factories/PaymentGatewayFactory.php:16-17` `'stripe' => app(StripeGateway::class)`.
- **The one real contract leaks untyped arrays.** `PaymentGatewayInterface` returns `array` on all three
  methods (`:7,8,9`) with a different key shape per implementation — `StripeGateway.php:30` returns
  `['success' => true, 'transaction_id' => $charge->id]`, `PayPalGateway.php:101-105` adds a `status` key.
  A caller cannot rely on either.
- **No contract test suite exists** (`tests/Contract/` absent), so the two payment adapters are never
  proven to satisfy the same behaviour.
- Four interfaces across a 400-file application — one dead, one speculative, one scaffolding — is not
  restraint. The standard warns against *over*-use; the failure mode here is the opposite.

**Gap.** No usable contract layer, and the one real contract is untyped and untested.

**Severity: High.** **Class: structural.**

---

## CLASSES.md — cohesion, injection, invalid state

**Requires.** "One cohesive responsibility, explicit dependencies, stable names." "Prefer small immutable
value objects, focused actions, queries, policies, adapters, and domain services." "Use constructor
injection and explicit visibility/types; avoid service-locator calls and static mutable state." "Keep
constructors cheap; perform I/O in named methods." "Make invalid state difficult to create."

**This repo.**

- *Service locator:* `app/Models/Product.php:190` `return app(TaxCalculator::class)->displayPrice($this);`
  and `app/Models/Refund.php:66` `$result = app(PaymentGatewayService::class)->refundPayment(` — an
  Eloquent model resolving a payment gateway out of the container. Also
  `app/Factories/PaymentGatewayFactory.php:16-17` and `app/Factories/CarrierRateFactory.php:22`.
- *Expensive constructors:* `app/Services/PaymentGateways/StripeGateway.php:16`
  `$this->stripeClient = new StripeClient(Config::get('services.stripe.secret'));` — construction is
  network-client setup; `DropxlService.php:22-23` reads env in the constructor;
  `DropshippingService.php:15` reads config in the constructor.
- *Immutable value objects:* 1 (`Shipping/CarrierRate.php`). `final` classes: **1**
  (`app/Support/EuVat.php:15`). `readonly` promoted properties: 15 across 8 files, four of which hold a
  live Eloquent or interface instance (`app/Mail/InvoiceMail.php:16` and the four `app/Modules/Events/*`)
  — an immutable reference to a mutable target.
- *Invalid state:* nothing prevents it. With zero enums and untyped array returns, an order status is
  whatever string is assigned. `app/Models/Order.php:41-60` defines a `TRANSITIONS` map that is a real
  state machine, but it is consulted in only two places
  (`app/Jobs/DispatchDropshippingOrder.php:128`, `StripeWebhookController.php:103-112`); every other
  write to `status` bypasses it, including the Filament form at
  `app/Filament/App/Resources/Orders/OrderResource.php:49-66`, which exposes `payment_status` as a free
  `Select` alongside an editable `total_amount` (`:44`).
- *Static mutable state:* none found. That rule holds.

**Gap.** Injection and immutability are the exception; the container is used as a locator from inside
models; invalid state is trivially constructible.

**Severity: High.** **Class: structural.**

---

## CONCERNS.md — traits and cross-cutting behaviour

**Requires.** "Before adding a trait, show at least two real owners with the same small behavior and no
hidden lifecycle assumptions." Narrow, namespaced, documented. "Do not use traits to hide authorization,
queries, transactions, external calls, mutable global state, or unexpected boot hooks." "Document
required methods/properties, boot order, conflicts."

**This repo.** Three application-owned traits.

| Trait | Lines | Owners |
| --- | --- | --- |
| `app/Traits/IsTenantModel.php:8` | 15 | 34 models |
| `app/Modules/Traits/Configurable.php:7` | 34 | **1** (`app/Modules/BaseModule.php:20`) |
| `app/Modules/Traits/HasModuleHooks.php:5` | 49 | **1** (`app/Modules/BaseModule.php:20`) |

- `Configurable` and `HasModuleHooks` each have a single owner, breaking the two-owner rule outright.
  Both belong to the dead module scaffolding (#926).
- `Configurable.php:25,30,35,40,46` uses the `Config` facade inside the trait and depends on a
  `getName()` its host must supply — an undeclared required method, which the standard asks to be
  documented.
- `HasModuleHooks.php:8` `protected array $hooks = [];`, mutated at `:19` and `:41` — mutable state
  introduced by a trait.
- `IsTenantModel` is the important one: 34 owners, so the two-owner test passes easily, but
  `app/Traits/IsTenantModel.php:11-14` declares only `return $this->belongsTo(Team::class);`. **There is
  no global scope.** The trait's name promises tenant scoping to 34 models and delivers a relation.
  Every tenant boundary in this application is therefore enforced — where it is enforced at all — by
  ad-hoc `where('user_id', ...)` clauses in controllers.

Positively: no trait contains queries, authorization, boot hooks or HTTP calls.

**Gap.** Two single-owner traits, and one widely-applied trait whose name overstates what it enforces.

**Severity: Medium.** **Class: mechanical** for the two dead traits (they go with the scaffolding);
**structural** for `IsTenantModel`, since making the name true means auditing 34 models' query paths.

---

## MODELS.md — persistence-owned data

**Requires.** "Models represent persistence-owned data and its local mapping rules." "Use
guarded/validated assignment, explicit casts, relationships, scopes, and database constraints." "Keep
business invariants in domain/application boundaries when they span records, workflows, permissions, or
providers." "Avoid hidden queries in accessors, serialization, views, jobs, and authorization checks;
prevent N+1 behavior intentionally." "Do not expose private models or tables as cross-module extension
points."

**This repo.** 99 models, 6,960 lines, one flat namespace.

*Business invariants in models.* `app/Models/Product.php` is 423 lines with 44 public methods, 19
relationships and 7 scopes; 18 of those methods are neither relationship nor scope:
`:188 displayPrice()` resolves `app(TaxCalculator::class)` from the container inside a model;
`:193-232 booted()` registers four lifecycle hooks including a write to a second table
(`downloadable()->updateOrCreate(...)` at `:209-220`) and a back-in-stock notification dispatch
(`:225-231`); `:242 notifyBackInStockSubscribers()` loads subscribers, sends mail and mutates each
(`:256 $subscriber->markAsNotified();`); `:395 adjustInventory()` opens a `DB::transaction`, does a
guarded decrement, `refresh()`es and writes an `InventoryLog` row (`:412`).
`app/Models/Order.php:138 transitionTo()` does four things at once: throws
`InvalidOrderTransitionException` (`:143`), derives `payment_status` via `match` (`:153-157`), writes an
audit row (`:164`), fires webhooks (`:171`) and generates an invoice (`:175 Invoice::generateForOrder($this);`).
`app/Models/User.php:218 getOrCreateCustomer()` writes a *second aggregate* from the User model,
splitting `$this->name` into `first_name`/`last_name` at `:220`.

*Hidden queries in accessors — the standard's explicit prohibition.* `app/Models/Customer.php:83`
`return $this->orders()->where('payment_status', 'paid')->sum('total_amount');` and `:93`
`return $this->orders()->count();`; `:88 getLifetimeValueAttribute()` re-triggers the first; `:100
isVip()` runs both, so one `isVip()` call is two round trips. `app/Models/InventoryItem.php:58,63,68,73`
are four separate accessors each issuing its own aggregate against the same relation — reading all four
costs four queries. `app/Models/User.php:108` appends `profile_photo_url` on every serialization, and
`:66 canAccessPanel()` runs `$this->allTeams()->isNotEmpty()` — a query inside an authorization decision,
which the standard names specifically.

*Casts.* **26 of 99 models declare no `$casts` at all**, including `Review`, `CartItem`,
`ProductCollection`, `ProductRating` and `ChatAnalytics`. Each has typed columns that go uncast:
`reviews.approved` is `boolean` (`database/migrations/2023_04_03_000000_create_reviews_table.php:21`);
`cart_items.price` is `decimal(10,2)`
(`database/migrations/2023_09_27_130936_create_cart_items_table.php:22`) yet `ProductCollection::getPrice(): float`
(`app/Models/ProductCollection.php:26`) returns the raw string; `ProductRating` divides four *nullable,
uncast* integers at `app/Models/ProductRating.php:33-36`. Even `Product` casts only 5 of its columns
(`:52-58`), leaving `is_variable`, `is_grouped`, `is_simple`, `is_featured` (which *is* in `$fillable` at
`:49`) and `expiration_time` uncast.

*Mass assignment.* `$guarded` appears **zero times** across 99 models; 7 models declare neither
`$fillable` nor `$guarded`, so their protection is Eloquent's implicit default rather than a stated
decision.

*Broken references.* `app/Models/Team.php:76-79` `return $this->hasMany(Collection::class);` — the class
`App\Models\Collection` does not exist (the model is `ProductCollection`, `$table = 'collections'`), and
there is no `use` import, so the relation resolves to nothing. `database/factories/CollectionFactory.php:5,10,31`
carries the same broken reference.

*Duplicate models — the ubiquitous-language failure made concrete* (#929). Fifteen competing pairs, of
which the most consequential:

| Concept | Competing models | Evidence |
| --- | --- | --- |
| Reviews | `Review.php` (`reviews`) vs `ProductReview.php` (`product_reviews`) | Both actively maintained — `2026_07_13_000003_add_votes_to_reviews_table.php` and `2026_07_14_001902_add_votes_and_verified_to_product_reviews_table.php` add the *same* columns to both |
| Ratings | `Rating.php` (`ratings`) vs `ProductRating.php` (`product_rating`) | Same duplicated migration pattern; `User::ratings()` points at one, `Product::rating()` at the other |
| Inventory | `Product.inventory_count` + `InventoryLog` vs `InventoryItem`+`InventoryLevel`+`InventoryAdjustment`+`InventoryLocation` | `Product::adjustInventory()` (`:395`) and `InventoryLevel::adjustQuantity()` (`:44`) are two independent sources of truth for stock |
| Identity | `User.php` vs `Customer.php` | `app/Models/User.php:207-208` comment: "a Customer is the same identity as a User"; reconciled at runtime by `getOrCreateCustomer()`, and `orders` carries both `customer_id` and `user_id` |
| Order tax | `orders.tax_total` vs `orders.tax_amount` | Two columns for one value; `tax_total` is in neither `$fillable` nor `$casts` |
| Order history | `OrderStatusHistory` vs `OrderEvent` (+ `OrderNote`) | All three created in one migration; `transitionTo()` writes only the first |

**Gap.** Models own the business, own hidden queries, and own the identity of concepts that were never
reconciled.

**Severity: Critical.** **Class: structural**, except casts and `$guarded`, which are mechanical.

---

## DATABASE.md — migrations, seeders, factories, queries

**Requires.** Migrations "deterministic, reversible where practical, safe for production data".
"**Never edit a released migration.** Add a new migration and document deployment order." Separate
destructive from additive changes. Seeders "explicit, repeatable, environment-aware", using "stable keys
and upserts for reference data so rerunning a seeder does not duplicate records", with baseline and demo
seeders separated and production running "only the required baseline set", and "never seed production
credentials, personal data, secrets, or nondeterministic records". Factories with explicit named states
"such as `draft`, `active`, `disabled`, or `unauthorized`". Constraints for invariants, indexes for
observed access paths, and no hidden database access in accessors.

**This repo.** 122 migrations, 18 seeders, 20 factories.

*Migrations.*

- **Every migration defines `down()`** — 0 missing. That rule holds.
- **40 of 122 migrations guard their work with `Schema::hasTable(...)` / `Schema::hasColumn(...)`**, so
  the resulting schema depends on prior database state rather than on the file. A *create* migration that
  silently no-ops:
  `database/migrations/2022_09_26_113708_create_products_table.php:16 if (!Schema::hasTable($this->table)) {`.
  Their `down()` bodies are unguarded (`2026_07_14_001901_...:26-29` drops unconditionally), so up and
  down are asymmetric.
- **Released migrations have been edited repeatedly**, which the standard forbids outright. Confirmed via
  `git log --diff-filter=M`: `2023_09_30_151612_create_invoices_table.php` modified in 4 commits,
  `2022_09_26_113708_create_products_table.php` 4, `2022_09_26_113707_create_product_categories_table.php` 4,
  `2023_09_28_132432_create_order_items_table.php` 3, and roughly fifteen more with 2 each. One migration
  was even *renamed after release* — commit `71a899f` "Rename 2023_09_26_113708_create_products_table.php
  to 2022_09_26_113708_...", changing the execution order of an already-run migration. The visible
  consequence: `create_products_table.php:20` now contains `$table->string('slug')->nullable()->unique();`
  while `2026_02_16_195912_add_slug_to_products_table.php` also adds `slug` — hence the `hasColumn` guard.
- **Destructive and additive changes mixed in one file**, e.g.
  `2026_07_12_000000_add_recipient_fields_to_orders_table.php:36-39` does four `->nullable()->change()`
  calls *and* `dropColumn` of three columns at `:46`;
  `2026_07_18_000000_align_schema_with_cashier.php:33,36` renames two columns and `dropIfExists('subscription_items')`
  at `:56`; `2026_07_15_000000_move_wishlist_share_token_to_users.php:22-23` drops a unique index and a
  column.
- **A data migration inside a schema migration**, unreversed:
  `2026_02_16_195912_add_slug_to_products_table.php:25-27` chunks `products` and backfills slugs. The
  standard asks for resumable backfill jobs, not inline chunks.
- **Two `create_site_settings_table` migrations creating two different tables** —
  `2023_04_01_000000_...` creates `site_settings`, `2023_05_15_000000_...` creates `settings`. Only the
  first has a model.
- **25 `unsignedBigInteger` foreign-key columns with no `constrained()`**, e.g.
  `2026_07_12_000001_add_tracking_columns_to_inventory_logs_table.php:25`.

*Seeders.*

- **10 of 13 writing seeders use non-idempotent `create()`/`insert()`; zero use `upsert()`.**
  `UserSeeder.php:22 User::create([`, `DefaultTeamSeeder.php:16`, `PermissionsTableSeeder.php:24`
  (`\DB::table('permissions')->insert([` over a 1,394-line literal array), `MenuSeeder.php:96`,
  `ShieldSeeder.php:60`, and all five `DummyData/` seeders. Rerunning `db:seed` fails on unique
  constraints or duplicates rows.
- **Environment awareness: zero.** No `app()->environment(...)`, `App::environment`, `isProduction` or
  `APP_ENV` reference anywhere in `database/seeders/`. `DatabaseSeeder.php:23-31` calls
  `DummyDataSeeder::class` unconditionally alongside the baseline set, so `php artisan db:seed --force`
  in production seeds demo catalogue data.
- **A credential is printed to stdout.** `database/seeders/UserSeeder.php:21` generates
  `$adminPassword = Str::random(12);`, creates a `super_admin` with the fixed address
  `admin@example.com` and `email_verified_at` pre-set (`:22-27`, `:33`), then at `:36`
  `$this->command->info("Admin password: {$adminPassword}");`. `install.yml:85` runs
  `php artisan db:seed --force` in CI, so that password is written into a GitHub Actions log on every
  push. The standard's rule is "never seed production credentials … or nondeterministic records" and
  GUIDELINES.md adds "do not commit … generated credentials".
- Six seeders (`EuVatRatesSeeder`, `PermissionsSeeder`, `ShieldSeeder`, `ArticleSeeder`, `PageSeeder`,
  `TeamSeeder`) are registered nowhere and never run.

*Factories.*

- **6 of 20 factories define any named state**, and none of them is a lifecycle state. The states that
  exist are `unverified`, `withPersonalTeam`, `withConnectedAccount` (`UserFactory.php:41,51,72`),
  `inactive` (`CustomerSegmentFactory.php:42`, `RecommendationRuleFactory.php:50`) and `private`
  (`GiftRegistryFactory.php:49`). **No `draft`, `active`, `disabled`, `published`, `paid` or `cancelled`
  state exists anywhere**, despite `Page::STATUS_DRAFT`/`STATUS_PUBLISHED` (`app/Models/Page.php:12-13`)
  and ten order status constants (`app/Models/Order.php:17-35`).
- **There is no `OrderFactory` at all**, so no test can construct an order in a named state — which is
  why order state transitions have no unit-level evidence.

*Queries.* 27 raw `DB::` lines across 5 files. `AnalyticsService.php:32`
`DB::raw("{$groupBy} as period"),` interpolates a variable directly into raw SQL;
`:58`/`:70` pass `DB::raw('total_amount - COALESCE(refund_total, 0)')` as the argument to `sum()`;
`ProductRecommendationService.php:162` puts a raw expression in the *value* position of a `where`.
`app/Models/Product.php:14` imports the `DB` facade into a model.

**Gap.** Migrations are edited rather than added, seeders are neither repeatable nor
environment-aware, one leaks a credential into CI logs, and factories cannot express domain state.

**Severity: Critical.** **Class: mixed** — the seeder credential and the environment guard are
mechanical and urgent; the released-migration editing is structural (the schema history no longer
describes any real deployment).

---

## JOBS.md — asynchronous work

**Requires.** "Pass stable identifiers and immutable values, not live models or request/session state."
"Establish tenant/team context explicitly and fail closed when it is absent." "Make handlers safe to
retry; use idempotency keys, unique jobs, deduplication, or compensating actions." "Define backoff,
timeout, max attempts, dead-letter behavior, alerting, and operator recovery." "Dispatch after commit
when a job depends on committed state."

**This repo.** Three jobs.

| Job | `$tries` | `$timeout` | `$backoff` | `$maxExceptions` | `uniqueId` | `failed()` | Idempotent |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `app/Jobs/DispatchOutboundWebhook.php` | no | no | no | no | no | no | existence guard only (`:25-27`) |
| `app/Jobs/SendWebhookDelivery.php` | no | no | no | no | no | no | **no** |
| `app/Jobs/DispatchDropshippingOrder.php` | `:26` yes (3) | no | no | no | no | `:110` yes | yes (`:47-49`, `:53-55`) |

- **Identifier passing: all three comply.**
  `DispatchOutboundWebhook.php:17 public function __construct(public int $orderId, public string $event) {}`.
  This is the one part of the standard the repository gets right.
- **Retry policy: 1 of 3 sets `$tries`; none sets `$timeout`, `$backoff` or `$maxExceptions`.**
  `SendWebhookDelivery.php:14` hand-rolls its own counter — `private const MAX_ATTEMPTS = 3;` — and
  re-dispatches itself at `:78-79` with `->delay(now()->addSeconds(30 * $this->attempt))`, bypassing the
  framework's retry, backoff and failure machinery. It therefore never lands in `failed_jobs`, so there
  is no dead-letter behaviour, no alert and no Horizon visibility for the one job that talks to customer
  endpoints.
- **`SendWebhookDelivery` has no idempotency guard** — a retry re-POSTs to the customer endpoint and
  inserts a second delivery row at `:63`.
- **Tenant context: absent from all three.** Nothing captures a `team_id` at dispatch or restores it in
  `handle()`, and `IsTenantModel` supplies no global scope, so a worker runs with no tenant boundary. The
  standard's "fail closed when it is absent" cannot happen, because nothing checks.
- **Dispatch-after-commit: `dispatchAfterCommit`/`afterCommit()` appear zero times in `app/`**, and
  `config/queue.php:42,51,62,71` sets `'after_commit' => false` on all four connections. This is live:
  `CheckoutController.php:275` and `HeadlessCheckoutService.php:70` both open transactions whose bodies
  reach `Order::transitionTo` → `app/Models/Order.php:189-193 fireOutboundWebhooks()`, which dispatches
  `DispatchOutboundWebhook`. A worker can pick that job up before the order row is committed.
- **An ordering race with a silent failure mode.** `CheckoutService.php:102-107 queueDropship()` runs
  `$order->update(['supplier_id' => $supplierId]);` → `DispatchDropshippingOrder::dispatch(...)` →
  `$order->transitionTo(Order::STATUS_SUPPLIER_QUEUED, ...)`, with no transaction. A worker starting
  between lines 106 and 107 sees a status that is not yet `supplier_queued`, and the job's own guard at
  `DispatchDropshippingOrder.php:53-55` then returns — the supplier order is dropped with no error and no
  log.

**Gap.** Payload discipline is correct; retry, timeout, tenancy, commit ordering and dead-letter policy
are absent, and two concrete race conditions follow.

**Severity: High.** **Class: mechanical** for the missing `$tries`/`$timeout`/`$backoff` and the
`after_commit` config; **structural** for the hand-rolled retry loop and the transaction ordering.

---

## QUEUES.md — queue selection and operations

**Requires.** "Select queues by workload, priority, tenant isolation, and operational ownership." "Set
explicit connection, queue, timeout, retry, backoff, `tries`, and `maxExceptions` behavior." "Make
delivery and external calls idempotent and observable with correlation IDs." "Do not enqueue uncommitted
assumptions, secrets, request objects, or authorization decisions." "Monitor age, throughput, failure
rate, retries, dead letters, and saturation; document safe replay and discard procedures."

**This repo.**

- **No job names a connection or a queue.** All three dispatch to the default, so there is no priority
  separation between a customer-facing webhook and a supplier order.
- Retry, backoff and timeout: one `$tries` in total (see JOBS.md).
- **Correlation IDs: none.** No job carries a request or correlation identifier, so a failed supplier
  order cannot be traced back to the checkout that caused it.
- **Uncommitted enqueues:** `config/queue.php:42,51,62,71` `'after_commit' => false` plus the two
  transaction sites above — precisely the standard's "do not enqueue uncommitted assumptions".
- Monitoring: `laravel/horizon ^5.47` is required (`composer.json:20`) and
  `app/Providers/HorizonServiceProvider.php` exists, so the tooling is installed — but there is no
  replay or discard runbook anywhere in `docs/`, and `SendWebhookDelivery`'s hand-rolled retry means its
  failures never appear in Horizon's failed-job view at all.

**Gap.** Horizon is installed and unused as an operational contract.

**Severity: Medium.** **Class: mechanical** for queue names and `after_commit`; **structural** for
correlation IDs and the runbook.

---

## FILAMENT.md — Filament 5 panels and resources

**Requires.** "Filament is an optional presentation adapter over Liberu domain capabilities. It provides
administrative and operational interfaces without owning business rules, persistence rules,
authorization decisions, or theme identity." Reusable Filament logic ships as `module-*-filament` /
`theme-*-filament` packages installed under `/modules` and `/themes`; the application owns only panel
providers. "Mutations call authorized domain actions; they do not bypass invariants with ad hoc model
writes." "Navigation visibility is not authorization." Resource slugs, page routes and widget identifiers
"must be stable and collision-checked" and "duplicate identifiers fail during development/CI … rather
than last-write-wins behavior." "Widgets … obtain data from authorized queries/read models." "User-facing
labels, help and notifications are translated."

**This repo.** 105 files under `app/Filament/`: `Admin/Resources` 44, `App/Resources` 36,
`Admin/Widgets` 9, `Admin/Pages` 6, `App/Pages` 5, `Resources/` 4, `App/Widgets` 1.

*Packaging.* **No `modules/` or `themes/` directory exists.** All Filament logic lives in the root
application under `App\Filament\`, so the entire `module-*-filament` model in §2–§5 is unimplemented.
This is the same finding as #926/#928 seen from the presentation side.

*Business rules inside Filament.*

- `app/Filament/Admin/Pages/ChatAgentDashboard.php:60-74` — a public, Livewire-callable `assignToMe($conversationId)`
  that reads a conversation by an unvalidated, untyped caller-supplied id and performs a three-field state
  transition (`'agent_id' => Auth::id(), 'status' => 'active', 'started_at' => now()`) with no policy
  check.
- `app/Filament/App/Pages/EditTeam.php:33-47` — `Team::forceCreate([...])` (bypassing mass-assignment
  guarding), a pivot `attach`, and `switchTeam` — three writes, no transaction. `App\Actions\Jetstream\CreateTeam`
  already exists and the sibling `CreateTeam.php:35` delegates to it correctly; this page reimplements it.
- `app/Filament/Admin/Resources/Products/ProductResource.php:188-190` and `:194-210` — inventory writes
  from table actions. The first discards the return value of `adjustInventory()`, so a rejected
  adjustment reports success; the second checks it (`:194`), so two adjacent actions on one resource
  disagree about whether the domain result matters.
- `app/Filament/Admin/Resources/Products/ProductResource.php:247-269` — a CSV export that derives stock
  status in presentation (`:249`) and writes to disk at `:267` `file_put_contents($path, $csv->getContent());`
  using a date-only filename, so concurrent exports collide.
- `app/Filament/Admin/Pages/DropxlImport.php:124-142` — a synchronous, unqueued bulk catalogue import
  driven from a page; the confirmation modal at `:186` admits "This may take a while." All four entry
  points (`:67`, `:95`, `:124`, `:147`) are public Livewire endpoints with no policy check.
- `app/Filament/App/Resources/Orders/OrderResource.php:49-66` — the order state machine exposed as a free
  `Select` for `payment_status` next to an editable `TextInput` for `total_amount` (`:44`). An operator
  can mark an order paid and rewrite its total with no transition guard, no audit row and no service
  call — bypassing `Order::TRANSITIONS` entirely. `InvoiceResource.php:53-60` repeats the pattern.
- `app/Filament/Admin/Widgets/TopProductsWidget.php:21-42` — revenue reporting SQL hand-written in a
  widget, including `DB::raw('SUM(order_items.price * order_items.quantity) AS total_revenue')` and the
  domain rule `->where('orders.payment_status', 'paid')` hardcoded at `:29`. The sibling
  `SalesOverviewWidget.php:15-16` delegates to `AnalyticsService` correctly, so the codebase demonstrates
  both the rule and its violation side by side. `CustomerGrowthWidget.php:16-23` and
  `ChatStatsWidget.php:17-19` query directly too.

*Authorization.* **Zero `canViewAny`/`canCreate`/`canEdit`/`canDelete` overrides exist in `app/Filament/`.**
No `Gate::` call, no `authorize(` call, no `HasShieldPermissions` implementation and no
`getPermissionPrefixes()` anywhere in `app/` — Shield is registered as a navigation plugin only
(`AdminPanelProvider.php:96-97`) and is not registered on the App panel at all.

Of 22 resource classes, **13 have an associated policy class and 9 do not** (`UserResource`,
`PageResource`, `TaxClassResource`, two `MenuResource`s, `MenuItemResource`,
`ChatConversationResource`, `DiscountResource`, `CustomerSegmentResource`). The App panel compensates
with a global flag — `AppPanelProvider.php:70 ->strictAuthorization()` — and the comment at `:60-70`
explicitly records that the Admin panel does *not* have it precisely because 8 of its resources would
throw. So the Admin panel's resources are reachable by any user who passes `Authenticate` +
`TeamsPermission`, and this is a known, documented state.

Both panels also hardcode `shouldRegisterMenuItem() { return true; }` with the real check commented out
(`AdminPanelProvider.php:139-142`, `AppPanelProvider.php:168-171`).

*Collisions and dead registrations — the standard's §9.4 "must fail, not last-write-wins".*

- **`MenuResource` is duplicated inside one panel.** `app/Filament/Admin/Resources/MenuResource.php:7`
  is a thin vendor subclass (`class MenuResource extends BaseMenuResource`) while
  `app/Filament/Admin/Resources/Menus/MenuResource.php:23-25` is a hand-rolled second resource on the
  same `Menu` model with an empty form (`:34-36` `//`). `discoverResources` recurses, so both register.
- **`app/Filament/Resources/` is unreachable.** Neither provider discovers `app_path('Filament/Resources')`
  — only `Filament/Admin/Resources` (`AdminPanelProvider.php:61`) and `Filament/App/Resources`
  (`AppPanelProvider.php:83`). `CustomerSegmentResource` (145 lines) and its three pages are dead UI,
  including the `Recalculate Members` action.
- **`app/Filament/App/Widgets/SocialLinksWidget.php` is never loaded** —
  `AppPanelProvider.php:89` discovers `app_path('Filament/App/Widgets/Home')`, a directory that does not
  exist.
- Two divergent `ProductResource` definitions (Admin 271 lines with stock actions, App 169 lines without)
  for one model.
- Orphaned imports pointing at namespaces that do not exist:
  `Admin/Resources/Stores/StoreResource.php:14-15`, `Admin/Resources/Menus/MenuResource.php:13-14`.
- `AdminPanelProvider.php:68-79` registers 9 widgets explicitly that `:63` already auto-discovers.
- The Admin panel imports its tenancy pages from the App namespace (`AdminPanelProvider.php:10`,
  `:116-129`), and the two panels order their auth middleware inversely (`AdminPanelProvider.php:91-94`
  vs `AppPanelProvider.php:105-108`).

*Localisation.* **139 uncommented hardcoded English `->label('...')` calls**, of which only 3 use `__()`
(all in `CategoryResource.php:79,81,85`). A further 89 hardcoded strings appear via `Stat::make(`,
`Section::make(`, `->heading(`, `->helperText(`, `$navigationLabel`, `->modalDescription(` and similar,
plus 38 hardcoded option values (`'paid' => 'Paid'`) and 10 hardcoded `Notification` titles.

*Strict types.* **0 of 105** Filament files declare `strict_types`.

**Gap.** Filament is not an adapter here; it is a second application containing its own business rules,
its own SQL, its own duplicated resources and no authorization layer.

**Severity: Critical.** **Class: structural.**

---

## LIVEWIRE.md — Livewire 4 components

**Requires.** "Livewire is an optional presentation adapter … without owning business rules, persistence
rules, authorization decisions, tenant resolution, or visual identity." Reusable components ship as
`module-*-livewire` packages with a bounded registered namespace and stable aliases; "application-only
components may use `App\Livewire` until they become reusable". "Every action validates untrusted state
and authorizes the operation server-side." "Mutations call domain/application actions rather than writing
models ad hoc." "Reauthorize records after hydration and immediately before mutation." "Hiding a
component, button or navigation item never substitutes for authorization."

**This repo.** Five components in **two directories**.

| Location | Components | Lines |
| --- | --- | --- |
| `app/Livewire/` | `ShoppingCart.php` 141, `ChatWidget.php` 82, `CartCount.php` 27 | 250 |
| `app/Http/Livewire/` | `InvoicePdf.php` 25, `CreateTeam.php` 23 | 48 |

- **Two roots is itself the finding.** `app/Http/Livewire/` is the Livewire v2 convention;
  `app/Livewire/` is v3+. There is no `Livewire::component()` registration anywhere in `app/`, `config/`
  or `routes/`, so `App\Http\Livewire\InvoicePdf` **cannot be resolved by name** under the default class
  namespace. Its view `resources/views/livewire/invoice-pdf.blade.php`, referenced at `:21`, **does not
  exist** either.
- **`app/Http/Livewire/InvoicePdf.php:16` `$this->invoice = Invoice::findOrFail($invoiceId);`** mounts a
  caller-supplied id with **no policy check**, despite `InvoicePolicy` existing in `app/Policies/`.
- **No component in either directory contains a single `authorize()`, `Gate::` or policy call.**
- Domain logic in components: `app/Livewire/ShoppingCart.php:36-48,102` re-derives price from the model,
  gates on stock and computes the cart total inline
  (`:137 fn (float $carry, array $item) => $carry + ($item['price'] * $item['quantity'])`). The comment at
  `:32-35` explains *why* it re-derives price rather than trusting the payload — that instinct is right
  and the standard would endorse it — but the rule belongs in a cart service, not a component.
  `app/Livewire/CartCount.php:19-21` sums quantities inside `render()`.
- `app/Livewire/ChatWidget.php:42` `sendMessage()` and `:65` `submitRating()` dispatch browser events for
  which **no listener exists anywhere in `app/`**, so the chat widget is non-functional.
- `app/Http/Livewire/CreateTeam.php:16-19` delegates to `App\Actions\Jetstream\CreateTeam` and validates
  at `:14`. This is the standard implemented correctly — and
  `app/Filament/App/Pages/EditTeam.php:37-44` is the same operation reimplemented badly, in the same
  repository.
- Localisation: **0 `__()` calls** in either Livewire directory; seven hardcoded English flash strings at
  `ShoppingCart.php:41,49,66,103,112,121,130`.
- Strict types: **0 of 5**.

**Gap.** Two component roots, one unresolvable, one unauthorised invoice reader, zero authorization
calls, and a non-functional widget.

**Severity: High.** **Class: mechanical** for consolidating the two directories; **structural** for the
authorization and the cart rules.

---

## BLADE.md — server-rendered views

**Requires.** "Keep components small, explicit, escaped by default, and independent of ambient database
queries." "Use layouts, slots, components, translation strings, locale-aware formatting, and semantic
HTML." "Use `@csrf`, safe URL generation, authorization directives only for presentation, and policy
checks in the server action." "Keep Livewire behavior in Livewire components and domain mutations in
authorized application actions." Verify "output escaping, CSRF, links, forms, keyboard/focus behavior".

**This repo.** 140 templates.

*What holds.*

- **Zero Blade files query the database.** No `\App\Models\`, `::all()`, `::where(` or `DB::` appears in
  any of the 140 templates. This is genuinely clean and is the strongest single result in the audit.
- **Every `method="POST"` form has `@csrf`.** 45 `<form>` tags, 35 `@csrf`; the 10 without are all
  `method="GET"` or `wire:submit`.
- Only **5 `{!! !!}` unescaped echoes**, and three are defensible: `products/show.blade.php:219` is
  `json_encode` with `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT` and a comment at `:186-188`
  naming the `</script><script>` vector it defends against; `components/input.blade.php:5` and
  `components/checkbox.blade.php:4` are the standard `$attributes->merge` idiom.

*What does not.*

- **Domain arithmetic in templates.** `resources/views/checkout/confirmation.blade.php:61` recomputes an
  order subtotal from four persisted columns:
  `${{ number_format($order->total_amount - $order->shipping_cost - $order->tax_amount + ($order->discount_amount ?? 0), 2) }}`.
  If the stored columns disagree, the customer's confirmation page silently shows a different number from
  the order record. Also `invoices/show.blade.php:30`, `checkout/confirmation.blade.php:54`,
  `livewire/shopping-cart.blade.php:61`, and `livewire/shopping-cart.blade.php:87,96`, which call
  `$this->calculateTotal()` from the template twice per render.
- **8 inline `onclick=` handlers**, and one interpolates unescaped model data into a JavaScript argument
  list — `resources/views/shipping/index.blade.php:67`
  `onclick="openEditModal({{ $method->id }}, '{{ $method->name }}', '{{ $method->description }}', ...)"`.
  `{{ }}` HTML-escapes but does not JS-escape, so an apostrophe in a shipping method's name breaks out of
  the string literal. Others at `shipping/index.blade.php:10,94,139,155,201` and
  `checkout/checkout.blade.php:327,340`.
- **12 inline `<script>` blocks and 6 `<style>` blocks**, plus two remote script tags at
  `checkout/checkout.blade.php:632-633` (`js.stripe.com`, `paypal.com/sdk/js`). Any CSP would need
  `unsafe-inline`, which THEMES.md §10 rules out ("avoid inline scripts unless protected by the
  application's content security policy nonce mechanism").
- **Localisation: 218 `__()` calls, but in only 28 of 140 files (20%)**, and — as recorded under
  TRANSLATIONS.md — there is no `lang/en/`, so all 218 fall through to returning their own key.

**Gap.** The escaping and CSRF discipline is real; the presentation boundary leaks in the other
direction, with money recomputed in templates and JS-injection through unescaped interpolation.

**Severity: Medium.** **Class: mechanical** for the `onclick`/inline-script cleanup; **structural** for
the money arithmetic, which needs a view model to move to.

---

## VIEWS.md — view contracts

**Requires.** "Pass typed/purpose-built view models or explicit props; do not depend on ambient database
state." "Cover loading, empty, error, unauthorized, offline, validation, and success states where the
surface can encounter them." "Escape output by default … and localize all user-facing copy." "Keep
layout regions, slots, test IDs, semantic landmarks, focus behavior, and extension points stable."

**This repo.**

- **View models: none.** There is no `app/ViewModels/` or `app/Http/ViewModels/` directory and no DTO is
  ever passed to a view. Every template receives raw Eloquent models. Because
  `resources/views/components/product-card.blade.php:14-15` falls back to `$product->getTotalReviews()`
  and `$product->getAverageRating()` when the eager-loaded aggregates are absent, the template's cost
  depends on which controller rendered it — `HomeController.php:37` eager-loads them, so the fallback is
  dormant today, but nothing in the view's contract says so.
- **Localisation: absent** (see TRANSLATIONS.md).
- **Test IDs: none.** No `data-testid` or equivalent stable hook anywhere in `resources/views/`, so the
  "stable test identifiers" the standard requires do not exist — which is also why there are no browser
  tests.
- Escaping is good (see BLADE.md).
- State coverage was not measured exhaustively, but the absence of a view-model layer means an
  unauthorized or error state has no typed representation to render from.

**Gap.** Views consume persistence objects directly; there is no view contract to keep stable.

**Severity: Medium.** **Class: structural.**

---

## THEMES.md — design tokens, theme packages, assets

**Requires.** Themes are independently released packages installed under `/themes/{installer-name}` with
a `theme.json` manifest; "the application Vite configuration discovers installed `themes/*/theme.json`
manifests and derives entry points exclusively from each manifest's `assets.css` and `assets.js`
declarations. It must not repeat installed themes or their asset paths in a maintained literal list."
"All themes define or inherit semantic tokens for color, typography, spacing, radius, elevation, borders,
motion, breakpoints, focus, and layering. Components consume semantic tokens such as `--color-surface`
and `--color-action-primary`, not brand-specific raw values." Tokens must cover "light, dark,
high-contrast, error, warning, success, disabled, and focus states." "Avoid inline scripts unless
protected by … CSP nonce." WCAG 2.2 AA.

**This repo.**

- **There is no `themes/` directory and no `theme.json`.** The whole theme package model is
  unimplemented, so §3–§5 (layout, manifest contract, resolution and inheritance) have no surface here.
- **`vite.config.js:10-14` is exactly the "maintained literal list" the standard forbids** — three
  hardcoded entry points (`resources/css/app.css`, `resources/js/app.js`,
  `resources/css/filament/admin/theme.css`) with no manifest discovery.
- **Tokens are brand-scale ramps, not semantic roles.** `resources/css/app.css:41-50` defines
  `--color-primary-50` … `--color-primary-900`. There is no `--color-surface`, no
  `--color-action-primary`, no error/warning/success/disabled/focus token, and no dark or
  high-contrast set. `tailwind.config.js:7-9` separately hardcodes a *different* primary ramp in hex
  while `app.css` uses `oklch` — two competing palettes for one brand.
- **Three CSS frameworks are stacked** in `tailwind.config.js:21-24`: `@tailwindcss/forms`,
  `preline/plugin` and `flowbite/plugin`, with both `preline` and `flowbite` also in the content globs.
  There is no documented cascade-layer order, which §9 requires.
- Filament theming is a single file (`resources/css/filament/admin/theme.css`) shared by **both** panels
  — `AppPanelProvider.php:71` points at the *admin* theme — so the App panel has no visual identity of
  its own.
- Accessibility: no automated check exists in CI, no skip links found, no reduced-motion handling in
  `app.css`. WCAG 2.2 AA is claimed nowhere and evidenced nowhere.

**Gap.** No theme packaging, no semantic tokens, two competing palettes, three UI kits.

**Severity: Medium.** **Class: mechanical** for the Vite manifest discovery and token renaming;
**structural** for the packaging model, which depends on the module system that does not exist (#926).

---

---

## OBJECT-ORIENTED-PROGRAMMING.md — object design

**Requires.** "Favor encapsulation, composition, polymorphism at genuine variation points, and dependency
inversion." "Keep invariants with the object or domain boundary that owns them." "Use interfaces where
consumers need substitution; use concrete classes when abstraction adds no value." "Separate domain
policy, application orchestration, infrastructure adapters, and presentation concerns." "Keep domain
objects independent of Laravel."

**This repo.**

- *Dependency inversion:* inverted. Zero interface-typed constructor parameters across 28 services; and
  models resolve services out of the container (`app/Models/Product.php:190`, `app/Models/Refund.php:66`),
  so the dependency arrow points from persistence to application.
- *Invariants with their owner:* the negative-stock invariant lives in `InventoryController.php:35-37`,
  the one-default-payment-method invariant in `PaymentMethodController.php:85-90`, the order-total
  invariant in `CheckoutController.php:262`, the refundable-balance invariant in
  `Api/OrderRefundController.php:22`, and the paid/total invariant nowhere at all — the Filament form at
  `app/Filament/App/Resources/Orders/OrderResource.php:44-66` lets an operator set both freely. Not one
  of these sits with the object that owns the data.
- *Polymorphism at genuine variation points:* one real (`PaymentGatewayInterface`, 2 implementations),
  one speculative (`CarrierRateInterface`, 1), one dead (`Orderable`, 0 consumers).
- *Domain objects independent of Laravel:* there are no domain objects. Every business concept is an
  Eloquent model, so the domain layer is by construction a Laravel dependency and cannot be unit-tested
  without a database.
- *Separation of the four layers:* this audit finds domain policy in controllers, in Filament resources,
  in widgets, in models, in webhooks and in one Blade template — six locations, none of them a domain
  layer.

**Gap.** The four layers the standard asks to be separated are collapsed into two: Eloquent models and
presentation adapters.

**Severity: Critical.** **Class: structural.**

---

## DOMAIN-DRIVEN-DESIGN-PATTERNS.md — bounded contexts and tactical patterns

**Requires.** "Define bounded contexts/modules around cohesive business capabilities and ubiquitous
language." "Use entities and aggregates for identity and invariants, value objects for constrained
values, and domain services for cohesive rules that do not belong to one entity." "Use application
actions/services to coordinate a use case … and read models/query objects for optimized reads."
"Publish domain events after committed local changes; use outbox/inbox, sagas, and compensating actions
for distributed workflows." "Keep aggregates small, enforce consistency boundaries, and never expose
private persistence as a cross-module contract."

**This repo.**

- **No bounded contexts exist at runtime.** `app/Modules/` is 1,095 lines of unused scaffolding (#926);
  modules ship no `extra.laravel.providers` (#928). The 99 models sit in one flat namespace with no
  capability boundary between catalogue, orders, payments, chat, analytics, GDPR and inventory.
- **Value objects: 1** (`app/Services/Shipping/CarrierRate.php`). **Enums: 0.** Money is a bare `float`
  throughout — `CheckoutController.php:262`, `StripeWebhookController.php:78`,
  `Api/OrderRefundController.php:22` and `Api/CartController.php:26` all do float arithmetic on currency,
  and `ProductCollection::getPrice(): float` (`app/Models/ProductCollection.php:26`) returns an uncast
  `decimal` string through a `float` return type.
- **Aggregates: none.** `Order` is the nearest candidate; its `TRANSITIONS` map
  (`app/Models/Order.php:41-60`) is a real consistency rule, but only two call sites consult it and the
  Filament form bypasses it entirely.
- **Domain events after commit:** `after_commit` is `false` on every connection
  (`config/queue.php:42,51,62,71`) and `dispatchAfterCommit` is never used.
  `app/Modules/Events/` holds four *module lifecycle* events that belong to the dead scaffolding; there
  are no business domain events (`OrderPlaced`, `PaymentCaptured`, `StockReserved`).
- **Compensating actions:** one exists and it is unsafe. `CheckoutService.php:167 releaseStock()`
  compensates a failed payment by incrementing inventory in an untransacted loop (`:175`, `:177`), so a
  partial failure double-counts stock.
- **Read models / query objects: none.** Complex reads are 18 `DB::` calls inside `AnalyticsService`
  returning untyped arrays, plus hand-written SQL in two Filament widgets.
- **Private persistence as a contract:** unavoidable here, since every consumer — API, Filament, Blade,
  jobs — reads the models directly.
- **Ubiquitous language:** contradicted by 15 duplicate stacks (#929). `Review` vs `ProductReview`,
  `Rating` vs `ProductRating`, two tax engines (`TaxService`, `TaxCalculator`), two recommenders
  (`RecommendationService`, `ProductRecommendationService`), two checkout services (`CheckoutService`,
  `HeadlessCheckoutService`), two cart models (`CartItem`, `AbandonedCart`), two inventory systems, two
  identities (`User`, `Customer`), and two columns for order tax. Two names for one concept means the
  ubiquitous language does not exist.

**Gap.** None of the tactical patterns is present, and the strategic prerequisite — bounded contexts —
does not exist at runtime.

**Severity: Critical.** **Class: structural.**

---

## Summary — findings ranked by severity

| # | Standard | Gap in one line | Severity | Class |
| --- | --- | --- | --- | --- |
| 1 | **CONTROLLERS.md** | 259-line checkout workflow in an HTTP handler; 2 policy calls in the entire HTTP layer against 24 hardcoded role checks; 25 of 39 controllers unauthorised | Critical | Structural |
| 2 | **FILAMENT.md** | Zero `can*` overrides and zero `Gate::` calls in 105 files; order `payment_status` and `total_amount` editable as free form fields; duplicated and unreachable resource trees | Critical | Structural |
| 3 | **API.md** | `Api/ProductController.php:21` reads every tenant's catalogue with any Sanctum token; zero API Resources against 145 `response()->json()` calls; webhook signing `secret`, payment `details` and customer PII returned raw; no versioning, no schema | Critical | Structural |
| 4 | **DOMAIN-DRIVEN-DESIGN-PATTERNS.md** | No bounded contexts at runtime, no aggregates, no value objects, no domain events, 15 duplicate model stacks | Critical | Structural |
| 5 | **DATABASE.md** | Released migrations edited in up to 4 commits each (one renamed after release); 10 of 13 seeders non-idempotent; `UserSeeder.php:36` prints an admin password into CI logs | Critical | Mixed |
| 6 | **MODELS.md** | 99 flat models owning transactions, mail, webhooks and invoice generation; hidden queries in accessors; 26 models with no casts | Critical | Structural |
| 7 | **PHP.md** | `declare(strict_types=1)` in 2 of 400 app files; **zero enums**; one file in `app/` has no `<?php` tag | Critical | Mixed |
| 8 | **LARAVEL.md** | Domain rules inverted with no module layer to hold them; Laravel 13 running a Laravel 10 skeleton (`bootstrap/app.php`, three Kernel classes, `config/app.php` providers array); `TeamServiceProvider` binds 11 nonexistent genealogy classes on every boot | Critical | Structural |
| 9 | **OBJECT-ORIENTED-PROGRAMMING.md** | Four layers collapsed into two; every invariant enforced outside the object that owns it | Critical | Structural |
| 10 | **SERVICES.md** | Zero interface-typed constructor params; 3 of 28 services transactional; 5 of 8 outbound calls have no timeout; API keys read via `env()` in constructors | High | Structural |
| 11 | **CONTRACTS.md** | 4 interfaces total — 1 real, 1 speculative, 1 dead, 1 scaffolding; none bound; none contract-tested | High | Structural |
| 12 | **CLASSES.md** | Models resolving services from the container; 1 value object, 1 `final` class, 0 enums in 400 files | High | Structural |
| 13 | **JOBS.md** | 1 of 3 jobs sets `$tries`, none sets timeout/backoff; hand-rolled retry that bypasses `failed_jobs`; jobs enqueued inside open transactions | High | Mixed |
| 14 | **PSR.md** | PSR-12 declarative half unmet; PSR-4 broken by one file; PSR-20 `ClockInterface` unused across 78 `now()` calls | High | Mixed |
| 15 | **PINT.md** | No `pint.json`, no CI step, no Composer script — the standard's one hard gate does not exist | High | Mechanical |
| 16 | **CI.md** | Formatting, static analysis and architecture gates all absent; no `release.yml`; actions on floating tags | High | Mechanical |
| 17 | **DOCUMENTATION.md** | No `CHANGELOG`/`CONTRIBUTING`/`SECURITY`/`LICENSE`, no `docs/index.md`, no ADRs, no runbooks; 3 overlapping architecture docs | High | Mixed |
| 18 | **TRANSLATIONS.md** | No application catalogue at all; 218 `__()` calls resolve to their own keys; ~270 hardcoded English strings in Filament | High | Structural |
| 19 | **GUIDELINES.md** | Umbrella failure: strict types, value objects, localisation, RTL, layering all unmet; two Livewire namespaces; a production `error_log` committed at the repo root leaking `/home/liberu/projects/ecommerce-laravel` | High | Mixed |
| 20 | **LIVEWIRE.md** | Two component roots (one unresolvable); `InvoicePdf` reads any invoice with no policy; zero `authorize()` calls | High | Mixed |
| 21 | **TESTING.md** | 1,209 tests but no `Architecture/`, `Contract/`, `Security/` or `Migration/` suite; no Pest; no Composer test scripts; coverage scope unfiltered; assertion-free tests and an ownerless skip | Medium | Mixed |
| 22 | **CONCERNS.md** | Two single-owner traits; `IsTenantModel` promises tenant scoping to 34 models and provides only a relation | Medium | Mixed |
| 23 | **QUEUES.md** | No queue names, no correlation IDs, `after_commit` false on all connections; Horizon installed but blind to the webhook job | Medium | Mixed |
| 24 | **BLADE.md** | Order money recomputed in a template; unescaped model data interpolated into an `onclick` argument list | Medium | Mixed |
| 25 | **THEMES.md** | No theme packaging; `vite.config.js` uses the forbidden literal entry list; brand ramps instead of semantic tokens; three UI kits stacked | Medium | Mixed |
| 26 | **VIEWS.md** | No view models anywhere; templates receive raw Eloquent; no stable test IDs | Medium | Structural |
| 27 | **ADOPTION.md** | Install is proven by CI; backup, restore, export, update and limits are documented nowhere; `minimum-stability: beta` | Medium | Mechanical |
| 28 | **CONTRIBUTING.md** | No `CONTRIBUTING.md` and no PR template, so none of the quality bar is stated to a contributor | Medium | Mechanical |
| 29 | **FRONTEND-TESTING.md** | Mostly out of scope (no SPA); no browser test for the checkout journey | Low | Mechanical |

### Cross-cutting observations

**One number explains most of the list.** `declare(strict_types=1);` in 2 of 400 files, zero enums, zero
API Resources, zero view models, four interfaces, one DTO and 2 policy calls in the HTTP layer are all
the same fact seen from different angles: there is no layer between Eloquent and the adapters. Nineteen
of the 29 findings are downstream of that.

**The most urgent items are not the most severe.** Three findings are small, mechanical and shipping
today: `Api/ProductController.php:21` serves every tenant's catalogue to any valid Sanctum token;
`database/seeders/UserSeeder.php:36` prints a generated admin password into every CI log;
`Api/WebhookEndpointController.php:19` returns webhook signing secrets to every staff user over the
API; and
`DropxlService.php:23` / `SubscriptionController.php:21` read API keys through `env()`, which returns
the default under `config:cache`.

**The repository already contains its own counter-examples.** `AppPanelProvider.php:60-70` enables
`strictAuthorization()` and documents exactly which resources would fail without it;
`main.yml:92-138` smoke-tests a Docker image before publishing and explains the two production faults
that motivated it; `app/Http/Livewire/CreateTeam.php:16-19` delegates to an action while
`app/Filament/App/Pages/EditTeam.php:33-47` reimplements the same operation inline;
`SalesOverviewWidget.php:15-16` calls a service while `TopProductsWidget.php:21-42` writes its own SQL;
`phpunit.xml:29-36` and `products/show.blade.php:186-188` both document a real decision in a comment. The
standards are reachable from where this codebase already is — the patterns exist in it, they are just
not the default.

**What is not broken.** No Blade template queries the database. Every POST form carries `@csrf`. All
three jobs pass identifiers rather than models. Every migration has a `down()`, and the schema carries
169 foreign-key declarations and 167 index/unique declarations. 92 models declare `$fillable` and none declares `$guarded = []`. Money
at rest is `decimal(10,2)` everywhere — the float problem is in PHP, not the schema.
`Api/ProductController.php:26` escapes `LIKE` wildcards with `addcslashes` and says why. No tabs, no
closing PHP tags, no CRLF, no static mutable state. 1,209 test methods with 1,949 assertions. Webhook
signatures are verified. These are the parts of the standards the repository already meets, and they are worth stating
because they narrow the migration surface.
