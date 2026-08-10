# Migration plan

How `ecommerce-laravel` gets from the state recorded in [`CONFORMANCE.md`](./CONFORMANCE.md) to the shape [`MODULE_DEVELOPMENT.md`](./MODULE_DEVELOPMENT.md) describes.

**This document is living.** It is edited as waves land and as the execution epics discover things the plan got wrong. `CONFORMANCE.md` is not — it is a dated snapshot, and the gap between the two is the progress record.

**This plan does not build the 105 modules.** It sequences the structural work: the enforcement layer, the packaging mechanism, the tenancy fix, four data migrations, and the extraction order. Building modules is carried by the 105 `Architecture: Ecommerce — <module>` epics, which execute against this plan.

---

## 1. The sequencing rule

**Tier order, then most-code-first within a tier.**

```
ecommerce-commerce-core
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

**No throwaway first extraction.** A small commerce leaf was proposed as a rehearsal — Back-in-Stock and Price Alerts — and that was wrong on the facts: `StockNotification` `belongsTo` `Product`, `ProductVariant` *and* `User`, and `ProductBackInStockNotification` imports `Product` and `ProductVariant`. **Essentially every commerce leaf hangs off `Product`**, so there is no dependency-free leaf to rehearse on. `ecommerce-commerce-core` keeps the rehearsal property anyway (§3).

**No storefront wave.** Each module takes its routes, views and Livewire components when it is extracted — in the same commit as its domain code, because the route name and the view that calls it live together. Only the shared layer (`theme-ecommerce`) is scheduled, in wave 0.

---

## Wave 0 — make a module loadable, and make the rules enforceable

Nothing can be extracted before this wave. A module ships **no `extra.laravel.providers`**, so Composer install boots nothing: `ModuleManagerServiceProvider::register()` is the only registrar in the reference design, and no module can exist until the manager does.

> **Re-checked 2026-08-09: the mechanism this wave waits on is published.** `liberusoftware/composer-installer` is on Packagist at `1.1.0` (`composer-plugin`), `liberusoftware/module-manager` at `1.4.2` with thirteen releases, and `liberusoftware/theme-default` at `1.5.1`. The first two rows below are a `composer require` rather than an open question, and since this wave gates every extraction, that is the gate on the whole sequence. Tracked on [#972](https://github.com/liberusoftware/ecommerce-laravel/issues/972).
>
> **Adopted 2026-08-09.** Both packages are required and locked; `app/Modules/` is deleted. [ADR 0011](./adr/0011-adopting-module-manager.md) is the diff, and it is now accepted rather than proposed. **The gate on the whole sequence is open.**
>
> The blocker this note used to record — *"`composer` hangs here"* — was wrong about the cause and therefore wrong about the remedy. Composer was never hanging and the machine was never offline. PHP's libcurl is built against c-ares, which resolves over UDP; UDP `:53` is black-holed on that network, which is exactly why `/etc/resolv.conf` carries `options use-vc`. glibc honours it and resolves over TCP — so `curl`, `git` and `gh` all work — and c-ares ignores it, so Composer alone fails at name resolution on every host. `.github/workflows/composer-require.yml` runs the require on a runner that has DNS. A blocker recorded by its symptom stayed a blocker for as long as nobody read the error.

| Item | Why it is in wave 0 |
| --- | --- |
| ~~Adopt `liberusoftware/composer-installer`~~ — ✅ **done** | `MODULES.md` §6.1 makes it a prerequisite; nothing installs into `modules/` without it. Locked at `1.1.0`, and allowed in `config.allow-plugins` — a `composer-plugin` that is not allowed is silently not run |
| ~~Adopt `module-manager`~~ — ✅ **done** | The only registrar. Locked at `1.4.2`. It replaced `app/Modules/` rather than merely deleting it — see the correction below |
| **Enforcement layer** — `pint.json`, ~~PHPStan at level 8~~ **PHPStan at level 0**, architecture tests, CI gates, Composer scripts | The cheapest item on the map and the one whose value compounds. Landing it after 20 extractions means 20 modules to re-check. **Static analysis landed — see below.** The architecture-test half is module-boundary rules and waits on modules existing |
| Create **`theme-ecommerce`** — `type: public`, `parent: default` | The storefront layout moves **once**, and every later extraction targets an existing theme instead of a moving one |
| Declare **`supported_locales`** in `config/app.php` | The key is absent and `localization-core` reads it. One line, arriving with the localization adoption already committed to |
| Add the **translation lint step** to `package-tests.yml` | A static catalogue check belongs with the enforcement layer, not with the first module that ships a catalogue |
| ~~Generate **badge versions from `composer.lock`**~~ — ✅ **verified instead, see below** | `REPOSITORIES.md` §6.1 forbids hard-coding a version CI does not verify. The README hard-codes PHP 8.5, Laravel 13, Filament 5, Livewire 4 |
| `package-testbench` upstream contribution — **timeboxed** | The boundary-rule architecture tests belong upstream so every module gets them. Fall back to `commerce-testbench` if it stalls |
| `spatie/laravel-permission` `^8.0` support upstream in `roles-permissions` | This repo is on `^8.3`, the reference app on `^7.0`. **Downgrading a security-relevant dependency to match a module is the wrong direction of travel** |
| ~~Vendor rename `liberu-eccommerce` → `liberusoftware`~~ — ✅ **done, with one step left outside this repository** | Free today — 0 downloads, 0 dependents. It stops being free the moment anything depends on it. [ADR 0009](./adr/0009-vendor-rename-to-liberusoftware.md), which is also corrected: the package **does** have five published tags, and that is what leaves a Packagist step for a maintainer — [#1000](https://github.com/liberusoftware/ecommerce-laravel/issues/1000), which needs maintainer rights this repository cannot grant itself |

### Static analysis landed at level 0, not level 8 — ✅ **done**

This plan asked for level 8. The gate runs at **level 0**, whole-tree, with **no baseline**, and the difference is not a compromise so much as a measurement.

`larastan/larastan` is not installed and cannot be — it needs Composer network this environment does not have. Without it PHPStan does not know what Eloquent or the facades are. Measured across `app`, `bootstrap`, `database` and `routes`, one CI job per level:

| level | 0 | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 | 10 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| errors | **26** | 481 | 909 | 934 | 938 | 956 | 2015 | 2065 | **2068** | 2376 | 4380 |

At level 2, **802 of the 909 are two identifiers** — `property.notFound` (*"Access to an undefined property `Order::$id`"*) and `staticMethod.notFound` (*"Call to an undefined static method `Product::where()`"*). That is Eloquent working as designed, and teaching PHPStan about it is precisely what Larastan is for. Baselining two thousand of those to claim level 8 would not be a record of debt; it would be a way of not looking — the real findings buried in the magic, in a file nobody reads. The gate lands where the tool is telling the truth, with nothing hidden behind it, and `phpstan.neon` carries the ladder and the upgrade path in its own comments rather than only here.

**Level 0 was not a token gesture: its 26 findings were, almost to a line, real.**

- **A live bug.** `Team::collections()` returned `hasMany(App\Models\Collection::class)` — a model renamed to `ProductCollection` some time ago. The class does not exist, so the relation fataled on use. `docs/research/standards-gap-audit.md` had already found this **by hand**, and it was still unfixed; the tool found it on day one, automatically, which is the entire argument for the tool.
- **Four classes referencing things that do not exist**, all deleted: `CreateNewUserWithTeams` and `CreatePersonalTeam` both construct an `App\Services\TeamManagementService` that exists nowhere (referenced only from commented-out provider blocks); `EmailTracker` calls `EmailCampaign::find()` and `Lead::find()` — CRM leftovers of the same family as the `ScreeningDataEncryptor` deleted earlier in this wave; `CollectionFactory` carries the same stale name as the relation above.
- **A notification instantiating `NexmoMessage`**, removed from the framework in Laravel 9 — unreachable, since `via()` returns only mail and database.
- **Five PHP 8.4 implicit-nullable deprecations** on a repository that requires PHP 8.5.

Two deliberate choices worth recording. The job runs **whole-tree, not changed-files** — deliberately the opposite of the Pint job beside it, because formatting is per-file and type analysis is whole-program: a changed signature breaks callers the diff never names. And there is **one `ignoreErrors` entry rather than a baseline** — `Undefined variable: $this` in `routes/console.php`, where Artisan binds the closure at runtime — scoped to that message in that file, so a genuinely undefined variable there still fails.

`composer analyse` runs it; `composer check` is lint, analyse and test together. Nothing was added to `require` or `require-dev` — `phpstan/phpstan` was already in `composer.lock`, pulled in by Rector, which is the only reason any of this was possible without network.

### `app/Modules/` is a replacement, not a deletion

This table used to describe `app/Modules/` as *"1,095 lines of unused scaffolding"* to be deleted on adopting `module-manager`. **It is not unused.** `AppServiceProvider` registers `ModuleManager` and `ModuleServiceProvider`, `app/Console/Commands/ModuleCommand.php` drives it, `config/modules.php` points at it, and two test files — `tests/Feature/ModuleStateTest.php` and `tests/Unit/ModuleSystemTest.php` — exercise it.

The rule `MODULES.md:193` states still stands: the manual class scanner is forbidden and this scaffolding goes. But it goes by being **replaced**, and the difference is those two test files. They describe behaviour something currently depends on, so they are the specification of what the adopted registrar has to keep — read before the deletion, not deleted with it. *The tests that pass are the tests that no longer exist* is the failure mode ADR 0008 was written to avoid, and it applies to a registrar as much as to a review table.

They have now been read, against `module-manager` `1.4.2`'s actual source, and the diff is [**ADR 0011**](./adr/0011-adopting-module-manager.md). The short version: the two systems agree on almost nothing except the word "module". The host holds "enabled" in a **database table** and lets it change at runtime; `module-manager` holds it in **configuration** and resolves it once in `register()`. So the adoption drops runtime enablement, the `modules` table and the four lifecycle events — affordable only because **nothing calls them**: `enable()`, `disable()`, `install()` and `uninstall()` have no callers outside `php artisan module` and the two tests. There is no operator surface, which makes it an unexercised code path rather than an operational capability.

The default also inverts, in the right direction. `isModuleEnabled()` returns `true` for a module with no row *and* `true` from its `catch (\Throwable)` — so an unknown module runs, and a database error runs all of them. `module-manager` starts from nothing enabled and makes the host name its selection: the same argument this plan made against `team_id`'s `default(1)`.

**The replacement landed 2026-08-09, and the swap had to be one commit.** Not for tidiness — because there was no safe half-adopted state, and this ADR did not predict it. **Both systems name their config file `config/modules.php`.** The host's declared `'cache' => ['enabled' => …, 'key' => …, 'ttl' => …]`, an array. `ModuleManagerServiceProvider::register()` reads that same key as `(bool) config('modules.cache')` — a non-empty array casts to `true` — and then `(string) config('modules.cache_path')`, which the host config never defined, giving `""`. So installing the package while the host config was still in place would have switched the registry cache on and pointed it at the empty string, in `register()`, on every boot. "Install now, replace next week" was not available.

Worth keeping as a pattern: two implementations of the same idea tend to collide on the *conventional* name, and the conventional name is the one neither author thought worth checking.

One thing the adoption found that nothing had written down: **`composer-installer` is a `composer-plugin`, and a plugin absent from `config.allow-plugins` is silently not run.** Composer does not fail; it declines, prints a notice among a hundred install lines, and the package that was supposed to route `liberu-module` installs into `modules/` simply does not. It is allowed explicitly in `composer.json` now.

**Still open, and deliberately not guessed at.** With `/modules` tracked ([#972](https://github.com/liberusoftware/ecommerce-laravel/issues/972), which withdrew ADR 0010), a `liberu-module` package installed by `composer-installer` lands in the working tree — so `composer install` now produces files that git does not track. `modules/` currently holds only `.gitkeep`. Whether the installed copy is *committed* — which is what `boilerplate-laravel` does, 726 files of it — is a separate decision from *not gitignoring it*, and #972 only settled the second. It needs settling before the first extraction, along with the `git diff --exit-code --stat -- modules themes` guard that makes committing it safe.

### Also in wave 0, because they are one-line and shipping today — ✅ **done**

The small faults that needed nobody's permission. Landed ahead of the rest of wave 0, since none of them depends on the packaging mechanism existing.

Three of these came from the severity table at the foot of [`research/standards-gap-audit.md`](./research/standards-gap-audit.md), whose own summary makes the point that **the most urgent items are not the most severe**: the three it named as *"small, mechanical and shipping today"* were an API endpoint serving every tenant's catalogue to any Sanctum token, a seeder printing a generated admin password into every CI log, and a controller returning webhook signing secrets to every staff user. All three are now closed — the catalogue one not by a fix in the endpoint at all, but by `Product` picking up `IsStoreScoped` in wave 1.5, exactly as `OwnsTeamResources`' docblock predicted it would be.

| Fault | Fix |
| --- | --- |
| `UserSeeder.php:36` printed a generated admin password, and `install.yml:85` runs `db:seed --force` | Printed only under `app()->environment('local')`. Off `local` the password now comes from `config('seeding.admin_password')`, and without one no admin is created — see below |
| `DummyDataSeeder` sat in `DatabaseSeeder`'s baseline chain, so `db:seed --force` created demo data in production | Called only when `! app()->isProduction()`, in the same position |
| `DropxlService.php:23` and `SubscriptionController.php:21` read keys via `env()` in constructors — `null` under `config:cache` | Read `config('services.dropxl.*')` and `config('services.stripe.secret')`. `services.dropxl` added; **no `env()` call remains outside `config/`** |
| `composer.json` declared `minimum-stability: beta` (audit finding 27) | `stable`. **Zero packages in the lock are alpha, beta, RC or dev**, so the loosened constraint admitted nothing `prefer-stable` had not already rejected — a weakened gate with no beneficiary, in the place a contributor reads as permission. Tightening can only remove candidates and none of the removed ones was chosen, so the lock's package list is untouched and only its `content-hash` moves |
| No `CONTRIBUTING.md`, `SECURITY.md`, `docs/index.md` or PR template (audit findings 17 and 28) | Written. The index is the one that earns its place: `docs/` mixes the living plan, a frozen snapshot, eleven ADRs, four research dumps and several documents of unaudited currency, and nothing said which was which |
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

## Wave 1 — the first extraction: `ecommerce-commerce-core` — ✅ **shipped**

Four packages, released and green: [`ecommerce-commerce-core`](https://github.com/liberusoftware/module-ecommerce-commerce-core) `0.4.0`, plus `-api`, `-filament` and `-livewire` at `0.2.0`. 411 tests. [#839](https://github.com/liberusoftware/ecommerce-laravel/issues/839) records what shipped and what deliberately did not.

Two things it leaves open, both outside the packages themselves: **none of the four is on Packagist**, so a consumer needs a VCS `repositories` entry — and Composer honours `repositories` only from the *root* manifest, so a package declaring its own dependency's repository does not help its consumer. And **the host has not swapped yet**: this repository still runs `App\Models\{Store,Channel,ChannelDomain}` and `App\Services\{ChannelResolver,StoreContext}`. That swap is the first thing that will find out whether the boundary is right, which is why the domain package is `0.4.0` and not `1.0.0`.

`ecommerce-commerce-core` is tier 0 and, uniquely among commerce modules, has **no inbound dependency on the god models**. `Store`, `Channel` and the shared value types — money, quantity, address, tax class — are greenfield.

So the first extraction exercises packaging, testbench wiring, migration ownership, panel registration, translation loading and CI **without simultaneously fighting a 99-model graph**. It is small, self-contained, cheap to redo, and it unblocks both tier 1 and wave 1.5.

Its promotion gate is [`MODULE_DEVELOPMENT.md` §6.1](./MODULE_DEVELOPMENT.md#61-promotion-gate--all-provable-inside-the-monorepo). Being greenfield, its coverage floor is `--min=100` — no ADR 0001 ratchet applies.

**What `ecommerce-commerce-core` owns on day one:**

- `Store`, `Channel`, `channel_domains` — the schema wave 1.5 needs
- `ChannelResolver` — the domain question *which channel is `shop.example.com`?* belongs to the module that owns `Channel`. The HTTP question — *how does a request carry it* — stays in the host as middleware
- The shared value types

`ecommerce-commerce-core` is provisioned in all four flavours, so promotion pushes into existing repositories.

**One prerequisite was a contribution rather than a fix, and it turned out not to be a block at all.** ~~[ADR 0006](./adr/0006-late-bound-host-model-resolution.md) has commerce modules resolve host models late and never import them. That needs a **team resolver** in `organizations-teams`, and it does not exist. Until it lands, a commerce module cannot resolve a team — which is a hard block on wave 1, not a nice-to-have.~~ — ✅ **sidestepped, and the sidestep is the better answer.**

`CurrentTeamResolver` did land, in `organizations-teams` 1.4.1, and it is unusable here twice over: it queries `team_user.status`, `effective_from` and `effective_until`, none of which this host has, and it returns *that package's* `Team` rather than the host's. So the extraction took the other route — `Store::team()` reads `config('commerce-core.team_model')` at call time, defaulting to `App\Models\Team`.

Which satisfies ADR 0006 more cheaply than the contribution would have: a module that needs a **class name** does not need a package. The dependency the ADR was written to avoid was never required to avoid it.

It is recorded here because it was previously recorded *only* inside [#961](https://github.com/liberusoftware/ecommerce-laravel/issues/961), the upstream-issue tracker, as the one item of 24 that had never been filed anywhere. #961 is now closed ([ADR 0013](./adr/0013-cms-and-crm-packages-are-built-from-ground.md)), and a wave 1 prerequisite belongs in the wave 1 section rather than in a list of other repositories' bugs.

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

## Wave 3 — tier 1, most-code-first — ✅ **shipped**

**Catalog**, then **Pricing**, then **Inventory Ledger**.

Catalog first: it has the largest existing footprint, and it is where the god model lives. `Product` stays whole in Catalog; Pricing and Inventory Ledger extend it through their own tables keyed by product id, never by adding columns or relations to a model they do not own.

Twelve packages, four per module, all at `0.1.0` and green on Tests, Install and Compatibility. 1,753 tests. [#833](https://github.com/liberusoftware/ecommerce-laravel/issues/833), [#891](https://github.com/liberusoftware/ecommerce-laravel/issues/891) and [#862](https://github.com/liberusoftware/ecommerce-laravel/issues/862) record what shipped and what deliberately did not.

**The three were built concurrently, and that is what tested the boundary rather than asserting it.** Catalog carries no price and no stock — a `SchemaTest` case proves `price`/`inventory_*`/`cost` are absent from `products` *and* `product_variants`, and a read-model case proves the serialised JSON never mentions them. Pricing prices product `987654321`, which nothing in its database has heard of. Inventory's whole suite runs with no catalogue present. Three modules keyed on `products.id`, none able to import the others, none waiting on the others.

**One live bug, found by CI on Catalog's first run.** `availableOn` ignored `hidden`, so a hidden product stayed reachable by direct URL — one fix, in the single scope every reachability question routes through, which is the entire argument for having such a scope.

**One thing open by design.** Catalog's `GET /staff/stores/{store}/products` is scoped by store and nothing else: `ProductQuery::paginate()` scopes on store while the policies scope on team, and `stores` belongs to `ecommerce-commerce-core`, which Catalog cannot depend on. A multi-team deployment must guard the route parameter itself. Stated in six places and pinned by a test named for it, so it fails loudly the day somebody closes it properly.

**A pattern confirmed twice now, and worth carrying into every later module.** A model with no policy is not safe, it is exposed: Laravel's unanswered gate case is permissive. Commerce Core's `ChannelResource` was the first instance; Inventory Ledger's `StockMovement`, `StockLevel` and `StockReservation` were the next three, all un-policied and all defaulting open. Every presentation package now restates the abilities explicitly rather than trusting the absence of a policy to mean anything.

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

After wave 3 the sequencing rule in §1 carries the rest with no further enumeration. **The next tier is Cart, then Checkout, then Orders, then Fulfillment and Returns** — and Cart's duplicate-stack merge already landed, above, so nothing gates it but the work.

Sixteen packages exist across the first four modules and **none is on Packagist**, which is now the single blocker with the widest reach: it is what keeps the host running its own `Store`, `Channel`, `Product` and the rest, and the host swap is the first thing that finds out whether any of these boundaries is right. Tracked on [#1000](https://github.com/liberusoftware/ecommerce-laravel/issues/1000).

---

## Wave 4 — tier 2, and the tier edge that turned out not to be one — ✅ **shipped**

**Cart** and **Checkout**, built concurrently.

Eight packages, four per module, all at `0.1.0` and green on Tests, Install and Compatibility. 929 tests. [#829](https://github.com/liberusoftware/ecommerce-laravel/issues/829) and [#836](https://github.com/liberusoftware/ecommerce-laravel/issues/836) record what shipped and what deliberately did not.

**§1's diagram puts Cart above Checkout, and that edge is an adoption edge, not an import edge.** A checkout session must snapshot its own lines, because prices freeze at the moment checkout begins — the copy is forced by the domain, not chosen. So Checkout never reads a cart, the two have no import relationship, and they built concurrently exactly as tier 1 did. Cart's whole suite runs with no catalogue and no pricing present, over a product id nothing in its database has heard of; Checkout's runs with no cart module present, under a test named for the fact.

### Cart

The identity model wave 1.5 decided was carried forward rather than reinvented, and gained a third case: `user_id` / `guest_token` / `company_id`, **exactly one set**. A company cart is a third identity, not a customer cart with a flag — `user_id = 4` and `company_id = 4` are provably different carts.

The invariant is enforced on `saving`, not `creating`, because the dangerous write is the update that sets `user_id` without clearing the token. It routes through `CartOwner`, which always writes all three columns, so no save passes through a state claiming two. There is **no DB CHECK constraint** — Laravel's schema builder has no portable one across SQLite, MySQL and Postgres — so the guard is the model's single write path, and a test proves a raw `forceFill()->save()` cannot get round it. Stated in `docs/domain.md` rather than left to be discovered.

**Merge is where a cart module is most likely to be wrong, so each edge was decided rather than left implicit.** Over a per-line or per-cart limit **clamps and reports** — a merge runs inside the login event, so throwing fails the sign-in over something the shopper cannot fix, and dropping silently discards a product they chose. `AddLine` throws on the same limits, because there the shopper is present and can be told. Currency mismatch and a stale cart both **refuse**, leaving the account cart untouched and marking the guest cart abandoned rather than deleting it. The guest cart is **copied, not emptied, and its token is not nulled** — nulling it would leave a row with no owner, the one thing a cart may never be; the claim dies with the terminal status instead, so the next visitor inherits nothing.

**One live bug, found by CI.** `totalsAreCurrent()` compared `recalculated_at >= last_activity_at`. At one-second timestamp resolution that answers *true* for a cart whose lines changed in the same second as the last recalculation — failing in the direction that shows a shopper a stale total. Now `carts.revision` against `recalculated_revision`: a stale in-memory model stamps a lower number, which reads as needing recalculation.

### Checkout

**Order placement is an event, not an order.** There is no `orders` table here — Orders is [#882](https://github.com/liberusoftware/ecommerce-laravel/issues/882). `CheckoutCompleted` carries a `PlacedCheckout`: a plain readonly value complete enough to write a whole order from, so no listener needs to read Checkout's tables.

**Idempotency is a first-class domain feature, and the ordering is the interesting part.** The key is checked **before** the guards. A retry arrives after the first call closed the session, so a guard-first ordering would answer `CheckoutNotOpen` and the client would never learn its order exists. A throw releases the claim, so a checkout that failed validation does not burn its key — both properties hold at once. The guarantee is a unique index on `(scope, key)`, not a `select`.

**The honest gap is recorded where it will be read.** SQLite `:memory:` on one connection inside `RefreshDatabase` cannot prove a concurrent race. What is proved is that the unique index is declared, asserted directly against the schema, and that the loser's recovery branch is *executed* — entered by writing the competing row from a `creating` hook, the exact window a real race lands in. That proves the branch, not concurrency, and the test file header says so.

`ApplyDiscount` **refuses** when a line's tax arrived as an amount rather than a rate: scaling it would mean deriving a rate, which the module has promised not to do. A one-way door, documented rather than silently approximated.

### The permissive-gate pattern, confirmed a third time — and it is worse than wave 3 recorded

Wave 3 established that a model with no policy is exposed rather than safe. Cart's Filament package found the sharper version by reading Filament's source: `get_authorization_response()` returns **allow** when a *present* policy has no method for the ability asked about. A partial policy is the same hazard as no policy, and it is harder to see, because the file exists and looks like a control.

There is a second edge underneath it. `CartPolicy::view/update/delete` are typed against `Cart`, so any default gate call about a `CartItem` would be a `TypeError` raised from inside the policy — not a denial. The relation manager returns `isReadOnly()` unconditionally rather than overriding per ability, which Filament consults *before* any policy, sidestepping both.

And a third, on the relation managers themselves: **`canAssociate` and `canDissociate` are live for a `hasMany`** and default open. That is how a tender ends up filed against someone else's order. Checkout's `EvidenceRelationManager` refuses sixteen abilities by name across `lines`, `tenders` and `consents` — three tables with no policy at all — and restates `canViewAny()` as `view` on the session.

Every presentation package now forces the unpublished abilities false and asserts the policy's *yes* first, so the overrides read as deliberately stricter rather than as dead code.

### Two surfaces where the boundary is a security decision, not a layering one

**`guest_token` gets a different answer per transport, from the same principle.** The domain says it is not a session id, not a credential, and that the gate answers *no* for every guest cart — so matching it belongs to whoever issues it.

The **API** must transport it, so it mints server-side with 256 bits, returns it in exactly one response, and **ignores** a client-supplied token that does not already name a live cart — because a client that could pick its token could pick a predictable one, which is the `session_id = 'api'` defect wearing a better name. It travels in `X-Cart-Token`, never a path or query value, because a URL lands in access logs, browser history and `Referer`. Every failure is the identical 404, so a guess never confirms a hit.

The **Livewire** surface does not have to transport it, so it does not: the token lives in the server-side session and never reaches the browser in any form. There is no cart identifier in the public surface at all — not locked, *absent* — and the cart is re-resolved every request. A line id must travel, since a quantity box has to name what it changes, so it arrives as a method argument and is looked up through the resolved cart's own items; another basket's id finds nothing and gets the same answer a second tab's removal gets. The accepted cost is that a guest basket lives as long as the session, with both ways out written down.

**Nothing reachable from a browser can zero a balance.** The checkout Livewire component's `recordTender()` takes no amount and no status: the amount is the server-computed outstanding, the status is always `pending`, which the domain does not count toward settlement. Only the host's server-side confirmation can settle. Likewise the cart API ships **no discount endpoint** — `ApplyDiscount` records an authoritative amount and the cart's owner is exactly who must not set it — and `POST /cart/recalculation` takes no body at all, because otherwise a token holder could write `tax_minor: 0` into the row support and recovery emails read. An OpenAPI test forbids those keys ever appearing in a request schema.

**Two refusals of a surface, rather than a guarded one.** Checkout's panel has no `IdempotencyKey` resource at all: that table has no `team_id`, so any listing of it is cross-tenant by construction, and no surface is the stronger form of the refusal. And placing is deliberately absent from the panel — `PlaceCheckout` takes its idempotency key *from its caller*, and that key is the whole guarantee, so a button minting a fresh one per press charges twice on a double click. The panel reports whether a commit happened instead. In the same spirit the abandonment reason is a `Select` over five slugs rather than a text box, because the domain's event logger copies that value straight into `checkout.abandoned`, and a text box is where a customer's email gets typed into a log line.

### Open by design

The Checkout domain publishes **one** exception class for two opposite conditions: the payload conflict (permanent) and the in-flight claim (transient). The API has to tell them apart to answer 409 or 423, and does it by rebuilding the in-flight message from the domain's own factory rather than guessing at a substring, with both factories pinned by a test so a domain reword fails the suite instead of a client. It is marked in the source as the seam it is. The proper fix belongs to the domain — two exception cases — and to whichever release next touches Checkout.

Twenty-four packages now exist across six modules, and **none is on Packagist**. The blocker named at the end of wave 3 has not moved, and every wave widens it.

---

## 2. The promotion procedure

Full detail in [`MODULE_DEVELOPMENT.md` §6](./MODULE_DEVELOPMENT.md#6-promotion-and-release). What matters to the *plan* is three properties:

**Promotion is per-package, the moment each qualifies** — not per wave. Batching promotions adds a synchronisation barrier where the slowest module holds the rest. A hundred small reviewable host commits beat four large ones.

**Promotion is a source-of-truth flip, and the code stays.** During the path phase a module's code is committed to the host under `app/`; at promotion it moves to `modules/` and **stays tracked there**, with Composer as the authoritative source and the host tree as an installed copy. Whether a module is promoted is answerable by its `composer.json` entry, not by `ls`.

[ADR 0010](./adr/0010-modules-and-themes-are-gitignored.md) argued the opposite — flip `.gitignore` per package so promotion is visible in the file tree — and is **withdrawn**, [#972](https://github.com/liberusoftware/ecommerce-laravel/issues/972). It was never implemented: `.gitignore` has never named `/modules` or `/themes`, because nothing has been promoted yet. So this is a plan correction with no code behind it, which is the cheapest moment such a reversal is ever available. The duplication ADR 0010 objected to is real and now accepted; `git diff --exit-code --stat -- modules themes` is what stops it drifting.

**The soak is retrospective.** No cross-boundary edit in its last N commits, computed from `git log` at promotion time — not a thirty-day calendar wait. Both measure whether the boundary has stopped moving; only one cannot be waived under schedule pressure.

Until a module tags `1.0.0` the host consumes it as `dev-main`, via `minimum-stability: dev` + `prefer-stable: true`. A VCS `repositories` entry exists **only while a module is unpublished**, so its presence carries information — *promoted, not yet on Packagist*.

---

## 3. Reversibility

What each wave costs to undo, stated up front so nobody has to guess mid-incident.

| Wave | Reversible? | How |
| --- | --- | --- |
| **0** — enforcement, installer, theme | **Yes, cheaply.** Config, CI and deletions of dead code. The riskiest item is deleting `app/Modules/`, which serves zero modules | Revert the commit |
| **1** — `ecommerce-commerce-core` | ~~**Yes, before its first tag.** Demotion is deleting an unreleased repository and restoring the path package~~ — **that window has closed.** Tagged `0.4.0`; the row below now applies | See §2 |
| **1.5** — schema, resolver, **the scope** | **The scope is reversible; the schema is additive.** Turning the scope off restores the previous (leaking) behaviour instantly | Feature-flag the scope for the first deployment |
| **2** — schema corrections | **Yes.** It stopped being a data wave: there is no production data to get wrong, so what is left is migrations and code | Revert the commit and rebuild the database |
| **3+** — extractions | **Yes before the first tag, no after.** After a tag, demotion breaks every consumer and the honest move is deprecation. **Catalog, Pricing, Inventory Ledger, Cart and Checkout are all past it** — all twenty-four packages are tagged. Nothing consumes them yet, which is not the same thing | See §2 |

Two asymmetries drive the whole plan:

**Scoping first may temporarily hide rows from their rightful owner. That is recoverable. Continuing to show them to the wrong merchant is not.**

**Quarantining a row that turns out to have an owner costs an operator query. Assigning a row to the wrong merchant costs a data-isolation incident that the scope then enforces and hides.**

---

## 4. What is outside this plan

- **The 105 modules themselves** — the execution epics.
- **The CMS and CRM code — no longer deferred, and no longer a move.** ~~#942, #943~~ are closed, superseded by [**ADR 0013**](./adr/0013-cms-and-crm-packages-are-built-from-ground.md).

  This plan said the code moves *"when those products have repositories, not on a date this plan can name"*. They have repositories, so the condition was met — and then the answer changed underneath it. **The CMS and CRM packages are being built from ground**, each tracked by its own module issue. They are not extractions of this code, so there is no move to schedule. The local code is **deleted at cutover**, in the change that adopts the module.

  Both premises had expired first, in opposite directions, which is what forced the re-decision. `crm-laravel` `2.0.1` already carries `LiveChat`, `ChatMessage`, `Chatbot` and `ChatbotInteraction` — the local stack was a second implementation, not an orphan. And `cms-laravel` holds 21 committed path packages under `packages/liberu-cms/`, so the CMS implementation exists too; only the tags do not. A deferral whose premise has expired needs re-deciding, because re-applying it is how debt outlives its reason.

  What survives, and is the reason closing the trackers costs nothing: [`reconciliation/cms-owned-code.md`](./reconciliation/cms-owned-code.md) and [`reconciliation/crm-chat-stack.md`](./reconciliation/crm-chat-stack.md) were written as *how to move this* and are now *what a fresh package must cover*. ADR 0013 lists the findings a from-scratch build will not rediscover — the inverted `user_id`, the read receipts and rate limits that exist only here, `cms-forms`' `'email'` instead of `'email:rfc'`, and the sitemap's canonical root and 50k cap that `cms-contracts` cannot express.
- **The five out-of-scope flavours** — `react`, `vue`, `nuxt`, `flutter`, `react-native`.
- **Adding a locale.** `en` only; adding a language is product scope. The RTL machinery in `TRANSLATIONS.md` and `THEMES.md` §18.1 stays unexercised as a result, which is recorded as a deliberate deferral rather than an oversight.
