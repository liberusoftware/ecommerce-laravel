# Migration plan

How `ecommerce-laravel` gets from the state recorded in [`CONFORMANCE.md`](./CONFORMANCE.md) to the shape [`MODULE_DEVELOPMENT.md`](./MODULE_DEVELOPMENT.md) describes.

**This document is living.** It is edited as waves land and as the execution epics discover things the plan got wrong. `CONFORMANCE.md` is not — it is a dated snapshot, and the gap between the two is the progress record.

**This plan does not build the 105 modules.** It sequences the structural work: the enforcement layer, the packaging mechanism, the tenancy fix, four data migrations, and the extraction order. Building modules is carried by the 105 `Architecture: Ecommerce — <module>` epics, which execute against this plan.

---

## 1. The sequencing rule

**Tier order, then most-code-first within a tier.**

```
ecommerce-core
  └─ catalog · pricing · inventory
       └─ cart
            └─ checkout
                 └─ orders
                      └─ fulfillment · returns
```

Tier order is forced by the dependency rules ([`MODULE_DEVELOPMENT.md` §2, R5](./MODULE_DEVELOPMENT.md#2-the-dependency-rules)). Within a tier, **the module with the most existing code goes first**: those carry the real risk, and discovering a boundary problem on a large module after ten trivial ones is the expensive ordering. It also front-loads the epics most likely to be wrong about their own scope.

That rule orders the ~100 modules after wave 3 without enumerating them.

### 1.1 Three standing constraints on every wave

1. **Foundation is adopted interleaved, not up front.** A foundation module is adopted when a commerce module needs it. Adopting all 28 first would front-load 28 decisions before a single commerce module proves the approach — and the five with real code movement do not all have a commerce consumer.
2. **A duplicate-stack merge lands before its owning module is extracted** ([`CONFORMANCE.md` §5.3](./CONFORMANCE.md#53-sequencing)). Reviews/Ratings especially: a GDPR-path data migration with a `Customer` backfill, not a code move.
3. **A contested placement is settled by whichever rival module is extracted first**, which states the boundary in its ADR and notifies the rival's epic.

### 1.2 There is no rehearsal extraction, and no storefront wave

Two things the plan deliberately does not contain.

**No throwaway first extraction.** A small commerce leaf was proposed as a rehearsal — Back-in-Stock and Price Alerts — and that was wrong on the facts: `StockNotification` `belongsTo` `Product`, `ProductVariant` *and* `User`, and `ProductBackInStockNotification` imports `Product` and `ProductVariant`. **Essentially every commerce leaf hangs off `Product`**, so there is no dependency-free leaf to rehearse on. `ecommerce-core` keeps the rehearsal property anyway (§3).

**No storefront wave.** Each module takes its routes, views and Livewire components when it is extracted — in the same commit as its domain code, because the route name and the view that calls it live together. Only the shared layer (`theme-ecommerce`) is scheduled, in wave 0.

---

## Wave 0 — make a module loadable, and make the rules enforceable

Nothing can be extracted before this wave. A module ships **no `extra.laravel.providers`**, so Composer install boots nothing: `ModuleManagerServiceProvider::register()` is the only registrar in the reference design, and no module can exist until the manager does.

| Item | Why it is in wave 0 |
| --- | --- |
| Adopt `liberusoftware/composer-installer` | `MODULES.md` §6.1 makes it a prerequisite; nothing installs into `modules/` without it |
| Adopt `module-manager` | The only registrar. **Deletes `app/Modules/`** — 11 files, 1,095 lines of unused scaffolding whose manual class scanner `MODULES.md:193` forbids |
| **Enforcement layer** — `pint.json`, PHPStan at level 8, architecture tests, CI gates, Composer scripts | The cheapest item on the map and the one whose value compounds. Landing it after 20 extractions means 20 modules to re-check |
| Create **`theme-ecommerce`** — `type: public`, `parent: default` | The storefront layout moves **once**, and every later extraction targets an existing theme instead of a moving one |
| Declare **`supported_locales`** in `config/app.php` | The key is absent and `localization-core` reads it. One line, arriving with the localization adoption already committed to |
| Add the **translation lint step** to `package-tests.yml` | A static catalogue check belongs with the enforcement layer, not with the first module that ships a catalogue |
| Generate **badge versions from `composer.lock`** | `REPOSITORIES.md` §6.1 forbids hard-coding a version CI does not verify. The README hard-codes PHP 8.5, Laravel 13, Filament 5, Livewire 4 |
| `package-testbench` upstream contribution — **timeboxed** | The boundary-rule architecture tests belong upstream so every module gets them. Fall back to `commerce-testbench` if it stalls |
| `spatie/laravel-permission` `^8.0` support upstream in `roles-permissions` | This repo is on `^8.3`, the reference app on `^7.0`. **Downgrading a security-relevant dependency to match a module is the wrong direction of travel** |
| Vendor rename `liberu-eccommerce` → `liberusoftware` | Free today — 0 downloads, 0 dependents, no tags. It stops being free the moment anything depends on it. [ADR 0009](./adr/0009-vendor-rename-to-liberusoftware.md) |

### Also in wave 0, because they are one-line and shipping today — ✅ **done**

The small faults that needed nobody's permission. Landed ahead of the rest of wave 0, since none of them depends on the packaging mechanism existing.

| Fault | Fix |
| --- | --- |
| `UserSeeder.php:36` printed a generated admin password, and `install.yml:85` runs `db:seed --force` | Printed only under `app()->environment('local')`. Off `local` the password now comes from `config('seeding.admin_password')`, and without one no admin is created — see below |
| `DummyDataSeeder` sat in `DatabaseSeeder`'s baseline chain, so `db:seed --force` created demo data in production | Called only when `! app()->isProduction()`, in the same position |
| `DropxlService.php:23` and `SubscriptionController.php:21` read keys via `env()` in constructors — `null` under `config:cache` | Read `config('services.dropxl.*')` and `config('services.stripe.secret')`. `services.dropxl` added; **no `env()` call remains outside `config/`** |
| `error_log` committed at the root, leaking `/home/liberu/projects/ecommerce-laravel` | Deleted and gitignored |
| `ScreeningDataEncryptor` — property-rental leftover, encrypts fields absent from this schema | Deleted |
| `app/database/seeders/MenuSeeder.php` — no `<?php` tag, no class | Deleted |
| `PermissionsSeeder` — 17 lines, unreferenced, calls a `permissions:sync` command that does not exist | Deleted |
| `TeamServiceProvider` — bound 11 nonexistent `FamilyTree365\LaravelGedcom\*` classes on every boot, registered in production | Deleted, with its `config/app.php` registration |
| `SiteSettingsService` — read a `config/site-settings.php` that does not exist, and looked settings up against the wrong table shape | Deleted. See below |

`DropxlServiceTest` was updated in the same change: its `setUp` sets config rather than calling `putenv`, which is both the path the service now reads and the one that survives `config:cache`.

### Product comparison — deleted, with the reasoning

Held back from the first pass as a product decision: four registered routes that were guaranteed 500s, because their controller methods sat commented out. Deleting looked like removing a feature someone intended.

Reading the rest of it settled the question. **Nothing was salvageable**, and nothing was reachable:

- The route signatures pass `{category}/{product}`; the commented methods take a single `$id`. Restored as written, they would have compared categories.
- `compare.blade.php`'s empty state — the state the page is in until something adds to it — links `route('products.list')`. **No route of that name exists**, so the page raised `RouteNotFoundException` before rendering.
- Its populated state prints `$product->category`, a `belongsTo` relation, and reads `$product->image_url`, which is neither a column nor an accessor.
- **No view anywhere linked to it.** There is no add-to-compare control in the storefront, so the only way to reach any of it was to type the URL.

So the feature did not exist: it was a broken view, four routes to methods that were not there, and no entry point. Restoring it means writing it, which is a product decision that can be taken later against a clean slate. Deleted: the four routes, `resources/views/products/compare.blade.php`, and the commented-out block at the foot of `Frontend/ProductController.php` — which also carried dead `create`/`update`/`delete` methods superseded by Filament.

### The seeded admin — the other half of #940

Gating the password print on `local` closed the log leak, and left the rest of the fault standing: off `local` the password was still **generated**, and now never shown. So every staging, demo or shared install ended up with an `admin@example.com` super_admin that nobody could log into and everybody could see.

The password now comes from `config('seeding.admin_password')` (`SEED_ADMIN_PASSWORD`). Off `local`, that is the only source: without one the seeder creates **no admin** and says so, rather than a ghost account. On `local` it still generates and prints, which is what someone bootstrapping their own machine wants — and only ever prints what it generated, never a configured value.

CI is unaffected either way: `install.yml` copies `.env.testing`, which sets `APP_ENV=testing`, so it took the no-print branch already and now takes the no-account branch.

### `SiteSettingsService` — deleted, because the config it wanted would have frozen the wrong contract

[#938](https://github.com/liberusoftware/ecommerce-laravel/issues/938) reports that the service reads `config('site-settings.cache_key')` and `config('site-settings.cache_duration')` from a file that was never published, and asks for the file. Writing it would have made a broken lookup permanent.

`site_settings` is a **key/value table**: `name` unique, `value` text, one row per setting. That is what the migration creates, what `SiteSettingController` serves, and what `SiteSettingFactory` produces. The service instead reads `SiteSetting::first()` and returns `$settings->$key` — a **column** off the **first row**. So `get('store_name')` returns `null`, because `store_name` is a value in the `name` column, not a column; and `get('name')` returns whichever setting happens to sort first.

Its own unit test asserted exactly that: it created `name: 'store', value: 'My Shop'` and asserted `get('name') === 'store'`.

The missing config was doing its own damage in the meantime. `config('site-settings.cache_key')` is `null`, so the cache key was the empty string, and a `null` TTL means `Cache::put` stores **forever** — the settings row was cached for the life of the cache store, and any other empty-key write collided with it.

Nothing called the service; the only reference outside its own file was its test. So this is not a repair, it is a deletion: the model, controller, routes and factory stay, and a correct `setting('key')` lookup can be written when something actually needs one — against the table's real shape.

### Socialstream — deleted, and why registering it would have been the bug

[#936](https://github.com/liberusoftware/ecommerce-laravel/issues/936) reads as a wiring fault: `App\Providers\SocialstreamServiceProvider` is absent from `config/app.php`, so its six bindings never run and the eight files under `App\Actions\Socialstream` are dead. The obvious fix is to register it. That fix would have broken social login.

Diffing all eight against `bursteri/socialstream` v7.0.0 at the locked commit: **byte-identical apart from the namespace**, except in two places, and both app-side versions are the poorer one.

- `GenerateRedirectForProvider` had lost the `Session::put('socialstream.previous_url', url()->previous())` that the package writes before handing off to Socialite. The OAuth callback reads that key to return the visitor where they started.
- The provider bound `CreateUserFromProvider` flat. The package binds `match (Jetstream::hasTeamFeatures())` — and `config/jetstream.php` enables `Features::teams`, so the package uses `CreateUserWithTeamsFromProvider`. Registering the app provider would have overridden that with the teamless one, and every social signup would have arrived with no personal team, hence no panel to reach.

That is the whole delta: the published copies are a stale scaffold from the non-teams stub. Deleted — the provider and all eight actions. Social login runs on the package defaults, which is what it has always done, and now says so. `tests/Feature/SocialstreamDefaultsTest.php` pins both behaviours so re-registering shows up as a failure rather than as a fixed issue.

### Two Filament wiring faults

Both from [`OPERATIONS.md`](./OPERATIONS.md#a-widget-or-resource-does-not-appear).

`AppPanelProvider` discovered widgets in `Filament/App/Widgets/Home`, a directory that has never existed, so `SocialLinksWidget` never loaded. The widget also rendered a view that was never written, and fell back to hardcoded links for a different Liberu product. Deleted; discovery now points at `Filament/App/Widgets`.

`MenuResource` being "registered twice" — recorded in the conformance snapshot — **is not a defect**. The plugin registers the same class that discovery finds, and `Panel::getResources()` returns `array_unique($this->resources)`.

`app/Filament/Resources/CustomerSegmentResource` stays where it is for now. It is discovered by neither panel, but both panels are tenant-scoped and `customer_segments` has no `team_id`, so moving it reproduces [#958](https://github.com/liberusoftware/ecommerce-laravel/issues/958) on a third table. It follows the tenant scope in wave 1.5.

### Not gated on wave 0

The **tenant scope fix is not gated on the enforcement layer** — it is a live exposure and does not wait on tooling. It is gated on something else instead: see wave 1.5.

---

## Wave 1 — the first extraction: `ecommerce-core`

`ecommerce-core` is tier 0 and, uniquely among commerce modules, has **no inbound dependency on the god models**. `Store`, `Channel` and the shared value types — money, quantity, address, tax class — are greenfield.

So the first extraction exercises packaging, testbench wiring, migration ownership, panel registration, translation loading and CI **without simultaneously fighting a 99-model graph**. It is small, self-contained, cheap to redo, and it unblocks both tier 1 and wave 1.5.

Its promotion gate is [`MODULE_DEVELOPMENT.md` §6.1](./MODULE_DEVELOPMENT.md#61-promotion-gate--all-provable-inside-the-monorepo). Being greenfield, its coverage floor is `--min=100` — no ADR 0001 ratchet applies.

**What `ecommerce-core` owns on day one:**

- `Store`, `Channel`, `channel_domains` — the schema wave 1.5 needs
- `ChannelResolver` — the domain question *which channel is `shop.example.com`?* belongs to the module that owns `Channel`. The HTTP question — *how does a request carry it* — stays in the host as middleware
- The shared value types

`ecommerce-core` is provisioned in all four flavours, so promotion pushes into existing repositories.

---

## Wave 1.5 — stores, channels, and the tenant scope

**This wave exists because of a sequencing conflict discovered late, and getting the order wrong here ships a security control at the wrong grain.**

### The conflict

Three decisions collided:

1. **The tenant scope ships before the backfill.** #939 is a live cross-merchant read; stopping it outranks correcting ownership. Waiting for the backfill leaves the read open for however long the query checklist takes to run across environments.
2. **Commerce scopes on `store_id`, not `team_id`.** A `Team` may own several `Store`s, and a shopper on store A's domain must see store A's catalogue — not everything their merchant sells across every store. `team_id` is the wrong grain and would under-scope.
3. **`store_id`'s backfill is one of the four backfills** the scope was supposed to precede.

**The scope cannot precede the data it reads.** Scoping on `team_id` first as a stopgap was rejected: it ships a control at the wrong grain and then changes a security control under load.

### The wave, in order

**1. Create the schema and resolve merchants.**

- `stores`, `channels`, `channel_domains` — many hostnames per channel, one flagged **primary** for canonicals. A storefront realistically answers on the apex, `www`, a custom merchant domain and a platform subdomain on day one; a single `domain` column pushes apex/`www` handling into web-server config the application cannot see, so the canonical the app generates and the host the request arrived on can disagree.
- `Channel` gains a **theme reference**, defaulting to `theme-ecommerce`. One storefront, theme selected per resolved channel; per-merchant themes are later children with `parent: ecommerce`.
- Host middleware resolves **host → `Channel` → `Store` → `Team`**, for the web and API route groups alike. The GraphQL resolver context carries the same resolved channel.
- **`TrustHosts::hosts()` derives its list from the channel domains** and caches it. Resolving tenancy from the `Host` header makes it security-relevant, and two lists of the same hostnames drift — the failure when they drift is either a live storefront returning 404 or a host resolving that should not.

**2. Backfill `store_id` alone.** On a today-single-store deployment this is a constant, so it needs no rehearsal — which is exactly why it can be separated from wave 2 without breaking that wave's rehearse-once discipline.

**3. Ship the tenant scope.** One global scope with two modes, rather than two scoping systems that must agree:

| Context | Scope |
| --- | --- |
| Storefront (channel resolved) | `where('store_id', $resolvedStore)` |
| Panel (no channel, tenant known) | `whereIn('store_id', $tenantStores)` |

Panels keep `->tenant(Team::class, …)`. Switching them to `Store` tenancy would break every non-commerce resource, which is legitimately `Team`-grained; adding a store filter per resource is the original failure — scoping at the caller, remembered 105 times.

**This step closes [#939](https://github.com/liberusoftware/ecommerce-laravel/issues/939), [#950](https://github.com/liberusoftware/ecommerce-laravel/issues/950) and [#952](https://github.com/liberusoftware/ecommerce-laravel/issues/952) at once.**

**4. Rebuild the sitemap per channel.** One sitemap per resolved storefront, listing only that store's products. Rewritten rather than moved — the fix rebuilds it anyway, and it stays in the host as pure cross-module aggregation.

### Rules this wave establishes

- **An unresolved host is a 404.** No default-merchant fallback. Single-merchant deployments and local development configure their one channel's domain, `localhost` included. *A configured fallback is exactly how `default(1)` produced the mess wave 2 is unpicking.*
- **A token is checked *against* the resolved channel, never *instead of* it.** Two mechanisms that can disagree means every disagreement is a potential leak.
- **Not a mismatch:** a shopper authenticated at merchant B arriving on merchant A's domain is legitimate traffic. `customers` belongs to a `Team`, and one `User` may hold customer records at several merchants.
- **Order history scopes to the resolved store.** The data is the shopper's, but the surface belongs to the merchant — otherwise merchant A's support staff viewing a customer's account see what that person bought from a competitor.

### Also in this wave

Add `team_id` to the **31 tables whose models declare `IsTenantModel` but whose tables lack the column** ([`CONFORMANCE.md` §6.4](./CONFORMANCE.md#64-six-of-forty-seven-tenancy-pairings-are-coherent)). Until that lands, `DiscountResource` and `MenuResource` are either broken or leaking, and nobody has established which.

---

## Wave 2 — the backfill wave

**Gated on** [the tenant-distribution query checklist](https://github.com/liberusoftware/ecommerce-laravel/issues/944), which needs a human at a real database and cannot be run from this repository. That gate is an argument for running the checklist **now**, not a reason to reorder the waves.

Three backfills — `store_id` already landed in wave 1.5 — shipped as **one sequenced data migration with a single rehearsal against a production-shaped copy**:

| Order | Backfill | Note |
| --- | --- | --- |
| 1 | `team_id` | Every `team_id = 1` row is **unverified** (below) |
| 2 | The reviews `Customer` backfill | Touches the same customer graph as 3 |
| 3 | The cart `'api'` session sentinel | Guest rows keep their identifier; **nothing is truncated** |

Four independent migrations shipped with their own modules would discover any deadlock or half-application in production instead of in rehearsal.

### The `team_id` rule

**No application code writes `team_id`.** The only writers are Filament's tenancy and the migration's `default(1)`, so any row created by the API, a frontend controller, a seeder or a factory silently became team 1.

- **Treat every `team_id = 1` row as unverified.** Attribute it positively from other evidence — a creating user, an order's customer, a parent record's team.
- **Quarantine what cannot be attributed. Do not assign it.** Under a real tenancy boundary a wrong attribution is a cross-merchant leak that the global scope will then enforce and hide. Quarantine is recoverable; a confident wrong assignment is not.
- **After backfill, `team_id` becomes non-nullable and `default(1)` is dropped.** A default on a tenant key means a forgotten assignment silently becomes team 1 instead of failing loudly — the mechanism that created this ambiguity. With no code path writing it outside Filament, keeping the default guarantees the same drift resumes the moment the migration lands.

### The reviews merge

The most expensive of the four, and the only one that is a merge rather than a fill. [ADR 0008](./adr/0008-reviews-and-ratings-merge.md). Two details are load-bearing:

- **Port the `approved` column** onto `product_reviews`. `ReviewController` creates reviews `approved = false`, exposes an approve endpoint, and the public listing filters `where('approved', true)`. Dropping it turns that listing from moderated to unmoderated the moment the merge lands.
- **Backfill a `Customer`** for every user with reviews but no `Customer` record. Both stacks are in the GDPR export and erasure paths; dropping unmappable reviews deletes user-authored personal data to simplify a migration.

Because it runs through GDPR paths, it is **rehearsed against production-shaped data rather than run directly** — which is the same rehearsal this whole wave gets.

### Why this wave precedes any tier-1 extraction

Every module extracted over mis-attributed rows inherits the problem into its own migrations and tests. Doing it once against tables the host still owns is far cheaper than doing it across N packages.

---

## Wave 3 — tier 1, most-code-first

**Catalog**, then **Pricing**, then **Inventory Ledger**.

Catalog first: it has the largest existing footprint, and it is where the god model lives. `Product` stays whole in Catalog; Pricing and Inventory Ledger extend it through their own tables keyed by product id, never by adding columns or relations to a model they do not own.

Prerequisites specific to this wave, from [`CONFORMANCE.md` §5](./CONFORMANCE.md#5-duplicate-stacks):

- The **tax merge** (`TaxCalculator` wins, after diffing `TaxService`) lands before Pricing.
- The **cart merge** lands before the Cart module, which is the tier after this one.
- The **recommender rename** lands with Recommendations.

After wave 3 the sequencing rule in §1 carries the rest with no further enumeration.

---

## 2. The promotion procedure

Full detail in [`MODULE_DEVELOPMENT.md` §6](./MODULE_DEVELOPMENT.md#6-promotion-and-release). What matters to the *plan* is three properties:

**Promotion is per-package, the moment each qualifies** — not per wave. Batching promotions adds a synchronisation barrier where the slowest module holds the rest. A hundred small reviewable host commits beat four large ones.

**Promotion is a source-of-truth flip, not a code move.** During the path phase a module's code is committed to the host. At promotion its `.gitignore` flips, its files **leave the host tree**, and Composer becomes the only source. Whether a module is promoted becomes answerable by `ls`. [ADR 0010](./adr/0010-modules-and-themes-are-gitignored.md).

**The soak is retrospective.** No cross-boundary edit in its last N commits, computed from `git log` at promotion time — not a thirty-day calendar wait. Both measure whether the boundary has stopped moving; only one cannot be waived under schedule pressure.

Until a module tags `1.0.0` the host consumes it as `dev-main`, via `minimum-stability: dev` + `prefer-stable: true`. A VCS `repositories` entry exists **only while a module is unpublished**, so its presence carries information — *promoted, not yet on Packagist*.

---

## 3. Reversibility

What each wave costs to undo, stated up front so nobody has to guess mid-incident.

| Wave | Reversible? | How |
| --- | --- | --- |
| **0** — enforcement, installer, theme | **Yes, cheaply.** Config, CI and deletions of dead code. The riskiest item is deleting `app/Modules/`, which serves zero modules | Revert the commit |
| **1** — `ecommerce-core` | **Yes, before its first tag.** Demotion is deleting an unreleased repository and restoring the path package | See §2 |
| **1.5** — schema, resolver, **the scope** | **The scope is reversible; the schema is additive.** Turning the scope off restores the previous (leaking) behaviour instantly | Feature-flag the scope for the first deployment |
| **2** — backfills | **Partially.** Quarantine is recoverable by design; a wrong positive attribution is not, which is why quarantine is the default | Single rehearsal, then a reversible migration per step |
| **3+** — extractions | **Yes before the first tag, no after.** After a tag, demotion breaks every consumer and the honest move is deprecation | See §2 |

Two asymmetries drive the whole plan:

**Scoping first may temporarily hide rows from their rightful owner. That is recoverable. Continuing to show them to the wrong merchant is not.**

**Quarantining a row that turns out to have an owner costs an operator query. Assigning a row to the wrong merchant costs a data-isolation incident that the scope then enforces and hides.**

---

## 4. What is outside this plan

- **The 105 modules themselves** — the execution epics.
- **The deferred CMS and CRM code** ([#942](https://github.com/liberusoftware/ecommerce-laravel/issues/942), [#943](https://github.com/liberusoftware/ecommerce-laravel/issues/943)). It moves when those products have repositories, not on a date this plan can name.
- **The five out-of-scope flavours** — `react`, `vue`, `nuxt`, `flutter`, `react-native`.
- **Adding a locale.** `en` only; adding a language is product scope. The RTL machinery in `TRANSLATIONS.md` and `THEMES.md` §18.1 stays unexercised as a result, which is recorded as a deliberate deferral rather than an oversight.
