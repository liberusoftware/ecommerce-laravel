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
| ~~Generate **badge versions from `composer.lock`**~~ — ✅ **verified instead, see below** | `REPOSITORIES.md` §6.1 forbids hard-coding a version CI does not verify. The README hard-codes PHP 8.5, Laravel 13, Filament 5, Livewire 4 |
| `package-testbench` upstream contribution — **timeboxed** | The boundary-rule architecture tests belong upstream so every module gets them. Fall back to `commerce-testbench` if it stalls |
| `spatie/laravel-permission` `^8.0` support upstream in `roles-permissions` | This repo is on `^8.3`, the reference app on `^7.0`. **Downgrading a security-relevant dependency to match a module is the wrong direction of travel** |
| ~~Vendor rename `liberu-eccommerce` → `liberusoftware`~~ — ✅ **done, with one step left outside this repository** | Free today — 0 downloads, 0 dependents. It stops being free the moment anything depends on it. [ADR 0009](./adr/0009-vendor-rename-to-liberusoftware.md), which is also corrected: the package **does** have five published tags, and that is what leaves a Packagist step for a maintainer — [#1000](https://github.com/liberusoftware/ecommerce-laravel/issues/1000), which needs maintainer rights this repository cannot grant itself |

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

### The README's versions — checked rather than generated

The standard forbids hard-coding a version CI does not verify, and the README hard-codes four: PHP, Laravel, Filament and Livewire, in badges at the top and again in prose. Fifteen claims in all, spread over five sections — the badge, the strapline, the About paragraph, the feature list and the requirements line. A README is the first thing anyone reads and the last thing anyone updates, so a stale claim outlives the upgrade that invalidated it, and the reader debugs against the wrong framework.

**Generating them was the plan's wording; verifying them is what the standard asks for, and it is the cheaper half.** A generator is a script, a commit step and a way for the two to disagree — machinery to spare a four-character edit that happens once per major upgrade. `ReadmeVersionsTest` reads every `Name version` and `Name-version` in the README and checks each against `composer.lock`, segment by segment, so `Laravel 13` and `Laravel 13.23` both pass and `1` is not accepted as a prefix of `13`. When it fails, the fix is to type the new number.

It catches the prose too, which is where the drift actually happens: nobody forgets the badge at the top, and everybody forgets the sentence in the middle.

The eight GitHub links pointing at `liberu-ecommerce/ecommerce-laravel` are corrected in the same change. They resolve — GitHub keeps a redirect after a rename — which is why nobody noticed, and a redirect is a courtesy rather than a guarantee.

### The first architecture test, ahead of the enforcement layer

[#924](https://github.com/liberusoftware/ecommerce-laravel/issues/924) was `Jetstream\HasTeams` and `Spatie\HasRoles` both declaring `teams()` on `User`. PHP treats an unresolved trait collision as a **fatal at class declaration**, not a warning at the call, so the application did not boot. The `insteadof` that fixes it landed in wave 0's first pass; what did not land was anything that would notice the next pair. Two traits arriving on the same model is ordinary.

The same fatal has a second source, met while wiring `TrustHosts`: an override that narrows a parameter type the parent left untyped. Also fatal at declaration, also silent until something touches the class. In CI it surfaced as `Premature end of PHP process` on whichever test loaded it first — a crash site that *moves* as the suite changes, which is what made it expensive to find.

**A fatal cannot be caught**, so it cannot be asserted in-process: the assertion dies with the process making it. `EveryClassLoadsTest` declares every class under `app/` in a subprocess instead, printing each name *before* loading it, so the tail of the output ends on the class that killed it. A catchable `Throwable` is swallowed — a missing parent is a broken reference, while a fatal is a broken deployment, and only the second one is being looked for.

It reads the filesystem rather than Composer's classmap, because the classmap is generated by a command that loads nothing: a class that cannot be declared is still listed in it.

**It failed on its first run**, which is the argument for it. `App\Http\Livewire\CreateTeam` overrode `Jetstream\CreateTeamForm::createTeam(CreatesTeams $creator)` with a no-argument `createTeam(): RedirectResponse` — dropping a required parameter, fatal at declaration. Nothing referenced it: the Blade view renders Jetstream's `CreateTeamForm` directly, both panels register `App\Filament\*\Pages\CreateTeam`, and its redirect targets a `filament.pages.edit-team` route from an arrangement that no longer exists. Deleted rather than repaired — a signature fixed on a class nothing can reach is a class nothing can reach, with a longer diff.

That is the shape of what this test catches: not code that is wrong when it runs, but code that stops anything else running the moment it is touched.

This is an architecture test arriving before the enforcement layer that will own it, for the same reason the wave 0 quick wins did — it depends on nothing and the fault it guards is live.

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

**1. Create the schema and resolve merchants.** — ✅ *schema, models, resolver, middleware, the 404, and `TrustHosts` reading the same table*

- `stores`, `channels`, `channel_domains` — many hostnames per channel, one flagged **primary** for canonicals. A storefront realistically answers on the apex, `www`, a custom merchant domain and a platform subdomain on day one; a single `domain` column pushes apex/`www` handling into web-server config the application cannot see, so the canonical the app generates and the host the request arrived on can disagree.
- `Channel` gains a **theme reference**, defaulting to `theme-ecommerce`. One storefront, theme selected per resolved channel; per-merchant themes are later children with `parent: ecommerce`.
- Host middleware resolves **host → `Channel` → `Store` → `Team`**, for the web and API route groups alike. The GraphQL resolver context carries the same resolved channel.
- **`TrustHosts::hosts()` derives its list from the channel domains** and caches it. Resolving tenancy from the `Host` header makes it security-relevant, and two lists of the same hostnames drift — the failure when they drift is either a live storefront returning 404 or a host resolving that should not.

**What landed, and what deliberately did not.** `stores`, `channels` and `channel_domains` exist, with `Store`, `Channel`, `ChannelDomain`, `ChannelResolver` and a `ResolveChannel` middleware on the `web` and `api` groups — so every storefront and API request already carries its channel. A second migration creates the initial store, channel and hostnames from `APP_URL`, plus `localhost` and `127.0.0.1`.

**The 404 did not land, on purpose.** A control that refuses unconfigured hosts, shipped into environments where no host is configured yet, takes every storefront down at once — and before the tenant scope exists it guards nothing anyway. Data first, control second, which is the same order wave 2 uses for the backfill. It flips in the same change as step 3.

That initial-channel migration is not the fallback this wave rules out. A fallback answers for hosts nobody configured; this configures the host the deployment already answers on, which on a single-store deployment is the whole truth. It refuses to run if any store or channel already exists, so it can never claim hostnames from a deployment set up by hand.

**`TrustHosts` now reads the channel domains, and is switched on.** It had been commented out of the global stack entirely, which trusts every `Host` header there is. Two lists of the same hostnames drift, and both directions of drift are outages — a live storefront answering 400, or a host trusted that resolves to nothing — so there is one list, and it is the table `ChannelResolver` already reads.

Three things it does not do, each on purpose:

| | |
| --- | --- |
| It does not drop `allSubdomainsOfApplicationUrl()` | That is what answers between deploying and running migrations, and on a deployment whose panel sits on a hostname no storefront resolves. It widens what is **trusted**, not what **resolves** — `ResolveChannel` still 404s a host belonging to no channel. |
| It does not apply to `/health` | The same exemption the 404 has, for the same reason: a probe arrives on the pod's own address, and refusing it restarts healthy pods. It generates no URLs and reads no tenant data, so a forged header reaches nothing. |
| It does not fail closed on a broken database | The read is wrapped, and an unreachable or unmigrated database trusts what it did before there was a table to read. This runs in front of every request, and failing closed means a deployment that cannot serve the page saying it is broken. |

Hostnames are quoted before they are anchored. Symfony matches trusted hosts as patterns, and an unescaped `.` matches any character — `shop.example.com` unquoted trusts `shopxexample.com`, which somebody can register, point here, and collect password-reset links from.

The list is cached and cleared by `ChannelDomain` itself on save and delete, rather than by whoever remembers to. **Mass deletes bypass it**, as they bypass every model event: `ChannelDomain::query()->delete()` leaves the removed hostname trusted until the cache is cleared some other way. Nothing in the application does that today; it is worth knowing before something does.

**2. Backfill `store_id` alone.** — ✅ **done**

On a today-single-store deployment this is a constant, so it needs no rehearsal — which is exactly why it could be separated from wave 2 while that wave still had a rehearse-once discipline to break.

It was **derived from `team_id`** rather than written as a constant. The 16 tables that carry `team_id` gained a nullable `store_id`, filled from the row's own team; every team without a store got one first. On a single-team deployment the two approaches agree, and on a deployment that already has several teams the constant would have handed one merchant's rows to another. Rows with a null `team_id` keep a null `store_id` — they belong to nobody rather than to whoever sorts first.

The stores created for the other teams have **no channel**, so no hostname resolves to them. A merchant whose storefront has not been configured is unreachable, rather than served from somebody else's domain.

`teams`, `team_user`, `team_invitations` and `stores` are excluded: their `team_id` is the membership graph itself, Team-grained by definition.

**3. Ship the tenant scope.** — ✅ *both modes, every store-grained model, and a ratchet holding the sweep in place*

One global scope with two modes, rather than two scoping systems that must agree:

| Context | Scope |
| --- | --- |
| Storefront (channel resolved) | `where('store_id', $resolvedStore)` |
| Panel (no channel, tenant known) | `whereIn('store_id', $tenantStores)` |

Panels keep `->tenant(Team::class, …)`. Switching them to `Store` tenancy would break every non-commerce resource, which is legitimately `Team`-grained; adding a store filter per resource is the original failure — scoping at the caller, remembered 105 times.

**This step closes [#939](https://github.com/liberusoftware/ecommerce-laravel/issues/939), [#950](https://github.com/liberusoftware/ecommerce-laravel/issues/950) and [#952](https://github.com/liberusoftware/ecommerce-laravel/issues/952) at once.**

**What landed first.** `IsStoreScoped` on `Product`, `ProductCategory` and `ProductCollection` — the catalogue, which is what all three issues name first and what the sitemap publishes. With it, the 404: an unconfigured hostname is an unscoped one, so refusing it is half the control rather than a separate rule. `/health` is the one exemption — a Kubernetes probe arrives on the pod's own address rather than a configured hostname, reads no tenant data, and 404ing it restarts healthy pods.

**Writes stamp too, or the read scope is a bug.** A product created in a panel resolves no host, so nothing would set its `store_id` and it would vanish from the storefront that sells it. `StoreContext::forWrites()` uses the resolved store, and off a storefront falls back to *the only store when there is exactly one* — not a guess but the whole truth on a single-store deployment. With several stores and no resolved host the row is left unstamped rather than attributed to whichever sorts first.

**Orders and customers followed, with two exemptions.** Checking their read paths first is what the step called for, and it found two places where the request's host says nothing about which store the work is *about*:

| Path | Why the host is the wrong answer |
| --- | --- |
| Inbound payment webhooks | Stripe posts to one configured endpoint. That hostname resolves to whichever store owns it, never to the store the charge belongs to. Scoped, a confirmation for any other store finds no order, takes the `null` branch and returns 200 — money captured, order left pending, nothing anywhere saying so. |
| Subject access and erasure | Both are about a person, not a storefront, and both are reachable over HTTP from a storefront that resolves a store. A scoped export returns one merchant's slice and presents it as the whole record; a scoped erasure misses rows and still reports success. |

`StoreContext::acrossAllStores()` suspends the scope for the duration of a callback, rather than `withoutGlobalScope('store')` at each query. These paths read through relations — `$user->customer`, `$user->wishlist()` — that no call-site opt-out reaches, and every model added to the scope later would need remembering again at each of them. **That is the failure the scope exists to stop, and an exemption written the other way would reintroduce it inside the fix.**

`store_id` is not fillable on any scoped model. The trait's `creating` hook is its only writer, so no request can post its way into another store.

**Then the checkout path — coupons and carts.** A coupon is a merchant's money, and the lookup was by code alone against a table every merchant shares, so a code issued by one merchant discounted baskets at another. A cart is the same defect, quieter: items added on one storefront appearing on another means a shopper checks out a competitor's basket.

`exists:products,id` in the cart's request rules still spans every merchant — **validation rules do not run through Eloquent, so no global scope reaches them.** The model lookup behind the rule does, which is what turns adding a foreign product into a 404 rather than a cart row pointing at something this storefront does not sell. Worth remembering wherever an `exists` rule is the only check.

**`coupons.code` is globally unique**, so no two merchants can issue the same code today. The scope is right regardless; the index grain is wrong and belongs with wave 2's other grain corrections, not here. *(Corrected there — the code is unique per store now, and the queries that identified a coupon by code alone had to move with it.)*

**The sweep finished, and is now checked rather than trusted.** Articles, reviews, ratings, invoices, wishlists, groups and downloadable products took the scope; their read paths all run through storefront requests, so none needed an exemption.

Two do not take it, and the reasons are recorded next to the rule rather than in a commit message:

| Model | Why not |
| --- | --- |
| `PaymentMethod` | A shopper's saved payment method belongs to the **person**, not the merchant. The column is there because a blanket migration put one on every table with a `team_id`, not because the data is store-grained. Scoped, their card would vanish the moment they shopped on another storefront. |
| `Channel` | Resolving a channel is what *produces* the scope. Scoping channels by the store a channel resolves is circular — nothing would resolve, so nothing would ever be in scope. |

`images` has no model, so there is nothing to scope.

**`StoreScopeCoverageTest` is the ratchet.** Scoping at the caller failed because it had to be remembered every time; a sweep across sixteen tables has that same shape one level up, and the model that gets missed is the leak. So it is asserted in both directions — a table with `store_id` whose model is unscoped fails, and a scoped model whose table has no `store_id` fails, that second one being #958 exactly: a query naming a column that is not there, breaking only on the paths nobody tested. The exemption list can shrink; adding to it means writing down why.

**The panel mode closes the step.** Off a resolved host the scope used to be inert, which left the panel to Filament's tenancy alone. That is a real control, and it is why this is a refinement — but it scopes panel *resources*, and a panel is more than its resources: relation managers, widgets, custom pages, and any bare `Model::query()` someone writes there are outside it. The store scope reaches all of them.

The tenant is read as Jetstream's `current_team_id` rather than through the Filament facade. It is the same value — both panels switch it when the tenant changes — it can be asked off a panel, where the facade has no panel to answer for, and it is a column already loaded, so it costs no query. A merchant browsing a storefront is unaffected: a resolved host answers first, and an unresolved one is a 404 before any query runs.

**Every store the team owns, not one of them.** A team may own several storefronts and the panel offers no store selector, so scoping to a single store would hide half a merchant's catalogue from them.

**A team with no store leaves the scope inert.** Nothing to scope by is not the same as scoping to nothing, and the latter blanks the panel of a merchant onboarded before their storefront is configured.

**Writes ask the team first, then fall back.** `forWrites()` prefers the single store the panel user's team owns — with several stores on the deployment it is the only thing that can answer — and drops through to the deployment-wide shortcut when their team owns none. That is not borrowing another merchant's store: **the shortcut only ever answers when the whole deployment has exactly one store**, a single-tenant install, where there is no other merchant to borrow from and the alternative is a row invisible to the one storefront there is. Add a second store and the shortcut goes quiet, so a team that owns several — or none — leaves the row unstamped rather than attributed to whichever sorts first.

The first cut of this got it wrong in the cautious direction: it refused the fallback once a panel team was known, which left every row a store-less team created invisible on a single-store install. CI caught it as two API failures, which is the shape this class of mistake takes — the scope reads correctly and the data quietly stops arriving.

**The surfaces are covered at the surface.** [#950](https://github.com/liberusoftware/ecommerce-laravel/issues/950) and [#952](https://github.com/liberusoftware/ecommerce-laravel/issues/952) name the anonymous GraphQL endpoint and the Blade storefront, not the models, and a scope nothing exercises through the reported surface is a scope the next refactor removes. `/api/graphql` is now driven the way a caller drives it — real `Host`, no token — across the listing, `search`, a known id, and the nested `collections { products }` read, which reaches `Product` through a pivot and so is the path a caller-side fix would have missed. A `collection_items` row pointing at another store's product is a mis-stamped row, not permission: the nested read returns nothing for it.

**The availability defects recorded on #950 close with it.** `collections`, `Collection.products` and `orders` returned unbounded lists — depth and complexity rules bound the *shape* of a query, not the row count, and `throttle:api` caps request count rather than per-request cost. All three are capped, and the nested products are eager-loaded, which retires the one-query-per-collection N+1 in the same change.

**The execution timeout has landed too, and the number is ten seconds.** It was left open as *"a number nobody has picked"*, which is a reason to pick one rather than to keep the endpoint unbounded: a storefront request that has not finished in ten seconds has already failed the shopper, who is long gone before a browser or CDN gives up, and the bound exists to stop the query holding a worker after they have left. It sits in `config/graphql.php` alongside the depth and complexity limits — one place for the bounds on a public endpoint — so a deployment that knows its own queries can tighten it without a release.

The deadline is checked between resolvers, and wrapped over **every** resolver in the schema rather than the default one: most of the expensive fields here have their own, so guarding only the default would bound the cheap half. Exceeding it produces a GraphQL error and a 200, not a 500 — the caller gets a reason and whatever resolved. What it cannot do is interrupt a single statement already in flight; that is a statement timeout on the database connection, and a different control.

**4. Rebuild the sitemap per channel.** — ✅ *scoped by step 3, then canonicalised and bounded*

One sitemap per resolved storefront, listing only that store's products. **Which** products it lists was settled by the store scope in step 3 and is covered by `StoreScopeTest`; what was left is the two questions scoping does not answer.

**Which hostname the URLs are written on.** A storefront answers on several hostnames from day one — the apex, `www`, a custom merchant domain, a platform subdomain — and `route()` builds from whichever the crawler used. Two hostnames then publish two sitemaps naming the same pages by different absolute URLs: duplicate content, announced to the crawler in the one file whose whole job is telling it what to index. `Channel::primaryDomain()` had been written for exactly this and used nowhere; it is what the URLs are built on now. The scheme stays the request's, because a deployment behind TLS termination reports it through `TrustProxies` and a hard-coded one would publish `http` URLs from an `https` storefront.

**How many URLs there are.** `Product::all()` was unbounded. The sitemap protocol's ceiling is 50,000 URLs per file and a crawler is entitled to ignore a file that exceeds it, so at 60,000 products the old sitemap did not merely render slowly — it published something that need not be read at all. The budget is spent in listing order, with the home page reserved first: a sitemap that omits the site is worse than no sitemap. `sitemap.max_urls` overrides the ceiling for a deployment that wants to sit further under it.

Rewritten rather than moved — the fix rebuilds it anyway, and it stays in the host as pure cross-module aggregation.

**5. The panel's own tenancy, which turned out not to be running.** — ✅ *#958, answered and closed*

[`CONFORMANCE.md` §6.4](./CONFORMANCE.md#64-six-of-forty-seven-tenancy-pairings-are-coherent) left one question open, because the two answers carried different severities and there was no database here to choose between them: `DiscountResource` and `MenuResource` sit in a Team-tenanted panel on tables with no `team_id`, so either the page raises an unknown-column error, or Filament skips the scope and both list every merchant's rows.

**It was the second, and not only for those two.** The question is answerable in CI rather than by hand — sign in as one merchant, create a row for another, and assert the panel does not show it. No test in this repository had ever asked it: both panel tests asserted that pages *respond*, which is the broken half of the question and passes cleanly while the leaking half is true.

The cause is not the missing column. `$isScopedToTenant` is declared by Filament's `BelongsToTenant` trait on `Filament\Resources\Resource`, so **every resource that does not redeclare it shares one storage slot.** A single `RoleResource::scopeToTenant(false)` — added so Shield's global role resource would stop raising inside a Team-tenanted panel, and reading exactly like a per-resource opt-out — wrote that slot for everybody. **Tenant scoping was off across both panels**: products, orders, customers, invoices, articles, collections, groups, reviews, ratings, coupons and categories all listed every merchant's rows to every merchant. That is #939 again, at the panel, and the panels looked healthy the whole time because with the scope never registered nothing ever emitted the query that would have named a missing column.

| Fix | |
| --- | --- |
| Shield's role resource | Published into the app, where the exemption is a **declared property** with its own slot. Its four pages come with it, since a page names its resource in a static property |
| `discounts`, `menus`, `menu_items` | Given the `team_id` their models had been promising, with no `default(1)` — that default is what wave 2 exists to unpick. Existing rows go to the only team when there is exactly one, and are left for a human otherwise |
| `ChatConversation`, `Page`, `TaxClass` | The same declared opt-out, each with its reason: no `team_id`, no `team` relation, and genuinely shared — a conversation belongs to the person having it, CMS content leaves this repository under [#942](https://github.com/liberusoftware/ecommerce-laravel/issues/942), and tax classes are jurisdiction data every merchant reads |
| `menu_items` | Takes its team from its menu on write. Filament scopes with `whereBelongsTo` on the model itself, which no relation through the parent satisfies, and the menu builder page creates items outside the resource |

**The ratchet asks it the only way that works.** Not *is this resource tenant-scoped* — an unscoped resource may be deliberate — but *if it is not, did this class say so*, by checking that the property is declared on the resource itself. That is the only form that distinguishes a written-down exemption from somebody else's side effect, and it covers both panels, because the slot they share is one slot. Both panel tests now go over HTTP: the scope is registered when the panel boots, the panel boots in middleware, and `Livewire::test` skips all of it.

Three tables took `team_id` and not `store_id`, against this wave's own rule, with the reason recorded next to the exemption: the menu builder's storefront component queries `Biostate\FilamentMenuBuilder\Models\Menu` **by class name**, not the model this application configures, so a store scope on `App\Models\Menu` would control the panel and leave the storefront reading exactly what it reads today. Per-storefront navigation is a product change and sits with wave 2's grain corrections, alongside the `coupons.code` grain that has since been corrected.

### Rules this wave establishes

- **An unresolved host is a 404.** No default-merchant fallback. Single-merchant deployments and local development configure their one channel's domain, `localhost` included. *A configured fallback is exactly how `default(1)` produced the mess wave 2 is unpicking.*
- **A token is checked *against* the resolved channel, never *instead of* it.** Two mechanisms that can disagree means every disagreement is a potential leak.
- **Not a mismatch:** a shopper authenticated at merchant B arriving on merchant A's domain is legitimate traffic. `customers` belongs to a `Team`, and one `User` may hold customer records at several merchants.
- **Order history scopes to the resolved store.** The data is the shopper's, but the surface belongs to the merchant — otherwise merchant A's support staff viewing a customer's account see what that person bought from a competitor.

### Also in this wave

~~Add `team_id` to the **31 tables whose models declare `IsTenantModel` but whose tables lack the column**~~ ([`CONFORMANCE.md` §6.4](./CONFORMANCE.md#64-six-of-forty-seven-tenancy-pairings-are-coherent)) — ✅ **resolved, and it was two questions rather than one.**

The item read as a single backlog of 31 columns. It is not. `IsTenantModel` is a `team()` relation and nothing else, so a model using it against a table with no `team_id` is a claim of ownership with nothing behind it — and there are two different reasons a model ends up in that position, with opposite fixes.

| | |
| --- | --- |
| **3 were live** | `Discount`, `Menu`, `MenuItem` — queried by a tenant-scoped Filament resource. They got the column, and the leak they were part of is step 5 above |
| **20 were children** | `ProductVariant` belongs to a product, `GiftCardTransaction` to a gift card, `InventoryLevel` to an inventory item, `GiftRegistryPurchase` to a registry item. Their owner is their parent's, so the **trait** is the thing that is wrong. Dropped |
| **8 are roots** | `ABTest`, `CartRecoveryCampaign`, `CustomerGroup`, `CustomerSegment`, `InventoryLocation`, `RecommendationRule`, `SeoSetting`, `TaxonomyCategory` — merchant-owned with no tenant-owned parent, so a column is the only way they could ever be tenanted |

**The eight keep the trait and stay on the ratchet, without the column.** Nothing reads them through a panel or a scope, so adding eight columns today buys eight columns nothing writes and nothing filters — and a nullable tenant key that nothing fills is how `default(1)` got its reputation. Each gets its `team_id` when something needs to ask whose it is, which is exactly how `discounts` and `menus` got theirs.

Two of the twenty are worth naming separately, because they are not children of a merchant's data at all: `GiftRegistry` and `CustomerMetric` hang off a `User`. A shopper's registry belongs to the person, like `PaymentMethod` — the same reasoning that keeps `PaymentMethod` out of the store scope.

Nothing read `->team` on any of the twenty; the only readers in the tree are `Store` and a test.

---

## Wave 2 — ~~the backfill wave~~ the wave that stopped being a backfill

**The premise is gone.** This wave was three data migrations, one rehearsal against a production-shaped copy, a quarantine rule, and a gate on [#944](https://github.com/liberusoftware/ecommerce-laravel/issues/944) — a query checklist somebody had to run against each real environment.

All of that existed for one reason: **rows that already exist and cannot be attributed.** Every `team_id = 1` row was unverified because the column carried `default(1)` and no application code wrote it, so a row created by the API, a controller, a seeder or a factory became team 1 without anybody deciding that — and afterwards nothing could tell those rows from rows that really were team 1's. Quarantine, rehearsal and the checklist are all answers to *"which of these existing rows can we prove anything about?"*

**There are no such rows.** The application is pre-production: every database is built from the migrations, and nothing has to be migrated *from*. So the question is not which rows to attribute, it is why the schema allowed an unattributed row in the first place.

### What replaced it

| Was | Is |
| --- | --- |
| Backfill `team_id`, quarantine what cannot be attributed | **`default(1)` deleted from the migrations, and `IsTenantModel` writes the key on create** — derived from the store, which belongs to exactly one team. A row nothing can attribute is left null, which an operator can see and fix |
| Run #944 against each environment before migrating | Nothing to run it against. The report itself stays — it is how a database gets checked rather than assumed — but it gates nothing |
| One sequenced data migration, rehearsed once | Migrations edited where they are wrong. There is no deployment to upgrade, so a correction belongs in the migration that created the fault |

`team_id` stays **nullable**, and that is not the old default in disguise. Null means *nobody said*, which is a true statement about a row created by a console command with no store and no panel; the fault was never nullability, it was a default that answered the question on the row's behalf.

### What genuinely remains, and is not a backfill

Two items from this wave were never really about attributing existing rows, and they survive as ordinary work:

- ~~**The reviews and ratings merge** — [ADR 0008](./adr/0008-reviews-and-ratings-merge.md).~~ — ✅ **done.** `ProductReview` and `ProductRating` won; `Review`, `Rating`, their tables and their factories are gone.

  **`approved` was ported**, which was the ADR's whole reason for existing: the retiring stack held the moderation flag, the surviving one had none, and a merge that simply dropped the loser would have published every review on arrival. Nothing in that diff would have said so — the tests that pass are the tests that no longer exist — so the column arrives with a schema test naming it, a factory that is unapproved by default, and a `approved()` scope the public listing goes through.

  **The `Customer` backfill moved from a migration to the write path.** It was going to be a migration because reviews already existed keyed to a `User` with no `Customer`; with no rows, the same requirement lands where a review is created — `getOrCreateCustomer()`, which already existed for exports. A shopper who has never had a customer record gets one rather than having their review dropped as unmappable.

  Two things fell out that the ADR did not name. The star rating on a review is a *rating* now that ratings are their own record, so a review writes both — `firstOrCreate`, so a breakdown the shopper already left is not flattened to one number. And the panel had no moderation surface at all: approving was an HTTP endpoint and nothing else, which is a queue with no queue. The review resource now shows the flag, filters on it, and can set it.

  **A footnote the merge left behind: `resources/views/reviews.blade.php` is gone.** No route rendered it — `ReviewController::show` returns JSON — and it could not have rendered if one had: it read `$review->rating->overall_rating` against a model with no `rating` relation, in Bootstrap 4 markup, driven by a jQuery this application does not load. It was a storefront review page that never existed, and a dead template is worse than no template, because the next person to want that page starts by fixing this one instead of asking whether the merged models still make it the right shape.
- ~~**The cart `'api'` session sentinel**, which is a code fix in the cart's identity handling.~~ — ✅ **done, by deleting the column rather than the sentinel.**

  `cart_items.session_id` was `NOT NULL`, written by every path and read by none. `user_id` on the same table is a **required** foreign key, so a cart item never belonged to a session in the first place — guests are not persisted at all. The API and the GraphQL mutation, having no session and no way to leave the column empty, wrote the literal string `'api'`: one identity shared by every API client, sitting in a column shaped like an identity. Nothing scoped by it, which is the only reason it was not a leak — the same "not a leak yet" that `default(1)` was.

  Repairing it would have meant inventing a truthful value for a column nobody reads. `abandoned_carts` keeps its own `session_id` and the contrast is the point: an abandoned cart is usually a guest's, so there the session really is the identity.

And the grain corrections this plan has been collecting, which are now edits to the migrations that got the grain wrong rather than corrections layered on top:

- ~~**`coupons.code` is globally unique**, so no two merchants can issue the same code. `discounts.code` has the same fault.~~ — ✅ **done**, and the uniqueness turned out to be the smaller half.

  The `->unique()` is gone from both create migrations and `2026_08_09_000002` adds the composite: `(store_id, code)` on coupons, `(team_id, code)` on discounts. The grain differs because the models do — discounts are team-scoped and deliberately not store-scoped yet, so team is the finest grain their schema can express today, and the constraint moves when that does. Null owners collide freely, which is what SQL does with NULLs in a unique index and also what is wanted: a row nothing can attribute is not sellable, because nothing resolves a store for it.

  **The half that was not about the index:** once two merchants hold `SUMMER10`, every query that identifies a coupon *by code alone* crosses the boundary — and `max_uses` is derived from exactly such a query. `Coupon::orders()` matched orders on `coupon_code` and nothing else, and `CouponService::getActiveCoupons()` joined `orders` on the same column; the store scope reaches `coupons` and not the table joined to it. Left alone, a competitor's customers would spend a merchant's coupon and withdraw it from their own storefront. Both now carry the store, and the two tests that would have caught it are the two that matter most in that file.

  A global unique index is not a constraint that was merely too strict. It reads as correctness and behaves as a land grab: the first merchant to issue a code takes it from everyone else, and finds out through a database error on a form that could not have known.

### Why this no longer gates wave 3

It gated tier-1 extraction because *"every module extracted over mis-attributed rows inherits the problem into its own migrations and tests"*. With no mis-attributed rows, there is nothing to inherit. **Wave 3 is unblocked.**

---

## Wave 3 — tier 1, most-code-first

**Catalog**, then **Pricing**, then **Inventory Ledger**.

Catalog first: it has the largest existing footprint, and it is where the god model lives. `Product` stays whole in Catalog; Pricing and Inventory Ledger extend it through their own tables keyed by product id, never by adding columns or relations to a model they do not own.

Prerequisites specific to this wave, from [`CONFORMANCE.md` §5](./CONFORMANCE.md#5-duplicate-stacks):

- ~~The **tax merge** (`TaxCalculator` wins, after diffing `TaxService`) lands before Pricing.~~ — ✅ **done, and the diff was the point.**

  `TaxService` was referenced by nothing but its own test, which is exactly why it could not be deleted unread: nobody had checked whether it held a rule the live engine lacked, and deleting tax logic unread surfaces a quarter later in a VAT return. It held one, in a single test — **tax lands on the amount after a cart discount**. `TaxCalculator` had no notion of a discount at all.

  The rule was already live, and in the wrong place: both checkouts computed a pro-rata discount factor themselves, identically, in the six lines before the call. So the merge moved it into the engine as a `$discount` argument, and both call sites lost their copy. A tax rule living at two call sites is a rule that will eventually live at one.

  **Pro-rata rather than `TaxService`'s flat subtraction**, which took the discount off one blended subtotal — correct only while every line shares a rate. The moment a cart mixes a standard-rated and a reduced-rated item, the answer depends on which line the discount is deemed to have come off, and the engine now shrinks every line by the same proportion. Untaxable lines count in the denominator: the coupon was given against the whole cart, and leaving them out concentrates the discount on what remains and under-taxes it.

  Rejected rather than ported: `parseAddress`, which regex-guessed a country, state and ZIP out of a free-text address string and **defaulted the country to `US`** — a guess wearing a lookup's clothes, against an engine that already takes a structured address. `getTaxDetails` was superseded by `calculateCartTax`'s `lines`, which are grouped and compound-aware. `calculateTax(amount, country, …)` had no caller and no tax class.
- ~~The **cart merge** lands before the Cart module, which is the tier after this one.~~ — ✅ **done.** One store, one door.

  A guest's cart lived in the session as a plain array; an account's lived in `cart_items`; `CartService` mirrored one into the other on login and on every write; the API and the GraphQL mutation wrote `cart_items` and never saw the session at all. Two stores that can disagree, and **the web checkout charged from the session copy** — the one no other surface could read. A shopper who filled a cart through the API and checked out on the web was charged for a different cart than the one they filled.

  Everything writes `cart_items` now. `cart_items.user_id` became nullable and `guest_token` joined it: exactly one is set, and on login the guest's rows are folded into the account's — quantities combined, since a shopper who added two signed out and one signed in wanted three — and the token dropped, so the next guest on that browser does not inherit a cart.

  `guest_token` is deliberately **not** a session id. A session id is a credential, and this column is read by staff tooling and abandoned-cart jobs. It is also not the `session_id` column deleted a few commits earlier: that one was written by every path and read by none, which is what made the API's `'api'` sentinel possible. This one has one writer, one reader, and a constraint.

  A consequence worth naming: the cart is store-scoped like everything else in wave 1.5, so a guest's cart no longer follows them between merchants' storefronts. That was the defect this plan describes as *"items added on one storefront appearing on another means a shopper checks out a competitor's basket"* — it was only ever half-fixed, because the session copy was never scoped by anything.

  **The merge also surfaced a bug it did not cause, and a follow-up finished it off.** `CartItem`'s product relation was named `products()`, and Eloquent derives the foreign key from the method name: `products` gave `products_id`, a column that does not exist. A missing key attribute reads as null rather than erroring, so eager loading quietly returned no product and every caller read that as *"this line has no product"* — the REST cart returned null products, the GraphQL cart resolved `product: null`, and `HeadlessCheckoutService` skipped every line when calculating tax. The merge pinned the key explicitly and left the plural name, because a rename mid-merge is a rename nobody can review; the relation is `product()` now and the pinned key is gone, since a correctly named `belongsTo` derives it.

  Two things that only showed up on the second pass. The docblock's *"four call sites"* was an undercount — `Api/CartController` held three more, in `with()` and `load()` strings that no `->products` grep finds. And `GraphQLStorefrontTest` **already selected `product { name }` and asserted nothing about it**: the original bug shipped through a test that queried the exact broken field. Both the GraphQL test and a new cart-line test now assert the product itself, because a relation that fails by returning null cannot be guarded by a test that only checks nothing threw.

  Client-visible, and deliberate: the REST cart serialises the loaded relation, so `data.products` in the JSON is `data.product`. It had been null for the entire life of the endpoint until the merge fixed it a few commits earlier, and this is pre-production, so the key is renamed rather than aliased.
- ~~The **recommender rename** lands with Recommendations.~~ — ✅ **done, and the pair is not what `CONFORMANCE.md` §5.1 recorded.**

  The snapshot calls it *"a read/generator split of one module"*. It is not. Both read, only one writes, and they read different things: the short service derives suggestions at request time from **one shopper's own** orders, browsing and ratings, and stores nothing; the long one captures interactions, builds `product_recommendations` from **cross-customer** co-occurrence, and serves the stored set back. Two recommenders over two signals, not two halves of one algorithm — and of the long one's nine methods exactly one generates.

  So the obvious `…Reader` / `…Generator` pair would have been a fresh lie in both directions: the "reader" is the one that computes from scratch, and the "generator" is mostly reads. They are `UserHistoryRecommender` and `ProductRecommendationEngine`, because the question a caller actually has is *whose behaviour is the signal* — this shopper's, or the crowd's.

  **`UserHistoryRecommender` has no live caller.** `Frontend/ProductController` injects it, but the only call sits in a commented-out block alongside a commented-out `BrowsingHistory::create` — so the whole personal read path is dormant, and its test asserts nothing but that the container can build it. §5.1 said keep both, on a characterisation that turned out wrong; whether a dormant recommender is worth keeping is a decision for Recommendations, not for a rename.

  Left standing deliberately: `BrowsingHistory` and `ProductInteraction` rows of type `view` record the same fact in two tables, and both services run a same-category "similar products" query. Real duplication, but a rename that also refactors is a rename nobody can review.

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
| **2** — schema corrections | **Yes.** It stopped being a data wave: there is no production data to get wrong, so what is left is migrations and code | Revert the commit and rebuild the database |
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
