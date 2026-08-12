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

## Wave 5 — tier 3, and the first tier that is genuinely sequential — ✅ **shipped**

**Orders**, then **Fulfillment** and **Returns**.

Twelve packages, four per module. [#882](https://github.com/liberusoftware/ecommerce-laravel/issues/882), [#859](https://github.com/liberusoftware/ecommerce-laravel/issues/859) and [#906](https://github.com/liberusoftware/ecommerce-laravel/issues/906) record what shipped and what deliberately did not.

**Wave 4 found that a tier edge was an adoption edge rather than an import edge, and wave 5 deliberately did not generalise that finding.** It held for Cart and Checkout because a checkout session is definitionally *not* about a cart — it snapshots lines handed to it. It does not hold here: Fulfillment and Returns are definitionally about an **order line**. Built concurrently, all three would have independently invented the same contract for the same thing, and the two later ones would have invented it differently. So Orders went alone, and Fulfillment and Returns fanned out only once it was tagged — against a published contract rather than against each other's guesses.

The boundary rule did not soften to pay for that. None of the three imports any of the others; each one's whole suite runs with none of the rest installed, over ids nothing in its database has heard of, under a test named for the fact.

### Orders

**The state machine is the module.** Twelve transitions are illegal and throw rather than no-op, self-transitions included, and the status history is append-only, so what happened to an order is a record rather than a current value with amnesia.

**Six statuses were refused as facts Orders does not own.** Anything that describes where goods physically are belongs to Fulfillment; anything describing what came back belongs to Returns. An order that reports its own shipping state is a second answer to a question another module already answers.

**The line contract was designed to be held by modules that did not exist yet.** `OrderLineData`, reached through `OrderQuery`, carries the line's identity, its product and variant, its quantity, and three counters — `fulfilled`, `cancelled`, `returned` — plus both derived counts, `outstandingQuantity()` and `returnableQuantity()`, so that no downstream module derives one itself and gets it wrong. A line id is **stable and public**: a line is never deleted and never replaced, and cancelling raises a counter rather than removing a row.

**One action writes all three counters**, append-only, refused rather than clamped, with `returned <= fulfilled` enforced as arithmetic. Nothing can come back that never went out. Money on a line is frozen and never recomputed, including after a cancellation.

**The cancellation/return line is drawn at delivery**, and enforced in three places rather than documented in one. Cancelling is calling off something that has not happened, so `completed → cancelled` is not a legal transition and `CancelOrder` refuses an order with anything fulfilled.

**Idempotency is `unique(source, placement_key)`** — a client-supplied key, scoped by who supplied it, with two exception classes rather than one. Wave 4 shipped a single class for two opposite conditions and had to rebuild a message downstream to tell them apart; that is recorded in §wave-4 as a seam, and this is the first module that did not repeat it.

The host column-by-column split is in the issue: shipping and dropshipping columns refused, `shipping_cost` kept as a `kind = shipping` line rather than a column, addresses and `recipient_*` kept because an invoice address must not change when a shipment reroutes, `billing_country` / `vat_number` / `reverse_charge` kept as customer evidence, `payment_*` refused, and `total_amount decimal(10,2)` refused outright — money is integer minor units everywhere in this fleet.

### Fulfillment

**A parcel, not an order, is the unit.** One order ships in as many parcels, on as many carriers, at as many times as it takes, each parcel carrying its own destination and its own evidence. A destination per shipment rather than per order is what makes a split delivery expressible at all.

**A reservation counter and a dispatch counter are told apart**, because taking goods on is not the same fact as goods leaving. Over-shipping is refused rather than clamped; dispatched quantities are final; a dispatched parcel is never un-shipped. Cancellation is allowed before dispatch and not after — the same line Orders draws, drawn in the same place.

**Every carrier is a string this module has no opinion about.** No integration, no provider list, no service-level enum. A host that wants tracking URLs owns that.

**`ShipmentDispatched` fires once per parcel and only when goods actually leave**, because reporting the same goods twice puts an order's fulfilled count ahead of the warehouse. A redelivered recording replays and announces nothing. A parcel that has already gone can be written down in a single call by passing `dispatchedAt` — two calls for that case would mean a redelivered job replaying the first and being refused by the second, which is idempotency that only works once.

### Returns

**A refund is recorded as an amount and a reference, not taken.** The money belongs to whoever owns the tender, and this module writes down what somebody else moved.

**Five clocks, and requested / approved / received kept as three separate facts** rather than one number that gets overwritten. They are refused rather than clamped when they disagree: a short receipt is fine, an over-receipt throws, and goods that were never authorised throw *differently* — adopting them would be this module deciding a merchant's answer for them.

**Eligibility is an input, never a lookup.** No return window, no policy, no rule about what is returnable lives here. The caller passes `returnableQuantity` and this module refuses anything past it.

**Inspection publishes a restock decision; it does not restock.** Saleable and rejected are counted separately, and what happens to stock is the Inventory Ledger's business via a host listener.

**Receipts are deltas, not totals.** A return arriving in two parcels dispatches its event twice, with two and then three, never with two and then five — the counter on the far side is append-only, so a total posted twice is double the goods. That listener is where the two modules' invariants meet: Orders refuses `returned > fulfilled`, and Returns refuses to resolve or refund a return that took delivery of nothing.

### The surfaces are where the security decisions live

Wave 4 recorded two surfaces where the boundary turned out to be a security question rather than a layering one. Wave 5 is the wave where that stopped being an observation and became the bulk of the work: nine of the twelve packages are surfaces, and what each one **refuses** is the interesting half of it.

**A tracking number goes in once and never comes out.** Fulfillment's API accepts it at intake, returns it from no operation, keeps it out of the logs and ships no correction endpoint. The domain leaves it reachable for the one surface that renders it to the customer who owns the parcel — and this is not that surface and cannot become it, because a shipment carries a `team_id` and no customer, so nothing there can answer *is this yours* for a shopper. What is left holding the credential is a machine, and returning evidence to a machine credential is a bulk export of every carrier reference in the business behind one `GET`. Likewise a destination appears on a single parcel and a single order, never in a pick list, because a page of a hundred entries is a bulk address export.

**An intake token can mark a parcel dispatched, and that is written down rather than hidden.** Recording-and-dispatching in one call is what keeps a redelivered job idempotent — two calls would mean the replay being refused by the second — so an intake credential does raise the order's fulfilled count. That is why intake is its own route group with its own scope and middleware, and why it appears in the README, the domain doc and the OpenAPI field description.

**A shopper advances exactly one of Returns' five clocks.** Seven operations are refused by name with a test apiece, cancelling included: a self-service cancel closes a workflow whose parcel may be in a van at that moment. **Recording money is its own token scope**, deliberately outside the staff grant, and the route is named so the name-derived scope rule keeps them apart — an operator token holding the whole staff grant is refused and writes nothing.

**Eligibility never comes from a caller.** `returnable_quantity` in a request body is a 422 rather than an ignored key, and a line the host did not resolve has zero returnable — so the module states the refusal in its own words and the adapter writes no eligibility rule at all. A deployment that resolves nothing publishes a surface that accepts no return, which is the safe direction to fail in.

**Every failed lookup answers identically, byte for byte.** Both APIs converged on it independently — missing, another tenant's, or never existed all produce one response, on reads and on writes. Returns went further than the sibling orders-api, which answers 403 cross-tenant, because a return authorisation number is minted to be quoted: a differing answer makes a shared support queue enumerable. In both, `403` survives only where the refusal is about the credential and names no resource, and a resource in a refusing state is 409 rather than 403.

**No incrementing id in any URL.** Parcels by public reference, orders by number, returns by authorisation number, pinned by tests that walk the route URIs. The Filament panel needed a fix for the same reason, and the fix is worth recording because the two halves are separate: `getRecordRouteKeyName()` is read in exactly one place, `resolveRecordRouteBinding()`, which is the *inbound* half. URL generation goes through plain Laravel `route()`, and `RouteUrlGenerator::formatParameters()` renders a model as `$model->{field}` only when the route declares a binding field. So the resource declared its route key and still emitted the id, until the page was registered as `/{record:reference}`. An id in a URL enumerates everybody else's parcels, and a URL is the part of a page that gets pasted into a support ticket.

**No free text on either API.** Wave 4 found that a free-text field next to an event logger is where PII gets typed. Returns' API refuses the module's one free-text field outright, reasons are a closed enum, transition reasons are short slugs, and a refund reference admits no whitespace so it cannot become a note in an accounting export.

**Two projections rather than one blanked.** A shopper's view of a return is a separate method over a separate schema — no row id, no tenant, no dispositions, no refund reference — but it does carry the amount, because it is their money.

### The permissive gate, a fourth and fifth time

Every panel in this wave forces the unpublished abilities false by name, on the resources and on the relation managers, and refuses `canAssociate` / `canDissociate` / `canDissociateAny`. A model with no policy is exposed rather than safe; a *partial* policy is the same hazard and harder to see, because the file exists and looks like a control. Tenancy scopes are written `whereRaw('1 = 0')` for the null-team case, never `where('col', null)`, which compiles to `is null` and lists exactly the orphan rows the policy denies.

### Three failures that are silent by construction

These are the ones that will not page anybody, so they are in the runbooks rather than in anybody's memory.

**Attribution disappears on a ULID or UUID deployment.** `Auth::id()` guarded by `is_numeric()` returns null, so every status change and every refund records "not a person", with no error anywhere — because `(int) '01H…'` is `1`, and attributing a refund to user 1 is worse than attributing it to nobody.

**The same guard on the storefront returns null for every signed-in shopper**, which renders as "sign in to see your returns", forever. That silence is deliberate — a guest is an *answer* there, not an error, which is what stops a signed-out response being an enumeration oracle — but it means a misconfigured host is indistinguishable from a logged-out one.

**A non-numeric store id disables the storefront filter rather than tightening it.** It arrives as a ticket about seeing another shop's returns.

The shape they share is worth naming: a value that fails to parse is being treated as an absent value, and absent means *unfiltered* in two of the three. A guard that narrows on a good input and opens on a bad one is a guard pointing the wrong way.

### The exception seam, paid down and reopened

Wave 4 left one class published for two opposite conditions and marked it as a seam. Fulfillment's domain shipped the fix — `FulfillmentConflict` and `FulfillmentInFlight` as two classes, mapped by `instanceof` to 409 and 423 with `Retry-After` — and where one class still covered two conditions its API **removed the ambiguity rather than decoding it**, resolving the request in the controller (which tenancy required anyway) so only the body failure could reach the action.

Returns did not, and it is now recorded rather than carried: [module-ecommerce-returns#2](https://github.com/liberusoftware/module-ecommerce-returns/issues/2). `UnexpectedReturnLine` means both "these goods were never authorised" — permanent — and "this return is no longer open to goods" — a race between a page rendering and a button being pressed. The panel labels the race with the permanent message for a line the return plainly does cover; the advice that falls out is right by coincidence of class rather than by design. The API tells them apart by calling the module's own message factories with sentinels and matching the invariant tail, pinning every side against the factory that produces it, so a domain reword breaks the suite rather than a client, and an unrecognised case falls to 409 as the safer wrong answer. That is a consumer working round a domain, and the fix belongs in the domain.

### Two things found by writing the documentation

Both packages that were documented after the fact turned up something the code did not.

**A doc overclaimed and no test was watching.** `returns-livewire`'s domain doc said no incrementing id appeared anywhere in the package, "not in the rendered markup". Two did — a row id as a `wire:key`, and an order line id in a hidden form field posted back. Both safe, matched by identity against lines already resolved for that shopper's purchase, but the sentence was false and nothing failed. It now says "no incrementing id travels as state", with a table naming both and why neither is a lookup key.

**A docblock contradicted its own code.** `ReturnRequestPolicy` claimed an orphan return was visible so it could be found and fixed; `view()` calls `ownsIt()`, which is false for a null team, so orphans are visible to nobody. Code and tests agree with each other; only the comment was out of step, and an adopter reading it would have taken it at face value ([module-ecommerce-returns#1](https://github.com/liberusoftware/module-ecommerce-returns/issues/1)).

Both were found because someone had to write down what the code does for a reader who cannot see it. That is a second use for the docs beyond the one they were commissioned for.

**Thirty-six packages now exist across nine modules, and none is on Packagist.**

---

## Wave 6 — Payment Operations, and the end of the tier diagram — ✅ **shipped**

Four packages at `0.1.0`, green on Tests, Install and Compatibility. 639 tests. [#883](https://github.com/liberusoftware/ecommerce-laravel/issues/883) records what shipped.

**§1's tier diagram is exhausted after wave 5**, so the sequencing rule falls back to what is forced by dependency and by most-existing-code. Payments is forced on both counts: Checkout ships tenders provider-neutral with no gateway, Refunds ([#901](https://github.com/liberusoftware/ecommerce-laravel/issues/901)), multi-tender ([#875](https://github.com/liberusoftware/ecommerce-laravel/issues/875)) and gift cards ([#860](https://github.com/liberusoftware/ecommerce-laravel/issues/860)) all need a tender lifecycle to exist first, and the host carries two gateways, a factory, a service, an interface and three controllers.

### What the host's contract encoded, and why none of it survived

`app/Interfaces/PaymentGatewayInterface.php` was three methods with four load-bearing faults. **`float $amount`** — every module since wave 3 uses integer minor units, and a float in a payment contract is the one that shows up in somebody's bank reconciliation. **No authorize/capture split**: `processPayment` was one step, which is precisely why payment operations had never been a module. **`processSubscription`**, which belongs to [#918](https://github.com/liberusoftware/ecommerce-laravel/issues/918) — a gateway contract that knows about plans has taken on a second domain. And **`array` in, `array` out**, so the shape lived only in the two implementations.

`app/Models/PaymentMethod.php` was a live leak: `details` in `$fillable`, no `$hidden`, no cast, and `CONFORMANCE.md` records the controller returning it raw.

**The module makes the equivalent unrepresentable rather than merely unexposed.** There is no column that could hold a PAN, CVV or IBAN, asserted by name in `SchemaTest`. `$hidden` was rejected as the answer, because it is a serialisation default that a `makeVisible()` or a raw query walks straight past — and the host's own webhook controller already calls `makeVisible('secret')` deliberately. A column that cannot hold the secret cannot leak it. `docs/adoption.md` leads with the migration off it: verify the contents, `$hidden` as a same-day stopgap explicitly not the fix, migrate only real provider tokens, drop the column, delete the controller.

### State is a fold, and the fold is proved total

There is no status column and no cached total anywhere — both asserted absent by name. Authorize, capture, void, refund and settle are rows on an append-only ledger, and `PaymentState::fold()` derives the rest. `CONFORMANCE.md` records what the alternative buys: `Order::transitionTo()` throwing, deriving payment status, writing audit, firing webhooks and generating an invoice in one method, with `OrderResource` exposing `payment_status` as a free `Select` beside an editable `total_amount` and bypassing the transitions entirely.

The fold is proved three ways rather than asserted. A `match` over `EntryKind` with **no `default` arm**, plus a test folding a ledger containing every `EntryKind::cases()`, so an unhandled case fails the build instead of contributing zero in silence. **Commutativity** over every permutation of four multisets — which is *why* out-of-order provider callbacks need no reordering logic at all, rather than a claim that they are handled. And all 400 sequences of kinds up to length three enumerated against a nine-branch cascade, with a hand-built dataset reaching every branch so totality is not achieved by collapsing everything into one answer.

Two holes the tests found. `PaymentInstrumentPolicy::detach` silently reopened Filament's relation-manager `detach`, because a subclass method wins over the trait's — now `detachInstrument`. And **model events do not fire for `query()->update()`/`->delete()`**, so append-only had a gap; `LedgerBuilder` closes it, and the remaining raw `DB::table()` path is named in the runbook with the trigger and GRANT fix rather than papered over.

### Multi-currency: record it, convert nothing

Presentment is fixed at authorization, every entry must match it or the write is refused, and it is the only currency any arithmetic happens in. Settlement amount, currency, exponent and rate ride on the entry — the rate as a **string** — and never enter the fold, are never summed, never converted back.

Refusing cross-currency settlement outright was considered and rejected: it does not stop the case happening, it only moves the fact out of the ledger that recorded the money. The cost is stated instead of hidden — **this module cannot tell you what you netted**, and `capturedForOrder()` throws for a mixed-currency order rather than answering. Every surface carries that refusal forward as a sentence rather than a blank cell.

### A provider callback is hostile input

`verify()` is the only method producing a `ProviderEvent`; there is no `parse()`, so verify-first is a property of the type rather than a convention. The API controller holds no `json_decode`, no `$request->input()` and no validation rule — it hands `getContent()` over — and the gateway is in the **path**, because reading it from the body would mean decoding the body to choose which secret to check it against.

**A signature failure is 401**, and nothing is written. Not 422: that invites retry, implies the body was parsed, and confirms to a forger that it got that far. Not 403: there is no identity.

**One 2xx covers all four verified outcomes** — applied, duplicate, unmatched, unrecognised — with the outcome in the body. A provider branches on 2xx-versus-not, so four distinct codes would invite it to branch on this deployment's business, and an unrecognised event answered with a 500 turns the provider's retry queue into an outage. Dedupe is the domain's `unique(gateway, provider_event_id)` row, belted by the entry key, so the same event cannot move money twice even if the callbacks row is deleted by hand. The raw body is never stored — only a SHA-256 digest.

Impossible ledgers are **reported, never clamped**: provider-origin entries are never refused, and `needsReconciliation()` surfaces the result.

### The same mechanism, three answers — and why copying the pattern would have been the bug

Wave 4's Checkout and this module both mint an idempotency key once when the paying step is entered and hold it locked. What a **conflict** means underneath is not the same, and the Livewire package reasoned it through rather than copying:

- In **checkout**, a conflict means nothing was committed under that key, so dropping it and minting a fresh one is safe.
- In **payments**, a conflict means a payment already exists under that key with different facts — so a fresh key would authorize a **second** payment for the new total. It refuses and mints nothing.
- A **decline** does mint a fresh key, because a decline is a committed `failed` entry the old key can now only replay, and the shopper needs to try another card.

Both exception classes are told apart by `instanceof` throughout — 409 permanent, 423 with `Retry-After` transient. The seam wave 4 recorded and wave 5's Fulfillment fixed does not recur here.

### The surfaces refuse more than they offer

The shopper-facing Livewire package is three components — pay, receipt, saved instruments — and the shortness of the list is the design: everything else either moves somebody else's money or reads hostile input. **No amount is a client input at all**; the component holds an opaque locked reference and the host's resolver prices it on the request that charges, so re-pricing between mount and click charges the newer server number. The three components render **no `<input>`, `<select>` or `<textarea>`** anywhere, asserted across all three at once, and the provider token arrives as an argument rather than a property, with a 12–19 digit run or an IBAN refused before the gateway is called.

The operator panel offers three actions — take the reserved money, release it, give it back — each re-reading the ledger before calling the domain. **No amount is ever typed**: capture takes everything reserved, refund gives back everything refundable, and the package constructs no form input of any kind, which a boundary test greps for. Partial movements are refused because a money field is where a typo becomes a charge, and a partial's idempotency key belongs to whoever decided the amount. The key a button uses is **derived, not minted** (`panel:PAY-…:capture:5000GBP`), and the honest limit is printed on the page: a panel cannot tell a second identical instruction from a second click, so a second identical movement needs a caller that owns its key. That guard also stops `CapturePayment` writing a zero-amount row, which it would otherwise do happily.

Two absences argued rather than assumed. **No instrument resource**, because `PaymentInstrumentPolicy` publishes `detachInstrument` but the domain ships no action performing it — a presentation package writing `detached_at` would be a second write path in a module where every write is an action ([module-ecommerce-payment-operations#1](https://github.com/liberusoftware/module-ecommerce-payment-operations/issues/1) is the upstream `DetachInstrument` this wants). And **unmatched callbacks are not in the panel**: they carry a null `team_id` because the team is copied off the matched payment, so showing them would be a second tenancy answer over rows that might be anybody's money. The page says they are absent and gives the runbook's console read, because an invisible queue is worse than an unread one.

**Forty packages now exist across ten modules, and none is on Packagist.**

---

## Wave 7 — Refunds and Gift Cards, built with each other absent — ✅ **shipped**

Eight packages at `0.1.0`, green on Tests, Install and Compatibility. 1,189 tests. [#901](https://github.com/liberusoftware/ecommerce-laravel/issues/901) and [#860](https://github.com/liberusoftware/ecommerce-laravel/issues/860) record what shipped.

Two modules, no import edge between them, built **concurrently and with each other absent** — the same technique as Cart-without-catalogue and Checkout-without-cart. A boundary asserted is a claim; a boundary compiled against nothing is a fact.

**The line the wave is organised around: Refunds decides what is owed. Payment Operations moves the money. Gift Cards holds a balance. None of the three imports another.** Everything crossing is an identifier or an already-resolved value — an order id, a payment reference, an amount in minor units, a currency code.

**Refunds and Returns are not the same thing**, which wave 5 made easy to get wrong by shipping a `Refund` model of its own. That one is a *record*; this module owns the *decision lifecycle*. A refund exists with no return (goodwill, a price adjustment, a cancelled order) and a return exists with no refund (an exchange, a warranty replacement), so `adoption.md` §4 says explicitly not to put a foreign key between them.

### What the host's two tables encoded

`refunds` carried five faults, and four of them are a domain leaking into a column. **`decimal` money.** A **mutable `status`** with the states in a comment. **`refund_method: store_credit`**, putting another module's domain in a string — a refund to store credit is a refund whose *destination* is a gift card reference, decided here and executed there. **`transaction_id`**, pointing at a gateway that is now wave 6's job; a refund carries a payment **reference**, never a transaction. And **`restock_items`**, making a refund do inventory's work — a refund emits, a listener restocks, and whether goods come back is a returns question anyway.

`gift_cards` carried four, and the first is the serious one: **the code was stored in plain text.** A gift card code is a bearer credential — whoever holds it holds the money — so a plaintext column means a leaked backup, a logged slow query or table access is cash. Then a **mutable `balance`** sitting beside a transactions table, two sources of truth where the mutable one wins by accident; `decimal` money with **`currency` defaulting to `USD`**, the same shape as the `default(1)` mistake wave 2 spent a wave unpicking; and no tenancy.

### A bearer credential, stored so that losing the database is not losing the money

Three columns replace the one. **`code_index`** is `hash_hmac('sha256', $normalised, $pepper)`, `char(64)`, unique — the one-query lookup key. **`code_hash`** is per-row bcrypt and **deliberately unindexed**, so the scheme is still sound if the pepper leaks or is rotated. **`last_four`** is for display. Neither hash is `$fillable`; the pepper has no default and `Code::pepper()` throws rather than hashing under `''`.

The guarantee is proved rather than asserted, twice in the domain and again on every surface. `SchemaTest` names ~35 plaintext column names absent, then issues a card and **searches every cell of every table** for the code just minted. The API's `ExposureTest` mints through the API and hunts that one string through body **and headers** of all nine routes — including the response nearest the code, a refusal after the row matched — plus log lines, both tables, `toJson()` and the raw attribute bag, in four forms: as issued, normalised, url-encoded, and **the leading sixteen characters**, so the four published for a receipt cannot be widened by one without the suite going red. The Filament package shows the code once at issue and its test recomputes `Code::index(Code::normalise($shown))` against the stored `code_index`, so what it proves is that the *card's* code was shown and then never shown again. The Livewire package's answer is the sharpest: **the code is not a property at all.** `apply(string $code)` takes it as a method argument, so it crosses the wire once as a call parameter and lives for one stack frame — a `#[Locked]` property would still be dehydrated into the snapshot on every render, which is exactly the outcome to avoid on a credential. Every public property is locked with **no exceptions list**, because the argument mechanism made one unnecessary.

### Enumeration is closed by making every wrong answer the same answer

One exception class with a constant message across all eight `RefusalReason` cases, and `Code::verify()` performs exactly one password verification whether or not the row was found. The surfaces carry it forward: the API answers seven of the eight with **one byte-identical 422** including headers, `Throttled` alone getting 429 with `Retry-After`; the Livewire component asserts the whole dehydrated public state is **equal across all ten failure modes** over a dataset, which is uniformity as *shape* rather than as message.

Three refusals follow from the same reasoning and are worth keeping. **The code's shape is never validated** — no pattern, no length, no alphabet — because a "wrong shape" error is a cheaper oracle than the one the domain closed, and it would undo the `I`/`L`/`O` normalisation. **`POST /redemptions` refuses any query string outright**, because `Request::all()` merges query into body and `?code=…` would redeem through exactly the channel the endpoint exists to keep a credential out of. And on the redemption path **there is no tenant at all**, argued rather than assumed: there is no lookup-by-code to pre-check with, and a tenancy refusal would have to be indistinguishable from every other refusal, so the operator could never be told why.

The alphabet is Crockford base32, 20 characters, 2^100, drawn with uniform `random_int()` — the host's `strtoupper(Str::random(16))` collapses 52 letters onto 26.

### Six decisions the gift-card domain could not leave implicit

**Expiry ends redeemability, never the money**: `expires_at` is write-once and no path edits a balance, so the module can be deployed under a jurisdiction that forbids expiry without changing what it does to the ledger. **Partial redemption keeps the balance** rather than reissuing. **Refunding onto a card** is `RecordCredit` with `CreditOrigin::Refund`, addressed by reference — which is where this wave's two modules meet without importing each other. **Balance is debited at redemption with no reservation**, because a hold needs an expiry, an expiry needs a sweeper, and a stopped sweeper locks a customer's balance. **One table for both kinds** via `AccountKind`, mirroring the one fold. And **`EntryKind` has no `Enabled` case**: commutativity is load-bearing, disable-then-enable is a different fact from enable-then-disable, so disabling is terminal and recovery is a replacement card.

### Refunds: the module is *told* what was captured, and every surface had to answer where that number comes from

`Refundability` is a required constructor argument with no default and no nullable, carrying `capturedMinor` and `previouslyRefundedMinor`, both frozen onto the refund row. Over-refunding is **refused, never clamped**, with the binding check inside `ApproveRefund`'s transaction computing `ceiling = captured − max(callerPreviouslyRefunded, ourSettled) − ourApprovedButUnsettled` and every sibling on the same `payment_reference` selected `lockForUpdate()`. The approver may not be the requester, and an anonymous approver is refused outright — `null !== null` is false, which is the wave-4 orphan trap in its other direction.

**A zero-amount approval dispatches `RefundAcknowledged`, not `RefundApproved`**, so a never-charged order can be closed and can never become a money movement. `RefundApproved` is imperative: the host listener moves money and calls back with `SettleRefund`.

The API found the load-bearing question. **The ceiling is resolved server-side, never received.** Taking `captured_minor` in the body is the obvious transport answer and it is a hole of the same shape as accepting a tenant id, because every domain guard is arithmetic over that number. So the package publishes `ResolvesRefundability` — one method, no implementation — and the deployment binds it. Nothing bound is a **503**, not the caller's fault; a resolver returning null is a **422** on `payment_reference`; nonsense is a 503. A cancelled order with no tender resolves nothing, so **a half-configured deployment can close never-charged orders and cannot record anything that moves money** — which is the correct direction for a partial failure to fall.

### The exception seam, closed by resolving earlier rather than by decoding

`ExceedsRefundable` publishes two conditions from one class — "you asked for too much" and "somebody else got there first". Wave 4 decoded a message to tell such a pair apart and it is recorded as a defect. Here the first case is refused **in the controller against a frozen column** (a refund's `amount_minor` never changes, so it is not a race) as a 422, which means what can still reach `callAction()` is only the second, and that is the 409. The same technique closed three more overloaded classes — `RefundNotSettleable`, `CurrencyMismatch`, `RefundabilityUnknown`. **No status anywhere is chosen by reading a message**, and the upstream fix is filed in `adoption.md` §5.1 rather than left as a comment.

### The same mechanism, two more answers

Wave 6 recorded three answers to what an idempotency conflict means. This wave adds two, both reasoned rather than copied — which is now the fourth time the pattern has been *not* copied, and the reason it is written down as a warning rather than a recipe.

- **Refunds/Livewire refuses and mints nothing**, and Checkout's "a fresh key is safe" is the trap: the domain ceiling refuses over-refunding *against the capture*, not duplicate claims *within* it. £50 captured, claim £20, conflict, fresh key, claim £30 — both pass every domain check and both land in the approval queue. It can hold that line absolutely, unlike Payment Operations, because `RequestRefund` guards *before* `DB::transaction`, so there is no committed non-outcome. One mount, one key, one claim.
- **Gift Cards/Livewire derives the key from the step** rather than minting a UUID. Payment Operations catches a reload by reading the ledger for the order; this module cannot, because the ledger is indexed by card and there is no card until somebody types a code. A random key would debit twice on reload; derivation makes the reload a replay.

And one deliberate *refusal* to distinguish, unique in the fleet so far: on the gift-card box, permanent conflict and transient in-flight are **not** told apart. Reaching either requires having presented a real code, so a 409-versus-423 split is a confirmation oracle. One catch, three exception classes, one answer.

### The surfaces, again, refuse more than they offer

**No amount is typed anywhere in either module's panels.** Approving a refund agrees to what was asked; a merchant wanting to give back less refuses and a second refund is raised, which is the domain's own answer to changing your mind. A test asserts the refunds panel constructs **exactly two** form inputs and names both — the approver's note and a manual settlement reference. There are **no bulk actions**: one press moving an unbounded amount of somebody's money is the operation the two-person rule exists to slow down.

**Settlement is offered only for `RefundDestination::Manual`** — stricter than `RefundPolicy::settle()` allows. For a card or a gift card the mover is a host listener holding the provider's reference and its own idempotency key; a panel button recording that writes down a movement it did not perform and cannot reconcile. `Manual` is the only case where the presser *is* the mover.

**A zero approval is relabelled an acknowledgement** across button, modal, confirmation and notification, and self-approval is permitted only there. A button reading "Approve" over £0.00 implies money moves.

Both shopper surfaces are scoped by what they refuse. Refunds/Livewire is two components using 2 of 11 published reads and 1 of 5 actions; it declines a "my refunds" list, whose only new capability is enumeration — an ownership check on every row instead of one — and declines a cancel button, because the domain has no withdrawn kind and cancelling would record a *rejection with the shopper as decider*. A guest order **404s for everybody**, because an order number is not a credential, and ownership compares two real ids so that `null === null` never hands an orphan to a visitor. Gift Cards/Livewire ships no balance-check-by-code box, and states the cost rather than hiding it: **a card short of the total is refused, not partly spent**, because split tender needs a domain-published answer against a caller-supplied figure and that is not a transport's to invent.

Wording carries a decision where a badge would undo it: `AccountStatus::Expired` renders **"Expired — the balance is still there."**

### Two weakenings named rather than buried

**Issuing a gift card is gated on `viewAny` plus having a team**, because the domain publishes no record-less ability but `viewAny` and the package refused to bind a second opinion. Whoever can see the resource can mint a balance; the role grant over the resource is the control. It is in `docs/domain.md` §7 and `docs/adoption.md` §4.1 so a deployment decides it deliberately.

**Refund intake is gated on `viewAny`** for the mirror-image reason: `RefundPolicy::create()` is permanently false, so gating on `create` would make the route dead. Upstream note filed for a `request` ability.

Two honest limits are printed rather than implied. SQLite compiles `lockForUpdate()` away, so `tests/ConcurrencyTest.php` says in its header that **no test in it is a real race** — the same admission Checkout made in wave 4. And the timing half of the enumeration defence **cannot be proved over HTTP on a shared CI runner**; the domain's `CodeTest` proves it where it is a property of one function.

**Forty-eight packages now exist across twelve modules, and none is on Packagist.**

---

## Wave 8 — Multi-Tender Payments, and the fact that there is no transaction across gateways — ✅ **shipped**

Four packages at `0.1.0`, green on Tests, Install and Compatibility. 433 tests, 7,554 assertions. [#875](https://github.com/liberusoftware/ecommerce-laravel/issues/875) records what shipped.

**The line the wave is organised around: Multi-Tender Payments owns the plan and the arithmetic. It never moves money and it never holds a balance.** Payment Operations authorises and captures; Gift Cards holds a redeemable balance; Refunds decides what is owed back; Orders owns the total. This module imports **none of the four** — the boundary suite names all four namespaces and both their `require` and `require-dev` entries — and what crosses is an identifier, an amount in minor units, or a currency code.

This closes the payments cluster, and it is the last module whose neighbours were all already built. Every prior wave could assert a boundary against something absent; this one had to hold a line against four things that exist.

### The host had no multi-tender concept, which is worse than having a bad one

Nine faults, and the first is the one that matters. **`orders.payment_method` is a single nullable free-text `string`** — no enum, no FK, no validation. One order, one tender. Multi-tender is not unimplemented in the host, it is **unrepresentable**, which is a different and larger problem than a table modelled wrongly.

The rest follow from it. **`orders.transaction_id`** (`2026_07_13_000005`) holds one gateway charge id on the order, so a second tender's charge has nowhere to go but on top of the first. **`orders.payment_status` and `invoices.payment_status`** are two independent denormalised status strings that can disagree, with nothing between "pending" and "paid" — partial payment has no value to be in. **`orders.total_amount` is `decimal(10,2)`** (`2026_07_14_001101`, itself a fix for an integer column truncating cents), and allocation is the one place money arithmetic is *not* a single addition: splitting a total across N tenders and reconciling the remainder. **`payment_methods.details` is a `text` blob**, unstructured and unencrypted; **`is_default` is a bare boolean** with no unique constraint, so two rows can both be default; and **`user_id` FKs into `users` with `onDelete('cascade')`** with no tenant column at all, so deleting a user silently deletes payment history. **There is no allocation record anywhere**, so an outstanding balance cannot be computed, only asserted by a status string. And **deposits and instalments do not exist in any form** — no table, no column, no model, which meant every decision below was made for the first time with no precedent to inherit.

### The fact that shaped the whole module

**There is no transaction across gateways.** A three-tender plan is three separate movements of real money, at three institutions, at three instants. When tender 2 declines, tender 1's capture has already happened and no application-level rollback can un-happen it. Any design treating a plan as atomic is lying about the world.

Four consequences, all visible in the code rather than in a comment. The tender ledger is **append-only** — no update path, no delete path, and reversal is a *new* entry carrying its own reason. A declined tender never erases an earlier captured one. **There is no "plan failed" state**: a plan is satisfied or it has an outstanding balance, and both are computed, never stored. And **partial satisfaction is the normal case**, designed for first, with full satisfaction falling out as the special case where the balance is zero.

The rule also decided a mechanism three sections later. In-flight idempotency is detected with a **cache lock rather than a claim row**, because a claim row would have needed an update path and contradicted append-only. The constraint propagated on its own.

### The wave-7 deferral, settled by reversing it

Wave 7's gift-card box refused a card short of the total rather than partly spending it, and said why: *split tender needs a domain-published answer against a caller-supplied figure, which is not a transport's to invent*. Wave 8 supplies the answer, so **a short tender is now partly spent and the shortfall becomes the outstanding balance**.

The mechanism is wave 7's `ResolvesRefundability` seam applied twice. **`ResolvesPayableTotal`** is published with no implementation and **no default binding**; unbound is a 503, a null answer for an order that exists is a 422, and the two never collapse into one. **`ResolvesTenderCapacity`** is keyed by tender kind and host-bound, so the module never asks Gift Cards what a card is worth. A `null` capacity means **"no ceiling known", not zero** — the ordinary answer for a card, and the distinction is load-bearing: a host returning `0` for a card would admit every card at nothing.

Ten decisions were pre-settled in the brief so the agents would implement rather than rediscover them. The four that carry weight: **over-allocation is refused, never clamped** (clamping silently changes a number the caller gave you), **under-allocation is valid** and is the outstanding balance, **no tender kind has a hardcoded priority** — caller-declared order, recorded — and **all tenders in a plan share the order's currency**, mixed refused with its own exception, no default, no conversion. Two more were forced by the shape of the domain: **a deposit is just a tender** recorded before the order completes, not a parallel ledger; and **instalments are external references only** — the module records a schedule's identifier and no due date is authoritative.

**Reversal is not refund.** Recording that a tender was reversed is a ledger entry here; deciding money is owed back is `ecommerce-refunds`. The README says so, because the two are one careless import apart.

### Allocation is the first arithmetic in the fleet that needed a property test

Every previous module's money handling is addition and comparison, which examples cover. Splitting a total proportionally across N tenders in integer minor units is not: the parts must sum to the total with **no residue**, and the failure mode is a single minor unit appearing or vanishing on inputs nobody picked by hand.

So the split is largest-remainder with an explicit tie-break (declared order), pinned by a property-style test sweeping totals against split counts and asserting `array_sum($parts) === $total` on every one. The domain package's ratio of assertions to tests is **57:1**, an order above every prior package in the fleet, and that sweep is where it comes from.

Two related refusals. **Decimal-to-minor truncates, never rounds** — rounding a caller's figure invents money. And **a plan declares amounts or shares, never both**; the brief mandated exact proportional splitting without saying how a caller expresses it, and mixing the two is ambiguous, so it is refused.

### Outstanding balance is a fold, proved three ways including order-independence

No status column, no cached total, no `amount_paid_minor`. The balance folds the append-only ledger, and the test builds a non-trivial one — mixed kinds, a reversal, a partial capture, an out-of-sequence entry — then proves the same number three independent ways: fold forward from empty; subtract applied tenders from the payable total; and **replay in a different order**. The third is the one that matters, because order-independence is the property that makes a fold over a distributed set of movements trustworthy at all.

### A fail-closed check that was failing open

CI caught the wave's one real bug, and it is worth recording because it is a shape the fleet has now hit three times in three different guises.

The API's token-scope middleware guarded on `is_callable([$user, 'tokenCan'])`. **That is true for any Eloquent model**, because `__call()` answers every method name. The fail-closed scope check was therefore failing *open* — reaching the call, raising `BadMethodCallException`, and rendering 500 instead of 403. Now gated on `method_exists()`.

It is the same class as "a present policy missing a method is permissive" from wave 4 and "an unanswered gate allows" from wave 5: **a capability probe that answers yes by construction**. The general lesson, now written into the build brief, is that asking a PHP object whether it can do something is not a security check unless you ask in a form that can say no.

### The surfaces each found a different way to hold the same line

**The API's input walk is the output walk inverted.** Wave 7 hunted a secret through every response body and header; wave 8 pushes a total, a balance, a capacity, a currency and a tenant id *into* every route's body, query **and** headers on all five routes, and asserts the server-resolved figures come back unmoved. The structural half enumerates every accepted key from one `Payload::rules()` table and greps `src/` proving there is no `->input()`, `->all()`, `->query()`, `->json()`, `->merge()`, `$_POST` or `$_GET`, and exactly one header read. A tender's own `amount_minor` **is** accepted — it is the caller's offer, measured against the server-resolved total — but a **currency is not**, so mixed-currency is unreachable from a body at all and only a host resolver can trigger it. `ProblemTest` constructs the two idempotency classes with an **identical message** and asserts different statuses, so a message-decoding regression cannot pass.

**Filament's append-only guarantee rests on `isReadOnly()`, not on a policy.** That closes edit and delete paths in Filament *before* a policy is consulted, which means it survives a host `Gate::before` answering yes to everything — every prior wave's permissive-gate defence was policy-shaped and therefore defeatable by exactly that. Backed by an `instanceof` sweep asserting no `Edit`, `Delete`, `ForceDelete`, `Restore`, `Replicate`, `Associate`, `Attach`, `Detach` or `Dissociate` action exists on either table, with `getRecordActions()` naming exactly `['reverse']`, and by a reflection sweep over the nine `can*` abilities that could otherwise default open. Attaching the plugin to a panel with `hasTenancy()` raises `PanelContextUnavailable` rather than serving unscoped rows.

**Livewire's answer is to make money not be an input.** The shopper offers a tender **for the outstanding balance**, so no money value crosses the wire at all — which sidesteps the entire class of hole the wave's other two packages spend tests defending. No arithmetic on minor units exists anywhere in its `src/`, asserted against the source rather than claimed. Every public property is `#[Locked]`, verified by reflection over the registry. The tender allow-list is **`card`, `gift_card`, `store_credit` only**: `bank_transfer`, `cash` and `instalment` record movements *somebody else witnessed*, so a shopper self-serving one asserts a movement nobody saw — validated server-side, not left to which buttons the form renders.

Two contracts were added by the Livewire package because the domain genuinely had no answer, both unbound by default and 503 when missing. **`AuthorizesOrderAccess`**, because the domain holds no user FK and no tenant column, so "never display another customer's tender" had no mechanism — and it deliberately did *not* use Laravel's `Gate`, since the unanswered gate is permissive and a published contract with no binding fails loudly instead. **`OffersTender`**, because the package may import neither Payment Operations nor Gift Cards and the domain never moves money, so the host moves it and returns a reference or `null`; without that seam the shopper surface has no honest state-changing action and idempotency would have had nothing to attach to.

### Five limits printed rather than implied

**No tenant or site column anywhere.** Host fault 7 is fixed by holding *no* user FK at all rather than by adding tenancy, and scoping stays at the resolver seam. Flagged as a `0.2.0` decision if a deployment needs database-level partitioning.

**Filament's reversal preconditions are mirrored nowhere.** The reason field is not `required()` and the action has no state-based `visible()`, because "only a captured tender, only once, only with a reason" are `ReverseTender`'s invariants and restating them as form validation is the duplication the standard forbids. The cost is that the action is offered on an already-reversed entry and the operator sees the domain's refusal as a notification instead of a greyed-out button. Three tests pin that path.

**`GET /plans/{order}` materialises the plan row**, because the domain publishes no finder and the `-api` rule forbids importing the model. The row is inert — reference and currency, no status, no balance — so the response is identical either way, and a domain-published finder is named as the `0.2.0` path.

**Reversal idempotency is carried by the ledger, not by the key.** `ReverseTender` takes no key and has no column to store one, so an identical replay returns 200 and a second reversal under a different reason is a 409. The `Idempotency-Key` header is still required so every state-changing route has one contract — stated plainly rather than pretended otherwise.

**One resolver call per row** for Filament's balance column: N+1 by construction, documented in `PlanBalance` with the upgrade path being a cache inside the host's resolver, the only place that knows whether a total is cacheable.

**Fifty-two packages now exist across thirteen modules, and none is on Packagist.**

---

## Wave 9 — Tax, and the fact that a tax figure is a claim about the past — ✅ **shipped**

Four packages at `0.1.0`, green on Tests, Install and Compatibility. 453 tests, 82,390 assertions. [#919](https://github.com/liberusoftware/ecommerce-laravel/issues/919) records what shipped.

| Package | Tests | Assertions | Coverage |
| --- | --- | --- | --- |
| `ecommerce-tax` | 143 | 80,552 | 95.4% |
| `ecommerce-tax-api` | 130 | 865 | 100.0% |
| `ecommerce-tax-filament` | 95 | 560 | 99.7% |
| `ecommerce-tax-livewire` | 85 | 413 | 95.7% |

### The rule this module was the exception to

Every wave since wave 3 has carried the same line in its brief: *"Tax is an input — a rate in basis points or an already computed amount. No module looks a rate up, knows a jurisdiction, or compounds."* Eleven modules were built against it. This is the module it was carving out — the one package in the fleet permitted to do all three, and the reason the other eleven never had to.

That ordering was worth having. Tax arrived last among the modules that feed it, which meant its shape was constrained by eleven existing consumers rather than negotiating with them, and not one of them needed changing.

### The host's twelve faults

The largest replaced surface of any wave: `TaxCalculator`, `TaxRate`, `TaxClass`, `EuVat`, `ViesService`, `OssReportService`, two migrations and a config block. Three faults are worth recording beyond the README's list.

**`orders.tax_total` has never been written.** `2024_02_15_000001` created it; `2026_02_16_000001` added `orders.tax_amount`, which is what every code path actually writes. Two decimal tax columns on one table, one permanently zero, nothing in the schema saying which is authoritative — the same shape as wave 8's two disagreeing `payment_status` columns, and found the same way.

**`tax_rates.priority` is written, cast, and sorted by — and never read.** `TaxCalculator::applyRates()` sequences simple-then-compound in collection order and ignores it entirely. A column that exists to sequence the arithmetic and does not sequence the arithmetic is worse than no column: it reads as a control that works.

**`EuVat::STANDARD_RATES` is twenty-seven real-world VAT rates as a PHP `const`**, docblocked *"as of 2025"*. A Finnish rate change was a code deploy. The module ships no rates at all, and says why in its README: a rate baked into a release is a rate that goes stale between releases, and the fleet has no mechanism to hot-fix twenty-seven of them.

### The fact that shaped the module

**A tax figure is a claim about the past, and it must stay true.** Every other number the fleet computes can be recomputed from current state. A tax figure cannot: it was correct under the rates, registrations, exemptions and rounding rules in force at an instant, and all four change. Recomputing an old order's tax against today's data does not check the old answer — it produces a different one and tells you nothing.

So rates are effective-dated and immutable, quotes are append-only evidence, and the third proof in the three-way fold is **reproducing each quote's total from its own recorded evidence with `tax_rate_versions`, `tax_registrations` and `tax_jurisdictions` emptied**. Waves 5, 7 and 8 each proved a fold three ways; this is the first time the third way proves the *evidence is sufficient* rather than that the arithmetic is consistent. A quote that survives its own rate tables being deleted is a quote that can be audited.

### A seam with a default binding, for the first time

Waves 7 and 8 published contracts with no implementation and no default binding, where unbound is 503. `CalculatesTax` deliberately breaks that pattern: it is bound by default to the module's own table-driven implementation, because an external tax provider is a *replacement* for working arithmetic, not a precondition for it.

The interesting half is the refusal. An adapter that returns a bare total is rejected — the quote cannot be assembled from it, and accepting it would put a number into an audit ledger that its own evidence cannot rederive. The seam that has a default is also the seam that is fussiest about what it accepts, and for the same reason.

`ValidatesTaxRegistration` keeps the wave-7 shape, with one refinement: unbound is 503 **on an exemption claim only**. A quote claiming no exemption succeeds with the seam unbound, because the module is not broken — it merely cannot verify a claim nobody made.

### A refusal recorded rather than an approximation stored

An inclusive base against a multi-rate or compound sequence raises `InclusiveSequenceUnsupported`. Backing a sequence out of a gross has no integer solution that also reproduces rate by rate, so any answer would be an approximation — and an approximation in an audit ledger fails the one claim the module makes. Refusing is the correct outcome, and the wave-9 addendum's §5 and §7 were reconciled by carrying an inclusive base and a compound sequence on separate quotes rather than by weakening either.

If inclusive compounding is wanted, it needs a decision on which rate absorbs the residual. That is a `0.2.0` question.

### `isReadOnly()` on a Filament resource enforces nothing

Wave 8 concluded that an append-only guarantee belongs below the policy layer, because a host `Gate::before` answering yes defeats anything policy-shaped. Wave 9 tried to apply that and found the mechanism does not exist: **`isReadOnly()` is a `RelationManager` instance method, not a `Resource` method.** Declaring it on a resource is a comment.

The enforcement is an override of `getAuthorizationResponse()` — the single funnel every `can*()` on a resource passes through — refusing a named ability list before the gate is consulted. The suite installs `Gate::before(fn () => true)`, asserts the gate really is answering yes, then asserts `canEdit`/`canDelete`/`canCreate` are still false. Overriding the funnel rather than fifteen individual methods also means a `can*()` added by a future Filament release routes through it.

`create` stays open on rate versions and registrations, because otherwise the first version of a rate could never be entered — there is no other table it could come from.

### Two lying constraints, one of which CI cannot catch

`Str::ulid()` needs `symfony/uid`, which `illuminate/support` does not require — only `laravel/framework` does. `$request->validate()` is a framework-*foundation* macro, not something `illuminate/http` provides. Both pass every CI job, because the testbench drags the framework in, while being lying constraints for a real consumer.

**`Compatibility` structurally cannot catch these**: the package is root in its own CI, so the constraint it is lying about is never the one under test. That is a gap in the enforcement layer, not an oversight in a package, and `$request->validate()` in particular is likely already shipped in earlier `-api` packages — named here because it is now a fleet audit rather than a wave-9 fix.

### The surfaces each hit a brief contradiction

**`#[Locked]` on every public property versus a typed VAT number.** The presentation standard demands the first universally; the capability demands the second; no `wire:model` satisfies both. Resolved by making the number an argument to `claimExemption()` — held client-side, validated on arrival, never stored — which is the reconciliation, not a workaround.

**Idempotency needed a third fleet answer.** Checkout mints fresh (nothing was committed); Payment Operations refuses (a second authorization moves money). Tax is neither: a second quote authorizes nothing, but one flat key strands a shopper who mistypes a digit. The key is `sha256(nonce | reference | number)` with the nonce minted at mount, so the same number is byte-identical on retry and a corrected number is recoverable.

**"Manages classes and exemptions" had no referent.** A tax class is a string column, not an entity; an exemption is a rate version's treatment or a claim denormalised onto a quote. Neither could have a resource, and the surface says so rather than inventing one.

### Five limits printed rather than implied

- The domain package declares `php: ^8.4` while the testbench requires `^8.5` and CI runs 8.5 — its own floor is a version nothing verifies. Both presentation agents flagged it independently. `0.2.0`.
- The `-api` boundary rule greps `use` statements only, so a fully-qualified inline model reference passes it. Belongs upstream in `package-testbench`.
- The domain's queries return Eloquent models, so `-api` can avoid *naming* them but not handling them. A real boundary needs published read DTOs.
- `Jurisdiction` is not append-only, and `tax_registrations` and `tax_rate_versions` both cascade-delete from it. The surface refuses the delete; a domain-side guard would be better.
- Tenancy has no seam anywhere in the fleet — the domain takes an int from the caller, `-api` reads an attribute off the actor, `-filament` uses `Filament::getTenant()`. Three packages, three answers, no contract.

**Fifty-six packages now exist across fourteen modules, and none is on Packagist.**

---

## Wave 10 — Shipping, and the two kinds of price — ✅ **shipped**

Four packages at `0.1.0`, green on Tests, Install and Compatibility. 494 tests, 3,200 assertions. [#915](https://github.com/liberusoftware/ecommerce-laravel/issues/915) records what shipped.

| Package | Tests | Assertions | Coverage |
| --- | --- | --- | --- |
| `ecommerce-shipping` | 223 | 1,976 | 96.0% |
| `ecommerce-shipping-api` | 96 | 318 | 98.1% |
| `ecommerce-shipping-filament` | 119 | 654 | 99.4% |
| `ecommerce-shipping-livewire` | 56 | 252 | 100.0% |

### A price this module cannot reproduce, and one it must

Wave 9 ended on the claim that a tax figure is a claim about the past, and proved it by reproducing every quote from its own recorded evidence with the rate tables emptied. Shipping cannot do that, and the reason is not a limitation to work around — it is the shape of the domain.

A **derived** price is computed here from rules this module holds: a zone matched, a rate row applied, a weight band, a free-shipping threshold. It is reproducible, and must be. A **quoted** price is an answer a third party gave at an instant, about a future physical movement, to a question only they can answer. Ask again in a minute and the number may differ; ask when the carrier is down and there is no number at all. **Nothing this module records will ever let it recompute one.**

So the three-way proof is a proof about the partition rather than a reproduction:

1. every recorded price is exactly one of derived or quoted — a stored discriminator, never inferred from whether a carrier column happens to be null;
2. every derived price reproduces from recorded rules with the carrier seam ripped out entirely — wave 9's technique, applied only where it is honest;
3. every quoted price survives the rate tables being emptied, provenance intact — which proves it depends on nothing this module can change its mind about, and is therefore why it must be stored verbatim.

### The host's twelve faults

1. **A zone is unrepresentable.** `grep -rn 'shipping_zone\|ShippingZone' app/ database/` returns nothing. `shipping_methods` is seven columns with no destination among them, so every method is offered to every address on earth at one price.
2. **The destination is accepted and thrown away.** `calculateShippingCost($method, $cart, $address)` threads the address into `calculateDistanceRate()`, whose entire body is `return 0;`. `isMethodAvailable($method, $cart, $address)` ignores it too and checks weight alone. Both signatures claim a destination matters; both prove it does not.
3. **Rates are floats** — `decimal(8,2)` cast to `float`, a float multiply for weight, `round(…, 2)` at the end, and `shipping_quotes.amount` re-cast `(float)` at the JSON edge and again at checkout.
4. **Three weight units and no agreement between them.** `products.weight` has no unit column at all; `product_variants.weight_unit` defaults to `'kg'`; `config('shipping.weight_unit')` defaults to `'oz'`; the EasyPost adapter multiplies by 16 only when the config says `lb`. A store in kilograms is quoted as ounces, silently, with a plausible number.
5. **`estimated_delivery_time` is free text** — `required|string|max:255`, so "3-5 days", "next week" and "Tues" are equally valid. An estimate that cannot be compared or resolved to a date is not an estimate.
6. **The evidence for a charged price is deleted on a schedule.** `PruneShippingQuotes` sweeps rows past `expires_at` with a one-day default, `orders.shipping_quote_id` is `nullOnDelete()`, and the migration's own docblock says a carrier quote cannot be recomputed.
7. **Every failure mode is the same empty array.** Missing API key, non-2xx, any `Throwable`, and no carrier configured all return `[]`, and the caller silently falls back to flat methods at a different price. *Live rating is off*, *the carrier is down*, and *the carrier does not serve this address* are indistinguishable.
8. **A config float is added to an authoritative stored quote.** `round((float) $quote->amount + $premium, 2)` — so `orders.shipping_cost` equals no quote that was ever fetched, and nothing records the difference.
9. **Parcels have no dimensions.** The adapter accepts length, width and height and `array_filter`s them out because no caller supplies them. Every carrier prices dimensional weight; the host cannot express a box.
10. **A missing weight is silently zero.** `products.weight` is `->default(0)` and the fold ends `?? 0`, so a product nobody weighed is quoted as a lighter box than exists and the store eats the difference.
11. **`verifyAddress()` calls `https://api.address-verifier.com`** with a config key defined nowhere, and has no caller.
12. **A quote's authorisation predicate is an OR of two weak identifiers, one of them a hardcoded literal.** `resolveQuote()` matches session **OR** user; the headless service passes `''` for the session; `StorefrontSchema.php:391` passes the string **`'api'`** for every headless buyer. No tenant column, no site column.

Two host facts were deliberately kept: persisting a fetched rate and billing the stored amount is correct, and falling back to flat methods is a legitimate deployment. Fault 7 is that the fallback is silent and undifferentiated, not that it exists.

### A seam whose absence is a configuration, not a fault

Waves 7 and 8 published contracts with no default binding, where unbound meant 503. Wave 9 shipped one *with* a default binding. Wave 10 adds the third species: `FetchesCarrierRates` unbound is **live rating being switched off**, which is a common, fully supported deployment and not an error at any layer.

That only works if it stays distinguishable from failure, so the contract never returns a bare list — four types behind one interface, rendered as four visibly different states at both presentation surfaces and four distinct responses at the API. The whole design turns out to hang on one detail: `?FetchesCarrierRates $carrier = null` resolves to null when nothing is bound, because the container catches the `BindingResolutionException` and falls back to the default. Drop the `= null` and it throws instead.

`ResolvesParcels` keeps the older shape — unbound is a 503, because a parcel you cannot measure is not a parcel — and a null weight is refused rather than defaulted, which is fault 10 stated as a rule.

### Where the addendum was wrong

Four things, all found by the agents and all reported rather than approximated around.

**Three carrier outcomes was the wrong count.** "Not bound" is the *absence* of an implementation, so no implementation can return it; the domain synthesises it. Four, not three.

**"No available method" was two conditions wearing one name.** *No zone covers this destination* is a buyer out of area; *a zone matched but nothing is priced in it* is an operator who half-finished a setup. A fourth outcome case, and the Filament coverage widget counts unpriced active zones so the second surfaces before a buyer hits it.

**The parcel seam publishes no basket facts.** `ResolvesParcels` returns weights and dimensions and nothing else — no subtotal, no item count. But free shipping is defined against a subtotal and table rates may band on subtotal or item count, so those forms have no server-side source for the number they need. Passing a client-asserted subtotal would be a price in a body wearing a different name, so the API refuses with a typed `422` instead. **The fix is upstream, in a tagged package**, and is the first wave-10 `0.2.0` item.

**The table-naming rule collides with the unadoptable-table rule.** §1.5 says an invented table carries the module prefix, which for this module yields `shipping_methods` — precisely the host table the brief calls unadoptable. The module ships `shipping_service_levels` so both schemas coexist during migration. This recurs whenever the prefix reproduces a host name and wants settling fleet-wide.

### Two actions that have no legal HTTP shape

`RecordPriceAdjustment` takes an amount or a rate; the three authoring actions take rate amounts and thresholds. Under the rule that no route accepts a price, a weight or a rate, none of them can be exposed — so the API ships no adjustment route and no authoring routes at all, and those live only on the operator surface. Adjustments remain fully readable over HTTP on the price they adjust, with the fold in `total`.

The same rule bent at one place and it is recorded rather than hidden: the Filament rate-preview page accepts an operator-entered parcel weight, because §6.1 demanded the carrier outcomes be visible on a surface that has no shopper quote flow. Quoting is a write, so the preview runs inside a transaction aborted with a private exception and records nothing — asserted.

### Three green tests that assert nothing

The wave's most reusable findings are all tests that pass while checking less than they appear to.

- **Pest's `toThrow()` given a class-string that does not autoload degrades to a message-substring check.** `toThrow(WrongFqcn::class, 'message')` passes on the message and says nothing about the type.
- **`getOriginal()` returns the *cast* value for an enum-cast attribute**, so an immutability guard comparing it against `Enum::Case->value` compares an object to a string and is always false. The guard enforced nothing and its tests were green.
- **`(int) (4.99 * 100)` is 499, not 498.** The float-truncation demonstration only bites for values whose binary form falls short, so the test that documents the entire money rule fails for the opposite reason to the one it teaches if the constant is chosen carelessly.

And one that is not a test at all: **`config()`, `app()`, `auth()` and `now()` are framework-*foundation* helpers, not `illuminate/support`** — the same hole as `$request->validate()`, passing CI for the same reason, and almost certainly already shipped across the fleet. That is now a fleet audit alongside the `$request->validate()` one, not a wave-10 fix.

### Limits printed rather than implied

- **The parcel seam cannot express a subtotal or an item count**, so free-shipping thresholds and subtotal/item-count band axes are unreachable through the API. Upstream fix, `0.2.0`.
- **Derived-price reproducibility holds only while the rules exist.** `shipping_prices` deliberately carries no foreign key to the rule tables and snapshots the zone code, so evidence outlives the rules — but delete a rate and proof 2 no longer applies to prices that used it.
- **Money is fixed at exponent 2** on the Filament surface: the domain's `Money` supports 0–6 and no shipping table has an exponent column.
- **An undefined gate denies**, so a host that configures no abilities gets a dead shopper surface. Correct for something that prices a basket, but adoption is not zero-config.
- **Tenancy still has no seam** — this is the fleet's fourth implementation of an idea with no contract, and wave 10 deliberately did not try to settle it.

**Sixty packages now exist across fifteen modules, and none is on Packagist.**

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
| **3+** — extractions | **Yes before the first tag, no after.** After a tag, demotion breaks every consumer and the honest move is deprecation. **Catalog, Pricing, Inventory Ledger, Cart, Checkout, Orders, Fulfillment, Returns, Payment Operations, Refunds, Gift Cards, Multi-Tender Payments, Tax and Shipping are all past it** — all sixty packages are tagged. Nothing consumes them yet, which is not the same thing | See §2 |

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
