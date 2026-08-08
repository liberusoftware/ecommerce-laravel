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
- Directory scanning to invent registration: `app/Modules/` contains a manual class scanner
  (see #926) that `architecture/MODULES.md:193` explicitly forbids.

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
- Runtime filesystem scanning: present in `app/Modules/` (#926).
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

**Gap.** Correct framework versions, inverted layering.

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

**Gap.** Aggregate of the above.

**Severity: High.** **Class: structural.**

---
