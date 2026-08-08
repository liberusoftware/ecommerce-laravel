# Module development

How to build, test, promote and release an Ecommerce module.

This is the document that outlives the migration. [`CONFORMANCE.md`](./CONFORMANCE.md) describes a codebase that starts changing the day wave 0 lands; [`MIGRATION_PLAN.md`](./MIGRATION_PLAN.md) is finished when the last module ships. This one stays useful.

Everything here derives from the Liberu standards. Where commerce deviates, the deviation has an ADR and is marked **⚠ deviation** at the point it applies.

---

## 1. Package anatomy

### 1.1 The four names

A module carries four names in two forms. Only the repository keeps the `module-` prefix, and every other name derives from it mechanically — there is nothing to look up.

| | Example | Rule |
| --- | --- | --- |
| Repository | `module-ecommerce-cart-filament` | `module-{product}-{capability}[-flavor]` |
| Composer package | `liberusoftware/ecommerce-cart-filament` | repository minus `module-` |
| `extra.liberu.name` | `ecommerce-cart-filament` | package minus vendor |
| Namespace | `Liberu\Ecommerce\Cart\Filament\` | package, `StudlyCase` per segment |

**⚠ deviation** — [ADR 0004](./adr/0004-no-module-prefix-in-package-names.md). `MODULES.md` §9's five presentation-adapter rows put `module-` in the *package* name and give namespaces two segments. Domain packages (`liberusoftware/ecommerce-cart`, `liberusoftware/ecommerce-core`) are fully conformant.

The product word is **`ecommerce`**, always. Where earlier planning said `commerce-core`, read `ecommerce-core`.

### 1.2 Module names come from the catalogue

[`ECOMMERCE.md`](https://github.com/liberusoftware/documentation/blob/main/projects/ecommerce/ECOMMERCE.md) is authoritative for module boundaries and names. A module that is not in it requires a **documented catalogue addition plus a new repository**, not a local override — 414 provisioned repositories and 105 execution epics are keyed to those names, and the catalogue is what other products' plans are read against.

### 1.3 Layout

```
module-ecommerce-cart/
├── composer.json          type: liberu-module, extra.liberu.name
├── module.json            the manifest module-manager reads
├── src/
│   ├── CartServiceProvider.php
│   └── …
├── routes/
│   └── web.php            this module's routes — see §2, R11
├── resources/
│   ├── views/             default views a theme may override
│   └── lang/{locale}/     this module's catalogue
├── database/migrations/
├── tests/                 Pest 5, standalone
└── .github/workflows/     install.yml, tests.yml, compatibility.yml
```

A module ships **no `extra.laravel.providers`**. Composer install boots nothing; `ModuleManagerServiceProvider` is the sole registrar, gated on `module.json`. Filament panels are *declared* in the manifest, not registered directly.

The module's own service provider registers its resources:

```php
public function boot(): void
{
    $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    $this->loadViewsFrom(__DIR__.'/../resources/views', 'ecommerce-cart');
    $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'ecommerce-cart');
}
```

### 1.4 Views, routes and strings belong to the module

Three rules that all follow from the same principle — a module must be installable and renderable without the host having been edited.

- **Routes.** The module declares its own. The host includes module route files and owns only cross-module pages — home, checkout, sitemap. A module view calling `route('products.show')` against a host-owned name is a module depending on its consumer.
- **Views.** The module ships default views. `THEMES.md` §5's resolution chain is *configured theme → parent chain → **module default view** → application fallback*, so the module's view is precisely the thing a theme overrides.
- **Strings.** The module owns its message keys, in the form `ecommerce-cart::cart.line.removed`. Overrides are `vendor:publish` into `lang/vendor/{namespace}/{locale}/`.

Note the whole fleet is ahead of nobody here: **no published package has ever shipped a translation catalogue, and only the `-livewire` flavour packages ship views.** The first commerce module to do either is exercising the mechanism for the first time.

### 1.5 Tables

New tables carry the module prefix — `ecommerce_cart_*`. Existing tables keep their bare names when they move.

The schema stays permanently mixed as a result, which is recorded as a finding rather than pretended away. Renaming ~122 tables during extraction would put a schema-wide rename in the same waves as a live data-isolation fix, which is the one place a mistake stops being a bug.

---

## 2. The dependency rules

Ten rules, applicable mechanically. R1 and R8 are **extensions** of the documentation rather than readings of it — [ADR 0006](./adr/0006-late-bound-host-model-resolution.md) and [ADR 0007](./adr/0007-events-and-identifiers-across-product-boundaries.md).

### Reaching the host

**R1 — Resolve host models late, never import them.** Reach the host user through `config('auth.providers.users.model')` and the host team through a resolver exposed by `liberusoftware/organizations-teams`. No commerce package ever names `App\Models\User` or `App\Models\Team`. *(MODULES.md §4 r5. The team resolver does not exist yet — it is a deliverable.)*

**R2 — Tenant relations are inverse-only.** Each commerce model keeps `team()`. The forward direction — `Team::orders()`, `Team::products()` — does not survive; a foundation package cannot declare relations into commerce. Traversal from tenant to commerce goes through the owning module's query API. *(§4 r6.)*

**R3 — Foreign keys stay inside a package's own tables**, with one late-bound exception: a reference to `users` or `teams` uses `foreignIdFor(Teams::teamModel())`, never a hardcoded table name. *(§4 r10.)*

### Inside Ecommerce

**R4 — Direct Composer dependency is the default.** Contract packages only where a provider is genuinely swappable — payment, tax, shipping, search. *(§5.2. A contract with one implementation is an interface with one implementation.)*

**R5 — Commerce is strictly tiered, and a test enforces it.**

```
ecommerce-core
  └─ catalog · pricing · inventory
       └─ cart
            └─ checkout
                 └─ orders
                      └─ fulfillment · returns
```

An architecture test asserts the graph is acyclic and that no package requires one above it. *(§4 r11. The tiers double as the migration ordering.)*

**R6 — Orchestration sits at the top and depends downward.** A flow spanning several capabilities lives in the higher-tier module. `CheckoutService` becomes `ecommerce-checkout`, depending on promotions, reservations, payments, digital fulfillment and dropshipping.

**R7 — Shared value types live in `ecommerce-core`.** Money, quantity, address, tax class. They move to a contracts package only when a non-commerce product needs them without the implementation — a tax class fails §5.1's domain-neutral test, so the foundation is not its home.

### Across products

**R8 — Events and stable identifiers only.** A commerce module never requires `billing-*`, `crm-*`, `cms-*` or `accounting-*`. It publishes domain events and holds identifiers — an invoice id, not an `Invoice`. A commerce installation must work with those products absent.

**R9 — Presentation depends on domain; domain never depends on presentation.** A domain package never references Filament, Livewire, themes or HTTP. *(§4 r9, §5.6.)*

**R10 — A module never assumes its host.** Product-specific behaviour is declared, not hard-required. *(§4 r17.)*

---

## 3. The testing bar

### 3.1 What every module needs

- **Unit and feature tests** for its own behaviour.
- **Architecture tests** for the boundary rules in §2.
- **Contract tests** only where a contract exists.

`TESTING.md` §8's full evidence set is **not** the bar. Migration/upgrade, failure and schema-compatibility suites are deferred to a module's second release — the reference fleet meets almost none of it (only `settings` has tests at all; `identity-core`, `organizations-teams` and `webhooks` have zero), and holding commerce to a bar nothing upstream meets would stop extraction rather than improve it.

### 3.2 Characterisation tests are triggered by behaviour

A conditional, a calculation, a state transition, a side effect. **Not** by a coverage number. Pure data models are exempt.

### 3.3 Standalone, and reparented

A module's suite runs **without a host** — `composer install && composer test` from its own repository, on the `liberusoftware/package-testbench` bootstrap. Tests are reparented off the host's `Tests\TestCase` at the moment their subject moves; extending it is what `MODULES.md` §4 r19 forbids and what this gate exists to detect.

New packages use **Pest 5**. The host's existing PHPUnit suite is never rewritten.

### 3.4 Coverage

| | Floor |
| --- | --- |
| Greenfield module | **100%** — `--min=100`, per `MODULES.md` §24 |
| Module extracted from this host | **80%**, ratcheting upward |

**⚠ deviation** — [ADR 0001](./adr/0001-coverage-floor-for-extracted-modules.md). The floor never moves down.

### 3.5 Preservation evidence is three-part

Nothing counts as preserved on two of the three:

1. The host suite is green.
2. The module suite is green **in its own repository**.
3. A **host composition test** asserts registration, migrations, one end-to-end path, and that presentation surfaces resolve in their declared panels.

The boundary rules themselves go upstream into `package-testbench` so every module gets them; only the whole-graph acyclicity check lives in the host.

### 3.6 Deliberate loss needs an ADR *and* an upstream issue

Both, every time. An ADR records the decision; the upstream issue is what stops the loss being permanent.

---

## 4. CI

### 4.1 Three workflows, thin callers

| Workflow | Evidence |
| --- | --- |
| `install.yml` | Clean dependency resolution, package bootstrap, manifest validation, minimal-host install |
| `tests.yml` | Pest 5 suites, architecture and security checks, static analysis, coverage report |
| `compatibility.yml` | Declared minimum/current PHP, Laravel, database, Filament/Livewire, representative hosts |

Each is a 12–22 line caller into a reusable workflow in `liberusoftware/.github`:

```yaml
jobs:
  tests:
    uses: liberusoftware/.github/.github/workflows/package-tests.yml@main
    with:
      phpstan-level: 8
      coverage-threshold: 80
```

No `docker.yml` (`REPOSITORIES.md` §6.3 permits the substitution) and no per-module `release.yml`. **`MODULES.md` §24.1 fixes this at exactly three** — rule 20's *"each repo owns its workflows"* is satisfied by the thin caller, since a callable Actions workflow is not a Composer package.

**Themes ship four.** `THEMES.md` §18.1 adds `visual.yml` for accessibility and visual regression. Two standards, two artifacts, no conflict — and no theme in the fleet has one yet.

### 4.2 Settings

- `phpstan-level: 8` everywhere. Baselines are floors, not measurements.
- `coverage-threshold` per §3.4.
- Third-party actions pinned to **full-length commit SHAs**; the reusable workflow pinned to a tag, not `@main`.

### 4.3 Releases

Tags are **bare** — `1.4.0`, not `v1.4.0`. **⚠ deviation** — [ADR 0005](./adr/0005-bare-version-tags.md). This is not cosmetic: `install.yml` and `compatibility.yml` trigger on bare tags only, so a `v`-prefixed tag publishes having silently skipped both gates. `module-analytics-core`'s `v1.0.4` did exactly that.

A module releases only when its three workflows **and the host composition test against the candidate ref** are green.

Until a module tags `1.0.0` the host consumes it as `dev-main`, via `minimum-stability: dev` + `prefer-stable: true` + `^1.0`. Tagging `0.1.0` at promotion would publish a version number for something the release gate has explicitly not cleared.

---

## 5. The README standard

`REPOSITORIES.md` §6.1–6.3 and §7. Two rules cause every violation found so far:

- **Only display a workflow badge when that workflow exists.** All ~1,930 provisioned stub READMEs carry a `tests.yml` badge with no `.github/` directory and a release badge with no releases.
- **No unqualified claims.** *"Fully compatible with Laravel 13, PHP 8.5, and Pest 5"* is a claim the compatibility matrix must support, or it does not go in the README.

A README documents purpose, installation, the compatibility matrix, and links to evidence. Installation and operations prose belongs in [`INSTALLATION.md`](./INSTALLATION.md) and [`OPERATIONS.md`](./OPERATIONS.md), not in a README that a reader is scanning to decide whether the package is what they want.

---

## 6. Promotion and release

`MODULES.md` §30's "definition of done" is **circular** as a promotion gate: one of its thirteen bullets requires *"the independent GitHub repository, README, CI workflow, generated coverage report, release tag, and tested-host compatibility evidence"*. Repository creation cannot be gated on having a repository.

So it splits at the point the repository exists.

### 6.1 Promotion gate — all provable inside the monorepo

- [ ] No `App\` dependency; no dependency on an unrelated product, provider implementation or presentation framework
- [ ] Architecture tests green (§2's rules)
- [ ] Its Pest suite runs **without a host**
- [ ] Persistence and provider data stay inside the package
- [ ] Public contracts and events versioned, documented, consumer- and implementation-tested
- [ ] Coverage at its floor (§3.4)
- [ ] Capability, category, owner, exclusions, dependencies and manifest approved
- [ ] **No cross-boundary edit in its last N commits**, computed from `git log` at promotion time

That last one is retrospective on purpose. Architecture tests prove the declared rules hold; they do not prove the boundary has stopped moving, and promoting a module whose edges are still shifting means paying the split cost twice. A prospective soak — thirty days, one wave — measures the same thing but waits on a calendar, and calendars get waived under schedule pressure in a way a `git log` query does not.

### 6.2 What promotion does

Eight steps, scripted as `fleet promote <module>`, because a hundred repetitions of an eight-step manual procedure is where steps get skipped — and the one most likely skipped is the composition test at the end.

1. `git subtree split` — **not** an rsync. For extracted code the history is the record of *why*; the commit that introduced a tax rule is often its only explanation. Greenfield modules start fresh.
2. Create the repository **if absent** — most already exist as stubs. 103 of the catalogue's 105 are provisioned; Checkout and Checkout Extensibility are not.
3. Force-push the split history over the stub. The generated README records nothing worth keeping, and merging on top of it would leave a wrong `composer require` line permanently in the history.
4. Scaffold the three workflows and Composer script aliases.
5. Flip `.gitignore` for that module — its files leave the host tree. **⚠ deviation** — [ADR 0010](./adr/0010-modules-and-themes-are-gitignored.md).
6. Swap the host's path entry for a VCS `repositories` entry.
7. Update `.liberu-meta.json`.
8. Run the host composition test.

Promotion is **per-package, the moment each qualifies**. Batching by wave adds a synchronisation barrier where the slowest module holds the rest; a hundred small reviewable host commits beat four large ones.

### 6.3 Release gate — §30 in full

Everything §30 lists, now answerable: the independent repository, README to `REPOSITORIES.md`, the three CI workflows, generated coverage report and badge, release tag, tested-host compatibility evidence, runbooks, changelog and upgrade notes.

The VCS `repositories` entry is **removed** once the module's first tag is live on Packagist. Its presence then carries information — *promoted but not yet published* — instead of accumulating ~100 redundant entries the way the fleet's 47 do today.

### 6.4 Demotion

Free only **before the first tag**, where it amounts to deleting an unreleased repository. After a tag it breaks every consumer and the honest move is deprecation.

In practice a wrong boundary is almost always *"these classes belong to the neighbouring module"*, which is a forward move between two repositories rather than a demotion at all.
