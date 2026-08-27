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

## Wave 11 — Reviews and Ratings, and the three things called "a review" — ✅ **shipped**

Four packages at `0.1.0`, green on Tests, Install and Compatibility. 549 tests, 2,750 assertions. [#907](https://github.com/liberusoftware/ecommerce-laravel/issues/907) records what shipped.

| Package | Tests | Assertions | Coverage |
| --- | --- | --- | --- |
| `ecommerce-reviews-and-ratings` | 233 | 1,110 | 97.1% |
| `ecommerce-reviews-and-ratings-api` | 139 | 597 | 99.0% |
| `ecommerce-reviews-and-ratings-filament` | 80 | 459 | 100.0% |
| `ecommerce-reviews-and-ratings-livewire` | 97 | 584 | 100.0% |

**§1.1.2 named this module by name**, and its condition was already met: *"a duplicate-stack merge lands before its owning module is extracted — Reviews/Ratings especially"*, which is [ADR 0008](./adr/0008-reviews-and-ratings-merge.md), landed in wave 2. It is also the module with the most host code left. Both halves of the sequencing rule point here.

### One fault was fixed in the host rather than waiting for the extraction

The survey's first finding was that `GET /product/{product}/reviews` — unauthenticated, outside the `auth` group — eager-loaded the customer and handed the models to `response()->json()`. `Customer` declares no `$hidden` and carries `email`, `phone_number`, `address`, `city`, `state` and `postal_code`. Anyone could walk incrementing product ids and harvest the postal address of every shopper who had ever left a review.

**Nothing consumed the customer object.** No Blade view, no Livewire component, no JS referenced the route at all — the storefront review page had been deleted in wave 2 as a template that never rendered. The eager load served no caller; it was there because serialising a model graph is the default and nobody chose otherwise.

Fixed in [#1044](https://github.com/liberusoftware/ecommerce-laravel/pull/1044) rather than deferred to the extraction, because the module lands over months and that was live. The rule it leaves behind: **a public route's payload is a publication decision, not a serialisation default.** An explicit projection also means a column added to either table later cannot start appearing by itself — which is the difference between a fix and a control.

### Three things are called "a review", and the host stored all three in one mutable row

This is the wave's shaping idea and every decision falls out of it.

**An expression** is one person, one product, one moment. It is a historical fact, so it is append-only: an edited review is a *new* expression superseding the old, never a changed row. **A moderation decision** is the merchant's answer to *should this be shown* — not a property of the expression but its own record, with an actor, a reason from a closed enum, and a timestamp. **An aggregate** is derived from the first filtered by the second, and is meaningless unless it states the population it summarises.

The host collapses all three into `product_reviews.approved`, a boolean that `approve()` and `reject()` flip in place. So a review approved, retracted and re-approved is indistinguishable from one approved once, nobody can say who did any of it, and — the part that matters — **`Product::getAverageRating()` averages every rating regardless of moderation.** Ratings never got the moderation column that ADR 0008 exists to preserve. Post an abusive review with a one-star score and the review is held for a moderator while the star lands in the public average immediately. The control was real and the number it was protecting was not behind it.

Every published aggregate in the module now carries `sum`, `count`, `scale` and the population that produced it, and there are two named queries rather than one — `DisplayedRatingAggregate` and a staff-only `RecordedRatingTotal`. A rounded float is never stored or published: `4.3` computed from `4.2999` and `4.3` computed from `4.3` are different facts, and a rounded value cannot be re-aggregated across pages without drifting.

### The merge was decided on a capability that does not exist

`CONFORMANCE.md` §5.3 chose `ProductReview` over `Review` partly because *"`is_verified_purchase` is a real capability the other lacks"*. The column exists, the model casts it, `isVerifiedPurchase()` reads it, the GDPR export exports it — and **nothing in the host has ever written it.** It is `false` on every row that has ever existed.

The capability is real and belongs here, but it is a seam, and the interesting part is that the badge is **tri-state**: `verified`, `unverified`, `unknown`. The absence of a badge is a claim shown to a shopper — "not a verified purchase" tells a reader something — so saying it when you simply did not check is a lie of omission, which is precisely what the host ships today. `unknown` means the module could not ask: the seam is unbound, the verifier threw, or the content was syndicated from a platform whose order book this module cannot see. A surface renders a badge for `verified`, renders **nothing at all** for `unknown`, and only renders a negative for `unverified`, tested as three separate states at each surface because that distinction is exactly what a view model flattens.

### An unbound seam is safe when its absence removes a claim, and unsafe when it removes a control

Wave 10 established that an unbound resolver can be a valid deployment rather than a fault. Wave 11 has one of each, and the pair states the rule.

`ConfirmsPurchase` unbound is a **configuration**: a merchant with no order data wired up gets `unknown` everywhere, which is honest. `ScreensContent` unbound is a **503**: a module accepting free text with no screening and no moderator notification is publishing unscreened speech on a merchant's page, and the safe direction to fail in is "not accepting reviews right now". Screening never auto-rejects — it produces a priority and a queue position. **A machine does not moderate speech here; it queues it for a person.**

### The append-only rule is enforced by an index, not by a guard

`reviews_expressions.live_key` is a hash of the natural key of a *live* row — tenant, product, author — nulled on supersede and on redaction, with a unique index over it. So "one live expression per author per product" is a database refusal rather than a check-then-act read, which is the host's own fault (`->exists()` then `create()`, no unique index behind it) fixed at the only layer where it can be.

It also survives `query()->update()`, where model events do not fire and **every append-only guard in this fleet silently does not run**. That is worth generalising: the four waves before this one enforced append-only in model hooks, and a mass update goes straight past all of them. The index is the only backstop that does not depend on the write going through Eloquent.

### There is no 423 in this module

Every wave since 5 has shipped the 409/423 pair, and the pair has been right every time — a permanent conflict and a transient in-flight claim are opposite instructions to a caller. Here nothing takes a transient claim: every guarded write appends a historical fact. So every conflict is permanent, and a `Retry-After` on any of them would be a lie.

Two consequences reached the surfaces without being asked for. The API mints **no replacement key** on a conflicting body under a used idempotency key — the Payment Operations shape rather than Checkout's, reasoned out rather than copied. And the Livewire package generalised it into a resubmittable/spent classification where exactly two failures are resubmittable and neither is a conflict — bad input, and screening unavailable, the case where nothing was written — with a test asserting that no shopper-facing message ever says "try again" or "shortly". A courtesy retry prompt on a permanent refusal is a lie the UI tells on the domain's behalf.

### An idempotency key can be strictly weaker than the constraint already there

The presentation brief said to mint a key when the step is entered and hold it on a locked property. The Livewire agent reported that none of the four shopper actions accepts one, and that this is correct rather than an omission: each already has a unique natural key whose every component is server-supplied. **A key the client holds is a key the client can change**, so layering one over a server-side natural key weakens the guarantee and adds ceremony. The test now in the brief is whether a second, different request could legitimately produce a second row — if it cannot, the database says so.

### A version constraint is a claim nobody checked either

`livewire/livewire: ^4.0` reads as correct and is false: only 4.4.0 permits `illuminate/support: ^13.0`, and every earlier v4 caps at `^12`. CI passes because the testbench resolves high.

That is the same defect class as `$request->validate()` and the `config()`/`app()`/`auth()`/`now()` finding from wave 10 — a constraint nothing exercises — and it is the third instance. What catches it is `--prefer-lowest`, which is why `Compatibility` runs both legs and why widening a constraint means proving the low end resolves.

### Where the addendum was wrong

Three of eighteen host claims, each reported rather than approximated around.

- **The PII fault was stale by about an hour** — the domain agent read the host after #1044 merged and found the whitelist rather than the leak. Both descriptions were true at different times. The reason for a separately-written public projection survives intact and the addendum now says why: the host's fix is a projection one editor has to keep remembering, which is the shape the fault already had.
- **The two disagreeing averages disagree by more than stated.** `calculateAverageRating()` does not average `overall_rating`; it averages all four detail columns separately and composites the non-null ones.
- **The duplicate-insert race is narrower than stated.** The rating written alongside a review goes through `firstOrCreate()`; only the review insert and the rating controller's own insert carry it.

One correction ran the other way, from a presentation agent back at the domain: `ModerationQueue`'s `Pending` is `whereDoesntHave('decisions')`, and a screener escalation *appends a decision* attributed to `system:screening`. So a machine-escalated expression appears under `Escalated` and never under `Pending` — not a defect, but it contradicts a naive reading of "nothing is displayed by arriving, therefore everything starts in the pending queue", and it is pinned by a test rather than left to be rediscovered.

### Limits printed rather than implied

- **No Q&A**, though the epic names it. A question is not one person's experience of a product, so folding it in would have made `product_reference` and `author_reference` mean two things each. It is a different grain and deserves its own decision.
- **No re-verification.** `verification` is decided at write and inherited by revisions, so binding `ConfirmsPurchase` after a backfill does not re-badge existing rows.
- **Helpfulness counters cannot be backfilled from the host**, which records no voters at all — an unavoidable loss, written into `docs/adoption.md` rather than papered over.
- **The moderation queue is filtered by state rather than sorted pending-first.** Ordering "no decision first" across states means ordering on a nullable subquery, and Postgres sorts NULLs last in `ASC` while MySQL and SQLite sort them first. The domain publishes the ordering it can defend — screening weight, then age.
- **No bulk moderation, deliberately.** Every decision names an actor and a reason; deciding fifty things under one reason is how a reason stops meaning anything.
- **No syndication outbound, no media, no cached aggregates.** Import only, text only, every figure computed from rows.
- **Tenancy still has no seam.** Fifth implementation, still no contract.

**Sixty-four packages now exist across sixteen modules, and none is on Packagist.**

---

## Wave 12 — Promotions, and the three things called "a discount" — ✅ **shipped**

Four packages at `0.1.0`, green on Tests, Install and Compatibility. 452 tests, 11,073 assertions. [#895](https://github.com/liberusoftware/ecommerce-laravel/issues/895) records what shipped.

§1's tier diagram was exhausted after wave 5, so the pick is made on what the rule falls back to: dependency and most-existing-code. Catalog already owns categories and collections, and Pricing owns price lists, so the largest *unclaimed* host cluster was Coupon and Discount — two models, two Filament resources, a service, both checkout paths, a tenancy migration and eight test files.

### The whole promotion engine was wired to a form with no fields

`Discount` declares targeting, prerequisites, buy-X-get-Y, free shipping, customer eligibility, allocation method and usage limits. **No service, controller, checkout path or job reads it.** A merchant can configure all of it and no order is ever affected.

It is dead at the other end too: `DiscountResource::form()` returns `->components([//])` — an empty schema, over a table whose `title` is `NOT NULL`. So the Create page renders nothing and saving would fail anyway.

And three of its four relations name schema that does not exist. `orders()` is a `hasMany(Order::class)`, so Eloquent derives `orders.discount_id`; no migration in the repository adds that column. `products()` and `collections()` name `discount_products` and `discount_collections`; neither table is created anywhere. `canBeUsedBy()` calls `orders()->count()` and would raise. **`DiscountModelTest` exercises none of the four** — it tests the constants, the casts and `calculateDiscount()`, and stops.

That is the wave-3 `CartItem::products()` defect exactly: a relation whose foreign key names a column nobody created, green because no test touched it. Twice now, in code written years apart, which makes it a property of the *shape* — a `hasMany` needs no schema to construct, and a test that only asserts nothing threw cannot tell the difference.

### Six mechanisms reduce what a shopper pays; one reaches a total

| mechanism | reaches an order total? |
|---|---|
| `Coupon` + `CouponService` | **yes** |
| `Discount` | no |
| `CustomerGroup::calculateDiscount()` / `qualifiesForFreeShipping()` | no caller |
| `LoyaltyTier::$discount_percentage` | no caller |
| `WholesalePriceTier::$discount_percentage` | yes, but as a *price*, and it belongs to Pricing |
| `ProductBundle::getBundlePrice()` | no caller at all |

Three of the six are merchant-editable in the admin panel today. `Discount::TYPE_FREE_SHIPPING` returns `0` under the comment `// Handled separately in shipping calculation`; nothing handles it separately.

### Three different things are called "a discount"

**An offer** is the merchant's standing rule. **An entitlement** is the evaluation of the offers against one basket at one moment — derived, perishable, never stored. **A redemption** is the historical fact that an offer was spent on an order — append-only, and the thing a usage limit counts.

The host collapses all three. The coupon row *is* the rule and the code; the applied amount is a number in the session; and a use is a `SELECT COUNT(*)` over `orders` joined on `coupon_code`. That last one is why a cancelled order can never give a use back, why a failed payment still spends the coupon, and why `Coupon` cannot express "once per customer" at all even though `Discount` tries to.

A fourth thing was separated from the offer that the host has no name for: **a code is a way of reaching an offer, not the offer itself.** One offer may be reachable by many codes or by none — an automatic discount is an offer with no code. `coupons.code` is the primary key of the concept in everything but name, which is exactly why neither case fits.

### A limit is enforced by a conditional update, not by count-then-insert

The host counts orders, then decides, then inserts — with a `lockForUpdate()` on the `coupons` row protecting a count of a *different* table, which works only because both writers happen to route through it.

The module claims a use with `UPDATE … SET redemptions_used = redemptions_used + 1 WHERE id = ? AND (max_redemptions IS NULL OR redemptions_used < max_redemptions)`, and **zero affected rows means exhausted**. Race-free without a lock. Per-customer and per-order limits are unique indexes. This is wave 11's finding generalised: that wave established a model hook does not fire for `query()->update()`, and the same reasoning says a check-then-act in PHP was never a constraint either.

The counter is a cache, so the module ships a query that recomputes it from the redemption and release tables and a test that the two agree — and the Filament panel surfaces that check on the page rather than burying it in the suite. A cached counter nobody can check is a number nobody should trust.

### The blast radius of an unbound seam is the scope of the thing it controls

Wave 11 stated the rule: an unbound optional seam is safe when its absence removes a **claim**, unsafe when it removes a **control**. Both of this module's seams remove a control — each narrows who qualifies, so treating an unresolvable rule as satisfied gives money away. By wave 11's rule that reads as "503 the request", and **that is wrong here**.

`ScreensContent` controlled every submission, so its absence failed the request. `ResolvesCustomerEligibility` controls *the offers that name a segment* — so its absence must fail those offers and only those. Refusing the checkout of a shopper who was not using a segmented offer, on a deployment that simply has no segments, is a refusal with nothing behind it.

So an offer naming a group, segment or collection is **skipped** with `eligibility_unresolvable`, every other offer evaluates normally, and the skip is **visible to the merchant as distinct from non-qualification** — because a merchant whose VIP offer has silently applied to nobody for a week must be able to find out why without reading logs.

The two seams turned out to be the same rule for different reasons, which the addendum had not anticipated: customer eligibility unbound means *we cannot tell whether this shopper qualifies*, a question about the person; product grouping unbound means *we cannot tell which lines this offer targets*, whose honest answer is "no qualifying lines". Two code paths, deliberately one merchant-visible outcome.

### The allocation is the contract, and it must sum exactly

An entitlement publishes a **per-line minor amount** plus a separate shipping reduction, never a single number. Tax reads it — wave 3 established the engine spreads a discount pro-rata and that untaxable lines count in the denominator, a correction made *for* a caller that only had one number — and Refunds reads it, because refunding one line of a discounted order needs to know how much of the discount that line carried.

Distributing a reduction across lines leaves a remainder in minor units, so **the remainder rule is published, not implementation detail**: largest-remainder, ties by ascending line index, proved by a property test over many baskets. A caller that re-derives it differently produces a line total that disagrees with the order total by a penny, forever.

### A per-customer limit and a release cannot both be a monotonic sequence

The addendum asked for two things that pull against each other: enforce per-customer limits by unique index, and let a release give a use back. A monotonic sequence in a unique index does the first and blocks the shopper forever on the second.

Resolved by making `customer_sequence` a **constraint slot rather than a fact**: releasing nulls it, NULLs collide freely in a unique index, so the slot returns while the redemption, its lines and its release all survive. It is the one column on an append-only table that is mutated in place, and it is documented as a slot so nobody later reads it as "which use this was".

### The API package refused to ship an endpoint rather than ship it unscoped

Four of the domain's published entry points take a bare id with no tenant argument — `ListOfferHistory::revisions()` and `::statusDecisions()`, `RecomputeOfferStatus`, `RecomputeRedemptionsUsed` — and the domain publishes no tenant-scoped offer-by-id read at all.

The `-api` package closed the release case by carrying the order reference in the route and verifying membership through the tenant-scoped `ListRedemptionsForOrder` first, tested both ways round. It could not close the history reads without importing a model, which its own boundary forbids, so **it deferred those endpoints rather than shipping them unscoped**. An unshipped endpoint is recoverable; a leaky one is not. The domain fix is the first item of Promotions `0.2.0`.

The Filament panel reaches the same queries through a tenant-scoped resource, so the panel is not exposed — which is the distinction worth keeping: the hole is in what the domain *publishes*, not in what is currently reachable.

### A Livewire return value is a surface

Nothing public on the Livewire component returns an `Entitlement`. Livewire ships an action's return value back to the browser, so a public method handing one back would let a crafted request read `skipped` and `refusedCodes` — the merchant-only half — **without ever rendering the view**, past every rendering test. Memoised on a private property, with a source-level guard test over `src/` and the view.

### Where the addendum was wrong

- **§2.8 overstated the fault.** `getActiveCoupons()` handles a null `max_uses` correctly; its disagreement with `isValid()` is only about the date bounds. One predicate, two implementations, agreeing on one of its two null cases and not the other — a better argument for the predicate existing once than the version originally written.
- **§2.7 was stale on one clause.** `Coupon::orders()` filters `orders.store_id` as well as joining on the code; the cross-merchant half was fixed in wave 1.5. Every consequence listed still holds.
- **§6 was framed around one seam** and needed splitting into two, above.
- **§5.4 and §5.5 contradicted each other**, resolved above.
- **The exception table implied failures the HTTP surface can barely reach.** `QuoteBasket` throws nothing — refusals and skips come back inside the `Entitlement`, and both usage limits are enforced at quote time — so a refused code is a `200` with a refusal object, not a `422`.

### Three fleet-wide corrections found by building

- **Trap 22's own constant was wrong, and how is the lesson.** It said only `livewire/livewire` 4.4.0 permits `illuminate/support: ^13.0`. It is **4.2.0**, checked against each tag's `composer.json`. The cause: Packagist's minified `p2` feed omits `require` keys unchanged from the entry after them, and the omitted keys inherit from the *newer* release — so read naively it shows every 4.2–4.3 release carrying 4.4.0's constraint. **The tool you reach for to check a constraint handed back a constraint that was never published.** The rule was right and its example was a fresh instance of it; wave 11's `^4.4` pin is narrower than it needed to be.
- **`is_callable([$model, 'tokenCan'])` is always `true`.** Eloquent implements `__call`, so duck-typing Sanctum's ability check passes and then dies inside the call — in middleware, where no `callAction()` mapping can turn it into an answer. `method_exists()` is the check.
- **`assertCountTableRecords()` cannot see a custom-data table.** `getAllTableRecordsCount()` falls through to the Eloquent query rather than the `Table::records()` source, so it passes or fails for reasons unconnected to what is on screen.

### One fault was fixed in the host rather than waiting for the extraction

`/cart/apply-coupon` is unauthenticated, and the route already named the threat — *"distinguishable valid/invalid responses make this brute-forceable to enumerate discount codes; cap attempts per IP"*. The mitigation chosen was a per-IP throttle, and the three responses stayed distinguishable: one for a code that does not exist, one for a code that exists, and one that **printed the coupon's configured minimum spend** to a caller who does not hold it.

A throttle limits how fast an oracle can be asked; it does not stop it being one, and the codes merchants actually issue fall well inside ten guesses a minute. Wave 7 settled this for gift cards — enumeration is closed by making every wrong answer the same answer — and the rule had not travelled ten routes. One refusal now, in [#1046](https://github.com/liberusoftware/ecommerce-laravel/pull/1046), with the trade stated rather than buried: a shopper holding a real code loses the "spend a bit more" prompt, because the server cannot tell them from a guesser.

### Limits printed rather than implied

- **No scheduler**, so `starts_at`/`ends_at` gate evaluation but write no status decision: an offer past its end date still reads `Active` while every quote refuses it as `ended`. `OfferStatusReason::Exhausted` is declared and never written for the same reason.
- **Per-customer slot allocation fails closed under a genuine race** — two concurrent claims can compute the same slot, the index rejects one, and it reports `CustomerLimitReached` where a further slot was free. A spurious refusal under contention, never an over-grant.
- **Erasure is published and surfaced nowhere.** `RedactCustomerFromRedemptions` takes a customer reference as input, which is the one value the panel refuses to make searchable or filterable, and it wants its own authority and audit trail. It belongs on a privacy surface, not among the discount tools.
- **Revert-to-revision is refused on purpose.** Reverting *is* authoring the old terms again — new revision, new actor, new time. A button would imply the old revision came alive.
- **Bundles and Attribution were split rather than absorbed**, since the epic's scope names both. A **kit** is a sellable thing with its own identity — the host's `ProductBundle`, which carries a `product_id` — and belongs to [#827](https://github.com/liberusoftware/ecommerce-laravel/issues/827). A **bundle promotion** creates nothing sellable and belongs here. Promotions owns the fact that an offer was redeemed on an order; it does not own "what caused this sale", which is [#821](https://github.com/liberusoftware/ecommerce-laravel/issues/821). Limits are rules a merchant wrote down and are owned here; risk scores are judgements and belong to [#857](https://github.com/liberusoftware/ecommerce-laravel/issues/857).
- **No bulk code issuing, no pagination on the order redemption list, no 423 anywhere** — nothing in this module takes a transient in-flight claim, so every conflict is permanent and a `Retry-After` would be a lie. Second module in a row where that is true.
- **Tenancy still has no seam.** Sixth implementation, still no contract.

---

## Wave 13 — Commerce Customers, and the three things called "a customer" — ✅ **shipped**

Four packages at `0.1.0`, green on Tests, Install and Compatibility. 387 tests, 4,382 assertions. [#840](https://github.com/liberusoftware/ecommerce-laravel/issues/840) records what shipped.

§1's tier diagram was exhausted after wave 5, so the pick is made on what the rule falls back to: dependency and most-existing-code. Customers is forced on both. It is the largest unclaimed host cluster — 57 files, 467 model LOC across four models, two GDPR services, a segmentation service and a Filament resource — and it is the module wave 11 and wave 12 each named as the neighbour that would hurt. Reviews shipped an author reference and Promotions shipped `ResolvesCustomerEligibility`, and **neither had anything to point at**. Customer Accounts ([#846](https://github.com/liberusoftware/ecommerce-laravel/issues/846)) is the self-service surface over this file plus other modules' records, so it depends on this one and waits.

### Three different things are called "a customer"

The host has one table named `customers` and asks it to be three things at once.

| | What it is | Whose it is |
| --- | --- | --- |
| A **person** | the human, one per deployment, may have no login at all | identity's — we hold an opaque reference |
| A **file** | one merchant's record *about* that person | **ours**, one per (tenant, person) |
| An **audience** | a segment, a metric, a score — derived and perishable | mostly [#821](https://github.com/liberusoftware/ecommerce-laravel/issues/821)'s |

And a fourth the host has no word for: a **group** is a *declared* membership an operator attaches, with a `joined_at` and an `expires_at`; a **segment** is a *derived* one nobody attaches, with a `last_calculated_at`. The host gave them the same word and different columns.

### Two correct controls that cannot both be true of one table

`customers.user_id` is **globally unique** — a person has one file. `customers` is **store-scoped** — a merchant's data is a merchant's. Each is right on its own.

Together, on a second storefront, the scope hides the person's existing row, so `User::getOrCreateCustomer()`'s `firstOrCreate` attempts an insert and the unique index raises. **A shopper who has bought from merchant A cannot get a customer record at merchant B**, and `ReviewController` is the first thing that calls it. This is what collapsing the person into the file costs, and it is not a bug in either control.

The extraction's answer is the aggregate identity `(tenant, person_ref)`, with `person_ref` nullable so guest files collide freely — NULLs are distinct in a unique index, which is wave 12's `customer_sequence` property reused rather than rediscovered.

### An accessor that reads as a filter, three times

Fixed in the host ahead of the extraction — [#1048](https://github.com/liberusoftware/ecommerce-laravel/pull/1048).

`Customer::getActiveGroupsAttribute()`, `CustomerGroup::getActiveCustomersCount()` and `scopeWithActiveMembers()` each wrote the live-membership predicate as `->where('expires_at', '>', now())->orWhereNull('expires_at')` with no grouping. `AND` binds tighter than `OR`, so what reached the database was

```sql
WHERE customer_id = 12 AND expires_at > now() OR expires_at IS NULL
```

— which SQL reads as `(customer_id = 12 AND expires_at > now()) OR (expires_at IS NULL)`. The right-hand side is constrained by nothing, so **a customer's "active groups" included strangers' groups** and a group's active-member count counted every other group's members. A membership with no expiry is the ordinary case, so this was never an edge.

Four tests pin it, and **two of the four pass against the old predicate**. That split is the finding: a test that sets up one customer and asks "is my group here" passes either way. `CustomerGroupModelTest` covered the discount arithmetic and the active scope and none of the three expiry paths.

### One column name, four meanings, and one of them is an authorization check

`ProductReview.customer_id` and `ProductRating.customer_id` mean `customers.id`. `ReturnRequest.customer_id` means `users.id` — the controller writes `$request->user()->id` and then authorizes with `abort_unless((int) $returnRequest->customer_id === (int) $request->user()->id, 404)`. And `orders.customer_id` is a foreign key to `customers` that nothing populates, which `OrderHistoryController`'s own comment records. `AnalyticsService`'s top-customers report joins on it, so the report is empty and nothing says so.

An access check comparing a column whose meaning depends on which table it sits on is one rename away from being a vulnerability.

### A phone number is not arithmetic

`$table->integer('phone_number')`. Leading zeros gone, `+44` gone, spaces and parentheses gone, and most international numbers overflow. The Filament form declared `->numeric()->required()->maxLength(255)` over it — `maxLength` is inert on a numeric input and `required` contradicts the nullable column. The extraction stores a string as given, with the dialling context beside it, and normalises nothing. The panel uses a plain text input: a `tel` rule rejected `+44 (0)161 496 0000`, which is the same mistake one layer up.

### A lifetime value is not a number

`Customer::getTotalSpentAttribute()` sums `orders.total_amount` with no currency filter and no store filter, and `isVip()` compares the result to a literal `1000`. An order in EUR and an order in GBP were added together.

`LifetimeValue` is a **currency-to-money map**, and there is no code path in any of the four packages that folds it into one figure. If a caller wants one number they convert, and conversion is not ours.

### Nothing derived is stored, and the host shows why

`CustomerMetric::recalculate()` computes its own conclusions from the row it is about to overwrite: `calculateSegment()` and `calculateRetentionScore()` both read `$this->lifetime_value` and `$this->days_since_last_purchase` **inside the argument array of the `update()` that replaces them**. A customer crossing 1000 becomes `vip` on the second recalculation, never the first.

So `total_spent`, `order_count`, `lifetime_value`, `retention_score` and `customer_count` are absent from the schema entirely. The purchase-history seam supplies the first three on demand.

### The blast-radius rule, applied to a decoration

Wave 12's rule — *the blast radius of an unbound seam is the scope of the thing it controls* — took a second form here. `ResolvesPurchaseHistory` unbound fails the purchase-history **read only**, and it renders `Not available` rather than `0`, because **zero is a lie a merchant will act on**. A *bound* resolver reporting no purchases renders `0`, and that zero is honest. `ResolvesPersonIdentity` unbound is a decoration, and a decoration's absence decorates less — nothing refuses. Neither may be called from a write path: a profile edit that fails because Orders is down is a control with no reason behind it.

### The three-way custody proof, and the seam that has no tenant

Promotions published `ResolvesCustomerEligibility`; we publish `ListGroupsForPerson(tenant, person_ref)`; the host binds them. No package depends on the other and the domain suite passes with Promotions absent.

The addendum called that binding "a four-line adapter" and **it cannot be four lines.** The published contract is `isCustomerIn(string $customerRef, string $groupRef): bool` — with **no tenant argument** — so the host adapter has to obtain the tenant from request context itself. One that forgets answers across every merchant on the deployment, which is this module's own worst host fault rebuilt on purpose. And the contract's `$groupRef` is documented as "an opaque group **or segment** reference": segments belong to [#821](https://github.com/liberusoftware/ecommerce-laravel/issues/821), unbuilt, so an offer configured against a segment is answered `false`. Safe — an eligibility control failing closed refuses the offer rather than giving money away — but silent, and **no module owns that half after this wave**.

### Where the addendum was wrong

Nine places, all found by building and none worked around.

- **§7's adapter, above** — the sharpest, and the one I verified myself against the Promotions repository rather than accept.
- **§5.7 contradicted itself.** "Consent carries `granted_at`/`withdrawn_at` **and** is append-only" cannot both hold if withdrawal edits the row that granted. Resolved as one row per decision with exactly one column set; the current state of a channel is its latest row.
- **§5.10 did not say what erasure does to an address**, and the obvious reading contradicts §4's requirement that an order still say where it shipped. Redaction keeps the postal lines and nulls the recipient and company: a street with no name on it is a place rather than a person. **The module offers no address deletion at all**, and a host asking for one is asking for something it refuses.
- **§5.3 did not say what happens to the losing `person_ref` after a merge.** Keeping it leaves two live references, or violates the unique index outright. It is released to null and both refs are recorded on the merge row — so the old reference resolves to **nothing**, and the merges table is the redirect.
- **§5.5 left "two live memberships of one group" open, and it cannot be an index.** The NULL-collision property makes every never-expiring membership distinct — the same fact §5.2 relies on. It is an action-level guard.
- **§5.9 could be read as permitting a `lifecycle_state` column** beside the ledger. The base brief settles it: state is a fold, and opening a file writes `null → Prospect` so the fold is total. That is also why the panel ships no lifecycle filter.
- **§2.15 told the agents to carry the host's fail-closed condition whitelist forward, and it had no target.** The only host code with it was `CustomerSegment`, which §1.2 gives to #821. The instruction moves with the module.
- **§8's `-filament` scope list omitted redaction** while the dispatch required building it. Built, guarded, and loudly labelled.
- **§5.4's "nullable default flag per usage"** is one `is_default` boolean beside `usage`, so per-usage defaulting is an action property rather than a schema one — the same shape as the membership guard.

### Two gaps in the domain package that the surfaces found

Both are `0.2.0`, and both are recorded because a surface refused to paper over them.

**There is no way to obtain a handle on an existing group.** `JoinCustomerGroup` and `LeaveCustomerGroup` both require a `CustomerGroup`; the only producer is `CreateCustomerGroup`; `ListGroupsForPerson` returns names. Combined with the `-api` model ban, *joining an existing group by name is not expressible through the published surface*. The API bridged it by reading the model type off the action's own parameter via reflection, labelled "delete this the moment the domain publishes a group query". **And there is no group update action at all**, so a typo in a group name is unfixable — the panel forces `canEdit()` false by name rather than reach past the boundary with a raw write.

**`CreateCustomerGroup` is a plain `create()`**, so a duplicate name escapes as `Illuminate\Database\UniqueConstraintViolationException` — a raw framework exception leaving a boundary that publishes thirteen typed ones.

### The domain's address actions are tenant-scoped, not person-scoped

`SetDefaultAddress`, `SupersedeAddress` and `ReviseAddress` each assert the address's file belongs to the tenant, and **never that it belongs to the caller**. That is correct for a merchant surface, where an operator acts on any file in their tenant. On a shopper surface it means a browser can name another shopper's address id at the same merchant and the domain accepts it. `-livewire` re-resolves every id through that file's own address list before calling anything, with six tests — three methods against another shopper, three against another merchant.

A locked `person_ref` is not an authorization story. It says which file the component is about; it says nothing about the ids the component is handed.

### A test suite at 98% coverage shipped a trap the brief already had

The domain package went out at `0.1.0` with 140 tests, 2,496 assertions and 97%+ coverage carrying **trap 1** — `CustomerAddress` has no `protected $attributes = ['is_default' => false]`, and its own docblock types the property `bool|null`. A green suite cannot see a column that is `null` where it should be `false`, because nothing inside the package ever assigns it to a typed parameter. The surface package does, and it pays: the `TypeError` is **swallowed by Livewire**, so the action wrote nothing, threw nothing, set no error and left the component's own failure state null. It reads as a domain problem.

That generalises trap 19, which was written as a Filament concern and is not one: `app.debug` belongs on in **every** test case that renders a Livewire component.

### Limits printed rather than implied

- **No unfiltered file index.** Nothing in the domain enumerates a tenant's files, so `GET /files` requires a `tag` or a `person_ref`. A merchant API with no index is a surprise, and it is the domain's gap.
- **`person_ref` is required to open a file over the API.** A guest file is nullable-ref by design and collides freely, so a retried create would silently open a second one. Guest files arise from orders, and Orders is not this API.
- **Idempotency: no key anywhere, asked per endpoint rather than decided once.** Twelve of thirteen API writes are protected by a natural key or a state guard. The one that is not — `POST /addresses`, where two identical addresses are a legitimate second row — still gets none: a key needs a store, an expiry policy, conflict semantics and a client contract, and what it prevents is one redundant row that authorises nothing, moves no money, and is corrected by superseding it. Third module in a row with no 423 and nothing transient.
- **The merge redirect has no owner.** Walking `commerce_customers_merges` is what a surface would need to land on the survivor, and no query publishes it. Neither surface built the table walk; presentation writing its own SQL over a domain table is the boundary this programme exists to hold.
- **Erasure and export are cross-tenant with no actor concept**, so the whole authorization burden lands on the transport. Hence `customers:erase` as a scope of its own — a token that can edit a phone number must not be able to redact a person — and a runbook that says to issue it to a privacy desk and never to a storefront. `RedactPerson` returns file ids across every tenant; the API returns a count.
- **Files are addressed by reference, never by `person_ref`.** A URL ends up in browser history and every access log between here and the server. `TenantMismatch` returns byte-identical to `CustomerFileNotFound`, pinned by a by-value test — a 403 would confirm the row exists.
- **Segments, metrics and wholesale approval left behind**, per §1.1.3: segments and rollups to [#821](https://github.com/liberusoftware/ecommerce-laravel/issues/821), wholesale status to [#824](https://github.com/liberusoftware/ecommerce-laravel/issues/824) except the tax registration, which is a fact about the file. Privacy *orchestration* is [#846](https://github.com/liberusoftware/ecommerce-laravel/issues/846)'s; we publish erasure and export of our own rows and nothing else.
- **Tenancy still has no seam.** Seventh implementation, still no contract — though this is the first module where every table, pivots included, carries `tenant_id` in its own right.

**Seventy-two packages now exist across eighteen modules, and none is on Packagist.**

---

## Wave 14 — Attribution and Analytics, and a system in which nothing was measured — ✅ **shipped**

Four packages at `0.1.0`, green on Tests, Install and Compatibility. 506 tests, 9,054 assertions. [#821](https://github.com/liberusoftware/ecommerce-laravel/issues/821) records what shipped.

| Package | Tests | Assertions | Coverage |
| --- | ---: | ---: | ---: |
| `ecommerce-attribution-and-analytics` | 182 | 6,155 | 98.7% |
| `…-api` | 162 | 1,913 | 99.1% |
| `…-filament` | 108 | 721 | 97.8% |
| `…-livewire` | 54 | 265 | 100.0% |

Namespace `Liberu\Ecommerce\AttributionAnalytics\`. **Thirteen tables**, all `attribution_analytics_`-prefixed, every one carrying `tenant_id` in its own right, **zero foreign keys and zero float or decimal columns anywhere in the schema**.

### Nothing was measured

The host's analytics module has five tables and no writer for any of them. `AnalyticsEvent`'s five static tracking methods — `trackPageView`, `trackProductView`, `trackAddToCart`, `trackPurchase`, `trackSearch` — have **no callers**: the class is referenced by itself and by a `hasMany` on `Customer` and on `Product`, so `analytics_events` can only ever be empty. `ProductPerformance::record*` is referenced by the model and one unit test. `conversion_funnels` and `conversion_events` have **no model at all** — schema with no code on either side. `ABTestingService` and `CustomerSegmentationService` are referenced only by their own tests. The one tracking call that runs in production is `ProductInteraction::track()`, from the recommendation engine, and it belongs to [#898](https://github.com/liberusoftware/ecommerce-laravel/issues/898).

That is unusually free — no data to preserve, no behaviour to keep bug-compatible — and it has one consequence the build had to internalise. **The host cannot tell you what an event is for.** Every column in `analytics_events` is a guess nobody ever tested against a reader, so the column list was not ported; the schema was derived from what a funnel, a rollup, a segment and a forwarded conversion actually need to read, and anything with no reader was refused.

### Three different things get called "analytics", and one of them is not ours

This is the boundary, and getting it wrong is how 271 lines of Orders' and Inventory's business rules ended up filed under an analytics name.

1. **A record of something that happened.** Ours.
2. **A restatement of somebody else's current state** — "revenue last 30 days", "low stock", "top customers". **Not ours.** `AnalyticsService::getSalesTrends()` encodes what revenue means: net of refunds, `payment_status = 'paid'`. That is Orders' definition. Absorbing it forks the definition, and then two modules disagree about revenue and both are authoritative.
3. **A derived judgement** — a segment, a retention score. Ours, but never stored as truth.

So `AnalyticsService` was **not extracted**. It stays in the host and splits among Orders, Catalog and Inventory when those are extracted. A module that takes everything filed under its own name inherits every definition somebody else owns.

### An index over a value the callee mints is a uniqueness constraint on nothing

The host's `analytics_events` has no unique key of any kind, so a retried tracking call double-counts silently. The extraction added `unique(tenant_id, event_ref)` — and it fixes nothing, because `RecordEvent` does `'event_ref' => Reference::mint()` and neither its signature nor `EventDraft` gives a caller any way to supply one. A retried recording call still writes a second row.

The `-api` package found this by testing it rather than reading it, and then made the harder call: it declined to accept an idempotency key it could not enforce. **A key the surface accepts and cannot honour is worse than no key**, and an idempotency table inside an adapter is an adapter owning a table. Recording is documented as at-least-once on the operation itself, and the real fix is named — a caller-supplied `eventRef` in the domain's `0.2.0`.

### A rate is where a float gets back into a package that bans them

Every wave since 8 has forbidden float money, and the boundary suite greps for the word. A conversion rate is the one place the ban is awkward: it is genuinely a ratio, and the obvious implementation is a float. `FunnelMeasurement::completionBasisPoints(): ?int` resolves it, matching the tax-rate convention.

It is **nullable**, and that is the wave-13 rule arriving somewhere new: a funnel nobody entered has no completion rate, and rendering `0%` there is a number a merchant will act on. Both surfaces render "not measured", and the Filament package asserts that `0.00%` never appears.

### A whitelist whose entries are SQL fragments is an escape hatch that looks like a control

The host sanitised operators before they reached `whereRaw`, and the addendum said to keep that. The build found the whitelist unnecessary — no operator reaches SQL at all here, because comparison happens in PHP against an answer a seam gave us — and replaced it with a backed enum whose values are `eq`, `ne`, `lt`, `lte`, `gt`, `gte` rather than `<=`. The property the host was protecting survives; its mechanism does not. The API publishes those tokens and rejects `<=` with a 422.

### Immutability and erasure are in direct tension, and the resolution is one narrow door

Events are append-only, and erasure has to rewrite them. Both are load-bearing, and a module cannot have both by wishing. The `updating` hook refuses any column outside `{person_ref, search_term}`; `RedactPerson` is the single place performing a bulk update, writing exactly the columns the hook would have permitted; a boundary test forbids raw SQL outright so no second bulk-write path can appear. Recorded in `docs/domain.md` rather than left as an apparent contradiction between two rules.

### The custody proof was written about queries, and relations are where it leaks

Every domain query states its tenant in a `where`, which is why a `TenancyPolicy` written for this module reached `0.1.0` at 0% coverage and was deleted: "fetch by reference, then assert tenancy" has no code path.

But `TrackingSession::events()` is `hasMany(AnalyticsEvent::class, 'session_ref', 'session_ref')` while the migration keys `unique(['tenant_id', 'session_ref'])` — unique **per tenant**, not globally. Two merchants can hold the same session reference and the relation joins both. `SegmentDefinition::runs()` is the same shape. Nothing in the domain's 182 tests exercises either, because the domain never reaches through a relation.

**A surface is where that gets paid for**, and the Filament package is what found it: both relation managers scope the query, and each has a two-merchant test with a deliberately shared reference that fails without it. The wave-13 lesson repeated — the test that catches a tenancy leak is the one that sets up a *second* merchant and asserts absence.

### Consent is prior to the write, and it is not wave 13's consent

Commerce Customers owns *may we contact you*, per channel. This module owns *may we observe you* and, separately, *may we forward what we observed*. Different subject, different lawful basis, different retention — and two permissions, not one, because a shopper permitting measurement while refusing forwarding is the common case. The host stored IP address, user agent and referrer indefinitely with no consent record and no prune command, while consent was in this epic's own stated scope.

Two decisions the module made that the host had no way to express. **No IP address is stored at all** — not raw, not hashed, not truncated — because nothing in the scope needs one, and removing the data removes the retention problem rather than managing it. And a consent answer is `?bool` starting `null`: "never asked" and "said no" are opposite facts, and a `bool` cannot hold the difference.

### A day is a timezone, and the host's was nobody's

`DATE(order_date)` buckets in the database server's timezone; `ProductPerformance::record*` buckets on the app's. A merchant in Auckland and one in Los Angeles read the same "daily revenue" as two different days and neither report says which. Every rollup row here carries the IANA timezone it was bucketed in, the half-open range `[from, to)` it covers, the definition version that produced it, and when it was computed. `getSalesTrends()` is also MySQL-only — `DATE_FORMAT`, `YEARWEEK` — and the test database is SQLite, so it cannot run under test, which is a large part of why none of the rest was noticed.

### Where the addendum was wrong

Six, all found by building, none worked around.

- **§6 said "two contracts, both consumed" and described one.** The second was Promotions' contract answered by a query we publish, not something we consume. The real second seam is `DeliversForwardedConversion` — forced by §5.11, since delivery that must be separate and retryable and not performed by us needs something to perform it. Without it the outbox can never drain and "what we forwarded" is unwritable.
- **§4 never said how a rate is expressed**, and the obvious implementation breaks the float ban. Basis points.
- **§2.21's operator whitelist was unnecessary**, per above.
- **§5.1 and §5.10 contradicted each other**, per above.
- **§5.2's stored session span is a stored derivation**, which §5.5 forbids for everything but rollups. Shipped as decided, but self-healing: `RecordEvent` recomputes both bounds from the events inside the insert's transaction, so the events stay the authority and the columns cannot drift.
- **§7.1's own claim that `unique(tenant_id, event_ref)` fixed the host's double-counting was half true**, per above — corrected in the brief so wave 15 does not inherit it.

And one thing the addendum implied wrongly about the code: §5.11 reads as though creating a forwarding intent were a separate operator step. `RecordEvent` calls `IntendForwarding` internally; only delivery is separate.

### Two gaps the surfaces found in the domain

Both handled the same way wave 13's group-name gap was — by closing the ability rather than reaching past the boundary with a raw write.

- **`RegisterEventName` has no update path**, so a retention window cannot be changed once declared.
- **`RegisterDestination` always sets `is_active` true** and nothing unsets it, so a destination cannot be deactivated.

### Four more host faults, found while building the replacement

`analytics_events` has no unique key of any kind. `conversion_events.occurred_at` uses `useCurrent()`, so the host's only event-time column defaults to insert time — exactly the conflation between *when it happened* and *when we stored it* that this module's schema separates. `ProductPerformance`'s date-cast comment is load-bearing: a Carbon cast yields `'Y-m-d 00:00:00'`, which `updateOrCreate`'s lookup never matches, and it documents a fix for a race the `updateOrCreate(...)->increment(...)` pair still leaves open. And `customer_metrics.customer_segment` is an **`enum` column**, so adding a segment is a schema migration — a fifth reason the host's four segmentation mechanisms could never converge.

### Fixed in the host ahead of the extraction — [#1050](https://github.com/liberusoftware/ecommerce-laravel/pull/1050)

`CustomerSegment::calculateMembers()` built its candidate set from a bare `User::query()` — every user on the deployment — and `sync()`ed the result, so one merchant's segment filled with another merchant's shoppers. Not a corner case: `IsTenantModel` writes `team_id` on create and installs no read scope, so `segments:calculate`'s `CustomerSegment::active()->get()` returns every merchant's segments and recalculates each against every merchant's users. One run of one command crossed every tenant boundary on the deployment.

Two things that fix taught. **The naive tenant predicate does not hold**: under `match_type = 'any'` the conditions are OR-ed and AND binds tighter, so `WHERE (customer is this merchant's) OR (condition)` leaves the right-hand side qualified by nothing and selects everybody again. Same shape as [#1048](https://github.com/liberusoftware/ecommerce-laravel/pull/1048), two waves running, and again a test written only against `match_type = 'all'` passes either way.

And **failing closed was the wrong call**. An unstamped segment matching nobody reads as the safer option and is not: it converts a leak into a silently empty segment, and a merchant acts on an empty segment as confidently as on a wrong one. Null matches null. A control that fails closed has to fail *visibly*, and there was nowhere in that query to say so.

### Limits printed rather than implied

- **Recording is at-least-once**, per above, until the domain accepts a caller-supplied reference.
- **No scheduler and no delivery worker.** How often a merchant wants an aggregate is a merchant's decision; the panel shows when a rollup was taken and over what range, and never implies one refreshes itself.
- **`ComputeCampaignRollup` loads matching session references into PHP** — `pluck` then `whereIn` — because joins are forbidden. Correct at merchant scale, wrong at a million sessions, and the upgrade waits until somebody has the row counts.
- **No cursor pagination** on the three listings: the domain publishes `limit`, capped at 500, and adding a cursor in the transport would mean querying tables the API must not touch.
- **No batch recording endpoint.** `RecordEvent` and `RecordEventJob` are both per-event, so batching only in the transport would make partial failure ambiguous — worse than the round trips.
- **No widgets in the panel**, deliberately: every tile considered would either restate a rollup without its stamp or imply a live number.
- **A/B testing was not taken.** Per §1.1.3 it goes to [#885](https://github.com/liberusoftware/ecommerce-laravel/issues/885) — assigning a variant is a delivery decision, and this module measures experiments rather than running them, the variant being a dimension on an event. Three defects go with it: `selectRandomVariant()` assumes weights sum to 100 and sends the remainder to a fallback variant, `traffic_allocation` is declared and never read so a test set to 10% traffic runs at 100%, and `getConversionRates()` mutates one query builder across four reads so its numbers are right only for the current line order.
- **The segment half of `ResolvesCustomerEligibility` now has an owner.** Wave 13 answered the group half and recorded the segment half as owner-less; `IsPersonInSegment` answers it. The contract still carries **no tenant**, so the host adapter resolves one from request context, and widening it is a Promotions `0.2.0`.

**Seventy-six packages now exist across nineteen modules, and none is on Packagist.**

---

## Wave 15 — Customer Accounts, and nineteen modules with four erasure verbs and no request — ✅ **shipped**

Four packages at `0.1.0`, green on Tests, Install and Compatibility. 478 tests, 5,400 assertions. [#846](https://github.com/liberusoftware/ecommerce-laravel/issues/846) records what shipped.

| Package | Tests | Assertions | Coverage |
| --- | ---: | ---: | ---: |
| `ecommerce-customer-accounts` | 148 | 2,076 | 97.6% |
| `…-api` | 109 | 1,228 | 99.5% |
| `…-filament` | 86 | 716 | 97.5% |
| `…-livewire` | 135 | 1,380 | 100.0% |

Namespace `Liberu\Ecommerce\CustomerAccounts\`. **Seven tables**, all `customer_accounts_`-prefixed, every `tenant_id` NOT NULL, zero foreign keys. No host table adopted.

### Nineteen modules, four erasure verbs, no request

This is the wave's whole justification, and it was read off the shipped repositories rather than inferred.

| Module | Erasure | Signature | Scope | Export |
| --- | --- | --- | --- | --- |
| Commerce Customers | `RedactPerson` | `(personRef, reason, actorRef, ?occurredAt): array` | **cross-tenant** | `ExportPersonFile(personRef): array` |
| Attribution and Analytics | `RedactPerson` | `(tenantId, personRef, ?actorRef, ?reason): RedactionRecord` | tenant | `ExportPersonRecord` |
| Reviews and Ratings | `EraseAuthor` | `(tenantId, authorReference): ErasureReport` | tenant | `AuthorExport` — a **query**, not an action |
| Promotions | `RedactCustomerFromRedemptions` | `(tenantId, customerRef): int` | tenant | **none** |
| Orders, Returns, Payment Operations, Shipping, Tax, Gift Cards | **none** | — | — | **none** |

Four verbs. Four return types. Three names for the same subject. **Two different scopes** — Customers erases a person everywhere because a person is a person; Attribution erases within one merchant. "Erase me" already meant two things. And six modules that hold personal data published neither half.

Every one of those modules was right on its own terms. Nobody was coordinating, because **coordination is a capability and it had no owner**. That is the thing this wave adds, and its first job was not to erase anything — it was to make the request a thing that exists.

### Three things get called "your account"

The **login** (identity's), the **file** (Commerce Customers', settled in wave 13 as `(tenant, person_ref)`), and the **claim** — the evidence that a person is entitled to see or act on a record another module holds. The host has the first two and no word for the third.

This module owns claims, which makes it the smallest-data module in the fleet and the one with the most neighbours. The discipline that follows is one sentence: **we never read another module's tables to satisfy a claim; we hold the claim and ask the owner.** The host's `GdprErasureService::erase()` is the counter-example — one method body writing ten tables across six modules that now publish their own erasure.

### A request that cannot reach every participant does not complete

The blast-radius rule, fourth extension. Wave 12: an unbound seam refuses the one offer it controls. Wave 13: it renders "Not available", never `0`. Wave 14: a recalculation with an unavailable input writes nothing. **Wave 15: a request whose participants did not all answer is reported as partial, with the participants named, and never as done.**

"Your data has been erased" is a claim about all of it. The host returns `{"success": true}` from a function that could not have known.

Two decisions carry that rule, and neither was in the brief:

- **A participant that publishes only one half is a full participant that answers "unavailable"** — not one excluded from the case. That is the majority case, and excluding them is the tempting reading that makes every export look complete. So an access request with Promotions registered is *honestly partial*.
- **This module is not a participant of its own registry.** A coordinator that appears in its own registry reports its own erasure as one more green tick, and stops erasing itself the moment somebody edits the entry out.

### The tenancy exception belonged in the reach, not in the column

The wave brief said a deliberately cross-tenant case could carry a null `tenant_id`. That is wrong twice: `where('tenant_id', null)` compiles to `is null`, so one careless query lists every deployment-wide case as an orphan, and it throws away the fact of *where* the case was raised.

`tenant_id` is NOT NULL on all seven tables and means the merchant the case was raised at. **`scope` alone decides reach.** The `-filament` package asserts, by scanning stripped sources, that no null-tenant predicate exists anywhere in it.

### Relations are where the tenancy work leaks, three waves running

Wave 14 found `TrackingSession::events()` joining on a reference that is unique only per tenant. This wave found two more, and both were found by **surfaces**, because a domain whose every query states its tenant never exercises its own relations:

- **`RevokeShare` has no tenant predicate at all.** `SavedListShare::query()->where('reference', $shareReference)->first()` — a share reference from one merchant revokes at another. Both the `-api` and `-livewire` packages guarded it independently, one by re-checking `owner_ref`/`tenant_id` after the fact and one by scoping through an owner-scoped lookup. The domain action is still cross-tenant.
- **`SavedList::items()` and `shares()` restate the tenant as `->where('tenant_id', (string) $this->tenant_id)`.** Eloquent builds `withCount()` and `whereHas()` from a *fresh* instance where that key is null, so `(string) null` is `''` and the predicate matches nothing. Counts come back zero for every row. It fails closed, which is the right direction, but it fails.

**The custody proofs keep getting written about queries.** That is the correction to carry into wave 16: prove the relations too, with a second merchant holding a deliberately identical reference.

### A scope mismatch concluded as a success

`CommissionParticipant` writes `outcome => Completed` alongside `scope_mismatch => true`, and `RequestProgress::isSatisfied()` is `participants > 0 && completed === participants` — it never consults `mismatched`. So a case where everyone answered, one of them at the wrong scope, reported satisfied.

That is exactly the silent success the brief's §5.5 forbids, sitting inside the module written to forbid it. `-livewire` found it by trying to render completion honestly and closed it at the surface; the domain should conclude such a case `Partial` at `0.2.0`.

### Silence is per-kind, and the domain's word for it is not

`ParticipantStatus::isSilent()` is `! $bound || $handles === []`. Promotions — the case the whole participant design exists for — publishes an erasure and no export, so `isSilent()` is **false** even though it is silent for every access request ever opened. The panel shipped a separate column rather than invent a fact the domain does not publish. `isSilentFor(RequestKind)` is a `0.2.0`.

### A token returned "exactly once" cannot be returned to the browser

The brief said a surface returns the guest-claim token exactly once. For the shopper-facing flavour that is self-defeating: returning it to whoever filled the form in defeats the proof-of-possession entirely, because that person is precisely who the mailbox check exists to test.

`-livewire` publishes a `DeliversClaimProof` contract instead, so the token goes to the host and never becomes component state — asserted against the `wire:snapshot`, not the rendered body. Unbound delivery closes claiming **before** the action runs, so it costs no rate-limit attempt and mints no orphan token.

### Two refusals that had to stay indistinguishable

`ClaimRefused` covers wrong reference, wrong address and wrong proof as one condition, because the module was never told which — separating them *is* the disclosure. The `-api` package pinned it with a byte-for-byte comparison across all three.

It nearly shipped distinct codes for its five 404s and caught it in review: that would have put the oracle straight back, since "not your case" would have become distinguishable from "no such reference". All five share one code and one message.

### The actor is the subject

Neither the merchant nor the person is accepted in a path, query string or body. The merchant for the fleet's standing reason; **the subject because a `subject_ref` in a body lets any credential open an erasure for a stranger**, and this module holds no login and no directory with which to check that it should have been allowed to.

That is also what makes the missing listing endpoint coherent rather than a hole: the API surface is the person's own, and a desk that reads across subjects is the panel's job.

### Three grounds for having no idempotency key, and the second is new

Reviews and Ratings said no because natural keys covered every write. Payment Operations said yes because a fresh key would authorize a second payment. This wave adds a third: **`POST /order-claims` and `POST /saved-lists/{list}/shares` mint a one-time secret, and replaying under a key would require persisting that secret to serve the retry** — which is exactly what the token rule forbids. A key there is a mechanism whose implementation violates the property it was added to protect.

The one write with no natural key is opening a privacy request, where a dropped response leaves a duplicate case that is visible, withdrawable and cheap.

### Fixed in the host ahead of the extraction — [#1052](https://github.com/liberusoftware/ecommerce-laravel/pull/1052)

The export and the erasure disagreed about what "everything" is, in **both** directions, while both docblocks claimed symmetry. `customer_segment_members` was exported and never erased. The wishlist was erased and never exported. And `customer_metrics` — lifetime value, average order value, order and item counts, first and last purchase dates, a predicted next order value and date, a segment label and a retention score — was in **neither**: a complete behavioural profile, invisible to both halves of the person's rights at once, and the row a person is most likely to have meant when they asked to be erased.

It survived two test files because each service was tested alone, and alone each one looks complete. `GdprSymmetryTest` sets up one person with a row in every store of personal data and checks both services against that same set — and its last case asserts the **invariant** rather than an instance, so a table added to only one service fails.

Erasure now also clears `wishlist_share_token`, which the scrub had missed.

### Where the addendum was wrong

Five, all found by building.

- **§5.12 put the tenancy exception in the column instead of the reach**, per above.
- **§6.1 never decided what a half-publishing participant is**, and the tempting reading reintroduces the defect §5.2 exists to stop.
- **The addendum never said whether this module is a participant of its own registry.** It is not.
- **§5.13 contradicted §5.8** — "no token is read or written here" cannot be true of a module whose claim completes with a token. Read as being about Identity's material; the claim secret is ours, stored as a fingerprint, compared with `hash_equals`, cleared on use and on expiry, and absent from the event.
- **§5.5's "recorded mismatch, not a silent success" is not what the code does**, per above.

### A twelfth host fault, and one that only matters now

`OrderHistoryController`'s own comment states that `orders.customer_id` is "a FK to the unrelated customers table, **never populated**" — while both `GdprErasureService::scrubOrders()` and `GdprExportService::orders()` do `orWhere('customer_id', $customer->id)`. Two files in the host disagree about whether that column is ever populated, and **the erasure is the one betting it is**.

`WishlistController::share()` also re-mints the per-user token on every call, silently killing every previously shared link with no record of it — the concrete reason a share had to become its own aggregate with its own revocable token.

### Limits printed rather than implied

- **No queue layer and no console commands.** Which queue and what backoff are the host's decisions, and a command that auto-concludes at a deadline is a policy choice an operator should make. The runbook says the host schedules it; no surface implies a sweeper.
- **No listing endpoint in the API**, per the absence of a listing query — documented in the OpenAPI description rather than improvised.
- **No cooling-off period and no notification to the address being erased.** Both need a clock and a mail the module does not have. Withdrawal is available until conclusion, which is a different promise, and every surface is tested for not implying the stronger one.
- **A shopper can create a share they cannot revoke** until `SavedListView` carries its share references — host fault 2.2 reproduced one layer up, in the module built to fix it.
- **`PrivacyRequestView::toArray()` publishes `tenant_id`** — right for the panel, wrong for the API, which strips it. A surface using `toArray()` naively would leak it.
- **Gift registries recorded as unowned.** 272 lines across four models with no controller, no route and no resource, reachable only from the two GDPR services — a feature that can be exported and erased but never created. No epic in the plan names it, and it was not absorbed to make this module look bigger.
- **Saved lists yes, saved carts no.** A list holds product references and survives a price change; a cart holds priced lines and does not. Saved carts are Cart's.

**Eighty packages now exist across twenty modules, and none is on Packagist.**

---

## Wave 16 — Loyalty, and a programme nobody can earn a point from — ✅ **shipped**

Four packages at `0.1.0`, green on Tests, Install and Compatibility. 506 tests, 6,766 assertions. [#866](https://github.com/liberusoftware/ecommerce-laravel/issues/866) records what shipped.

| Package | Tests | Assertions | Coverage |
| --- | ---: | ---: | ---: |
| `ecommerce-loyalty` | 196 | 3,521 | 97.6% |
| `…-api` | 127 | 1,163 | 100.0% |
| `…-filament` | 81 | 833 | 96.7% |
| `…-livewire` | 102 | 1,249 | 100.0% |

Namespace `Liberu\Ecommerce\Loyalty\`. **Eight tables**, all `loyalty_`-prefixed, every `tenant_id` NOT NULL. None of the host's six adopted.

### A programme in which nobody can earn a point

The host has six loyalty models, six tables, a notification and five test files. Outside those tests there is **not one call site**. No controller, no service, no observer, no listener, no route, no command. `addPoints()`, `redeemPoints()`, `expirePoints()`, `LoyaltyReward::redeem()`, `calculatePointsEarned()` and `qualifiesForTier()` have no caller anywhere in the application.

`customers.loyalty_tier_id` — the column wave 13 deliberately left behind as "Loyalty's column on our table" — is written by nobody and read by nobody. `Customer` does not name it in `$fillable` and declares no relation to it. `LoyaltyPointsEarnedNotification` is never sent, and links to `/loyalty/rewards`, which is not a route.

Everything else in the survey follows from nobody ever having run it: no tenancy on any of the six tables; a mutable `balance` beside the ledger that moves it; three write paths with no transaction; a check-then-act redemption; `min_points_redemption` enforced nowhere; a `qualifiesForTier()` that returns `true` for a person with no points and no spend, because both thresholds default to zero and the comparison is an `||`.

### Points expire, so the balance is a replay and not a fold

This is the wave's contribution to the design record, and it is a **correction to an earlier wave rather than an addition**.

Gift cards established that a balance is a fold over an append-only ledger with no `balance` column, and proved the fold *order-independent*: every contribution is a sum or a flag, both commutative and associative, so folding the same entries in any order gives a bit-identical answer.

**Expiry breaks that.** Points sit in lots, and which lot a redemption consumed decides which points are still there to expire next month. Two ledgers holding identical entries in different orders give different balances at a future date. So the balance is still derived — there is still no column — but it is a **replay over an ordered ledger**, not a sum over a set, and the consumption order has to be *stored* because it cannot be recovered from the entries afterwards.

The brief said that much and got the proof wrong. With a deterministic ordering rule over lot *attributes*, allocation over a fixed lot set genuinely **is** order-independent: permuting rows the way gift cards' `FoldTest` does gives identical answers, and a test built that way reads as a refutation of the whole idea. The order-dependence lives in the **interleaving** — a redemption written before a lot exists cannot consume it. `ReplayTest` therefore replays *operations* rather than permuting *rows*, moving only a redemption's position between two lots with different expiry dates: the 1 March balance is **0** one way and **100** the other, and the same pair with the expiry dates removed gives the same answer both ways. The second half is what locates the boundary instead of merely asserting it.

Generalised: **a derived figure is only order-independent if nothing in the domain expires.**

### An expiry that cannot say which lot it consumed does not run

The blast-radius rule, fifth extension. Wave 12: an unbound seam refuses the one offer it controls. Wave 13: it renders "Not available", never `0`. Wave 14: a recalculation with an unavailable input writes nothing. Wave 15: a request whose participants did not all answer is reported as partial, naming them. **Wave 16: a ledger that cannot be replayed gets no balance rendered for it — not a clamp, not a guess, and not a number taken off a total.**

The host's `expirePoints()` is the counter-example, and it is honest about it: it clamps each expiring lot to the running balance under a comment naming the missing per-lot tracking. The clamp is right about the danger and wrong as an answer, because a clamped expiry cannot say *which* points went.

Three distinct detections turned out to be needed, not one: a debit with no consumptions, a debit drawing on a lot not yet earned, and a lot drawn past its size. The middle one catches the interleaving fault mechanically. All three are tested, and all three surfaces render the entries and the fact rather than a bare flag — and suppress every derived figure to an em dash rather than passing zero through.

### The remedy for a three-wave-old rule was itself a defect

For three waves the standing instruction to surface agents has been *prove the relations, not only the queries* — because tenancy kept leaking through the one path nobody wrote a `where` on. This wave found that the obvious remedy is wrong in the other direction.

`->where('tenant_id', (string) $this->tenant_id)` on a relation is correct through a loaded parent. But `withCount()` and `whereHas()` build the relation from a **fresh instance** whose `tenant_id` is `null`, so the predicate becomes `where('tenant_id', '')` and **every count silently reports zero** — the mirror image of the leak, and just as wrong. Written without the cast it is worse: `where('col', null)` compiles to `is null`, listing exactly the orphan rows the scope exists to hide.

No domain suite can catch it, because every call site inside a domain package goes through a loaded parent. It needs `withCount()` or `whereHas()`, and those are what a *surface* reaches for.

`module-ecommerce-customer-accounts` ships the unguarded form in four relations, including `OrderClaim::attempts()`, which joins on `order_reference` with no foreign key — so that predicate is the only thing standing between two merchants' claims on the same order number. Not currently exploitable, and that was checked rather than assumed. Tracked as [#1057](https://github.com/liberusoftware/ecommerce-laravel/issues/1057), with the guarded form this wave ships and proves in both directions.

### A natural key is only as good as its scope

The best-founded decision in the brief produced the wave's sharpest defect, [module-ecommerce-loyalty#1](https://github.com/liberusoftware/module-ecommerce-loyalty/issues/1).

Idempotency here is a **natural** key — the cause exists before we do, so there is nothing to mint and nothing for a client to hold or change. Reviews reached the same answer for the same reason, and it is better than an idempotency token on every axis. But the key shipped as `(tenant, programme, cause_ref, kind)` with `membership_id` left out, and a cause is unique **per subject**, not per programme. Two people can be awarded under one referral campaign, one promotion, or one goodwill batch.

So awarding to subject B under a cause subject A already used writes nothing and **returns A's entry** — A's reference and A's point count, from a call that looks like it succeeded. Nothing is misplaced; the damage is entirely in the return value.

It was not caught because every award test used one membership. That is the same blind spot the fleet's relation proofs were written to close — *a second subject holding a deliberately identical reference* — showing up on a uniqueness constraint instead of a relation. The `-api` package refuses the case with `409` rather than publishing the wrong entry.

### Contested placements, and the tenth capability the epic listed

The epic named ten capabilities and four belong to other epics. Split rather than absorbed, and each rival notified:

- **Referrals** ([#900](https://github.com/liberusoftware/ecommerce-laravel/issues/900)) — the *relationship* is theirs, the *reward* is ours. We never learn who referred whom.
- **Fraud controls** ([#857](https://github.com/liberusoftware/ecommerce-laravel/issues/857), [#858](https://github.com/liberusoftware/ecommerce-laravel/issues/858)) — velocity limits on our own ledger are ours; scoring a person is not, and no risk verdict is stored here.
- **Liabilities export** ([#903](https://github.com/liberusoftware/ecommerce-laravel/issues/903)) — the figure is ours and nobody else can compute it, since it is a fold over our ledger. The report is theirs. We publish a query, not a spreadsheet.
- **Tiers you pay for** ([#870](https://github.com/liberusoftware/ecommerce-laravel/issues/870)) — a tier you *reach* is ours, a tier you *buy* is theirs. Different objects sharing a word.

And the one that shaped the boundary: **`LoyaltyTier::$discount_percentage` is not extracted.** A tier discount is an *offer*, and Promotions has owned offers since wave 12. Loyalty publishes the standing; somebody else decides what it is worth. `IsSubjectInTier` has exactly the shape Promotions' shipped `ResolvesCustomerEligibility` asks for — `isCustomerIn(string $customerRef, string $groupRef): bool` — so a tier reference *is* a group reference.

**Neither module depends on the other, and the host binds one to the other.** This is the first time in the fleet that two shipped modules meet through a contract one of them had already published, rather than through a new one invented for the occasion. The binding is a host concern precisely because R5 forbids the dependency.

### Smaller things worth keeping

- **`points_multiplier` is deliberately not shipped.** Legitimately ours — a rule about our own ledger, unlike the discount — but nothing in this wave exercises it, and **an unexercised earn rule is a lying constraint**. Recorded as a column and a branch.
- **The Filament floor question is settled, and the brief had the cause wrong.** Every tag from `v5.4.0` to `v5.6.5` declares `illuminate/contracts: ^11.28|^12.0|^13.0`, so `^5.4` is the true floor — but `--prefer-lowest` resolves to 5.6.5 regardless. The "5.6.5 under prefer-lowest" the presentation brief attributed to wave 15's narrower `^5.6` is a property of the fleet's whole dependency set. Declaring `^5.4` is free; no later wave needs to pin higher.
- **`RewardUnavailable` is one exception class over six named constructors**, five meaning *stop asking* and one (`needsADeliverer`) meaning *nothing is bound yet*. Opposite instructions in one type, separable only by decoding a message — the defect wave 4 recorded. Both surfaces resolved the sixth case earlier instead: a value-carrying reward is not offered at all while no deliverer is bound.
- **A read path routed through a write action.** `SubjectStatement` takes a `Membership` and nothing published resolves one from `(programme, subject)`; the only non-writing route is `EnrolSubject::find()`, a static query helper on a write action. Used rather than improvised around, and queued as a published query.
- **`SubjectStatement` hard-codes `lotExpiresOn: null`** on every allocation though the field exists and the allocator fills it elsewhere. Harmless in a panel; on a shopper's statement, "your points expiring soon" is the most useful thing there is. Both surfaces render the lot's expiry exactly once, on the earning line, and never infer one under a spend.
- **No idempotency key on the API, and the third distinct reason for that answer.** Reviews said no because natural keys covered it; Payment Operations said yes because a fresh key authorises a second payment; Customer Accounts said no because serving a retry would mean persisting a one-time secret. Here: every write has a natural key, so a client-held key is strictly weaker than the one already there.
- **The API's authorization splits by whose subject, not by read-versus-write.** Wave 15 refused a body-supplied subject everywhere and was right to; Loyalty cannot follow that all the way, because points are earned by something outside the module and an API that could not award them would reproduce the fault that shaped the wave. The merchant is still never accepted anywhere, and the split is enforced mechanically rather than by review.
- **Every public property on all four Livewire components is `#[Locked]`** — the writable set is empty. The only shopper input is a reward reference passed as an argument and validated against the catalogue on arrival.

**Eighty-four packages now exist across twenty-one modules, and none is on Packagist.**

---

## Wave 17 — Dropshipping, and a supplier chosen by the shopper — ✅ **shipped**

Four packages at `0.1.0`, green on Tests, Install and Compatibility. 462 tests, 7,658 assertions. [#853](https://github.com/liberusoftware/ecommerce-laravel/issues/853) records what shipped.

| Package | Tests | Assertions | Coverage | PHPStan |
| --- | ---: | ---: | ---: | ---: |
| `ecommerce-dropshipping` | 174 | 3,551 | 98.7% | 5 |
| `…-api` | 137 | 1,297 | 100.0% | 8 |
| `…-filament` | 85 | 2,046 | 99.5% | 8 |
| `…-livewire` | 66 | 764 | 96.0% | 10 |

Namespace `Liberu\Ecommerce\Dropshipping\`. **Seven tables**, all `dropship_`-prefixed, every `tenant_id` NOT NULL. The host had none to adopt.

Picked by §1's rule with the tier diagram exhausted — most-code-first among the remaining epics, at 14 files and 2,264 lines. Categories and Navigation measures larger and is not the pick its size suggests: `Category` shipped inside `module-ecommerce-catalog` in wave 3, and what remains of that epic is CMS-owned navigation.

### The shopper chose the supplier

The host's dropship routing was a request parameter, in both checkout paths:

```php
// CheckoutController.php:303
'is_dropshipped' => $request->has('dropship'),
// CheckoutController.php:359-360
$supplierId = $request->input('supplier_id', 'dropxl');
```

A hidden form field decided that an order was drop-shipped, and a second named the supplier who would be paid to ship it. `HeadlessCheckoutService` did the same from the GraphQL input.

It could not have been otherwise, and that is the finding rather than the field. **There is no supplier entity anywhere in the host** — no `suppliers` table, no model, no relation from any product. `config/dropshipping.php` holds three literal keys (`supplier1`, `supplier2`, `dropxl`) with an endpoint set and an API key each, global to the install. Nothing in the system records which products a supplier supplies, at what cost, or in how long, so there was no data a routing decision could have been made from. Asked to route, the code asked the only party present.

The epic's first named capability is *supplier/source routing*. The module's first table is the one the host did not have: a supply offer, tenant-scoped, linking an opaque product reference to a supplier with a cost in minor units, a lead time and an availability stance. Routing then stops being a question and becomes a fold over rows.

Twelve of the eighteen host faults follow from the same absence. Cost is never recorded anywhere, so margin on a drop-shipped line is unknowable after the fact. `supplier_tracking_number` was added to `orders` and **written by nobody** — shipment sync, the epic's fifth capability, does not exist. Acknowledgement is an HTTP 2xx at the moment of transmission, with no later confirmation and no way to learn that a supplier accepted and then rejected. No lead time is recorded, so no promise is made and none can be measured.

### An idempotency key the other party mints is not a key

The host's queue job says exactly the right thing and cannot do it:

```php
// DispatchDropshippingOrder.php:50-52
// At-least-once queue: if this order was already placed with the supplier,
// a retry must not place it again (double fulfilment / double cost).
if ($order->supplier_order_reference) { return; }
```

`supplier_order_reference` arrives in the supplier's response. A timeout **after** the supplier accepted the order leaves the column null, the queue retries, and real goods ship twice at the merchant's cost. The guard is right about the danger, and the value it tests cannot exist at the moment the danger does.

Generalised, and it is the wave's contribution to the fleet's idempotency rule: **a key minted by the party you are protecting yourself against is not a key.** The remedy is to write the row first and carry its reference outbound, so the thing that identifies the attempt exists before the attempt does. A purchase is persisted before transmission, the request carries that reference, the provider's own reference is recorded on acknowledgement — and a timeout leaves the purchase `transmitting`, where the offered action is **reconcile**, never retry. All three surfaces enforce the absence of a retry mechanically rather than by convention: there is no transmit route in the API at all, and `-filament` asserts `assertActionDoesNotExist` on both `draft` and `transmitting` plus a source grep for `TransmitPurchase`.

### Two keys, answering different questions

The addendum said the purchase key was `(tenant_id, supplier_id, purchase_reference)` and called it natural. It is not natural — **we mint `purchase_reference`**, so it is exactly the kind of key the paragraph above rejects.

The natural key is `(tenant_id, supplier_id, order_ref)`: the order exists before this module does, which is the property wave 16 identified and this wave nearly lost by writing the rule down slightly wrong. The minted reference is separately unique and is the **transmission** key — the thing an outbound request carries so a retry is recognisable at the other end.

Both are needed and they are not substitutes. The first answers *have we already decided to buy this*; the second answers *is this the same attempt*. Wave 16 established that a natural key must be scoped to the subject rather than the parent; this wave adds that a module can need two keys, and that calling both of them "the idempotency key" is how one of them ends up minted.

### The person-keyed query is the one that leaks the most

The `-livewire` package went looking for the shopper's own facts and found there is exactly one query keyed on something a shopper owns: `ExportDestinationRecord`, the GDPR export. It publishes `expectedCost`, `actualCost`, `supplierReference`, `providerReference` and the per-line supplier SKU.

`FindPurchase` and `PurchaseStanding` both need a purchase reference no shopper-facing host can obtain. So a shopper surface must index off the **erasure and export** query and then remember what to drop — and every surface has to remember separately.

This is a new shape and it generalises. A subject-access query is built to be *maximal* — the whole record, by design, because a partial answer to "what do you hold about me" is a wrong answer, which is the rule waves 14 and 15 established. A shopper-facing read is built to be *minimal*. Pointing the second at the first is the natural move when only one query is keyed on a person, and it inverts the guarantee. **A module that publishes an export query and no person-keyed read has published exactly one door, and it is the wrong one.** Tracked as [module-ecommerce-dropshipping#6](https://github.com/liberusoftware/module-ecommerce-dropshipping/issues/6).

### The blast-radius rule, sixth extension: the PII rule closed the door it was protecting

Wave 12: an unbound seam refuses the one offer it controls. Wave 13: renders "Not available", never `0`. Wave 14: writes nothing. Wave 15: reports partial and names the participants. Wave 16: renders no balance for a ledger that cannot be replayed. **Wave 17: a rule about what a module may hold can make its own principal action unreachable, and the surfaces are where that shows up.**

`TransmitPurchase` requires a `Data\Destination` — name, address lines, postcode, country, email, phone. The module stores none of that; a purchase carries an opaque `destination_ref`. So the action asks its *caller* for the address, and every surface is forbidden to have one. The `-api` package therefore shipped **no transmission endpoint**, which is the most obviously expected endpoint in the package, and documented an in-process call for the host instead.

The rule was right and the mechanism was one seam short. A `Contracts\ResolvesDestination` — host-bound, `destination_ref` → `Destination`, unbound by default like the other two — makes the no-PII boundary structural instead of a thing each caller is trusted to honour. [#5](https://github.com/liberusoftware/module-ecommerce-dropshipping/issues/5).

### Three shapes for four outcomes

`ReconcilePurchase` has four honest answers: no reporter bound, this provider cannot be asked, asked and nothing had happened, and resolved. `ReconcileOutcome` carries three.

The missing one matters because it is the one that needs a person: *asked, and the supplier says nothing has happened* is a purchase still genuinely outstanding, and it comes back `resolved: true`, indistinguishable from *asked, and the answer confirmed what we knew*.

Two surfaces found it independently and recovered it two different ways, which is the tell that it belongs in the type. `-api` leans on a purchase nothing has happened to still being `transmitting`, so `PurchaseState::needsReconciling()` separates them — and that recovery fails for a purchase in any other state. `-filament` snapshots the prior state and diffs, which is why `Render::reconcile()` takes a `PurchaseState $before` argument it should not need. The domain's own `docs/domain.md` §7 already names four things in prose. [#4](https://github.com/liberusoftware/module-ecommerce-dropshipping/issues/4).

### Not shipping something is a decision, and this wave made it eleven times

Dropshipping is a merchant capability with a thin shopper edge, and the `-livewire` brief asked for the judgement rather than a component count. It shipped **one** component and declined four, which is the smallest shopper surface in the fleet and the right one:

- **"Sold by"** — the most-requested dropshipping feature, and a publication of the merchant's supply chain to anybody who can place an order. `PurchaseView::$supplierReference` is read on every request and never reaches a view.
- **Anything derived from cost** — a "you saved" figure is the merchant's cost of goods. Asserted by rendering a purchase that carries every cost field and grepping the HTML for all of them.
- **A despatch estimate on a product page** — one query away via `SupplyOffersFor`, and it leaks that the merchant holds no stock. It is also the surface that invites a "choose a faster supplier" control beside it, which is the host's fault rebuilt with better manners.
- **A shopper subject-access page** — for the reason in the section above.

`-filament` declined a sourcing preview (its inputs are order lines the host owns, and a form that took them would be a second place an order is described), erasure and export (a person spans more modules than this one; a per-module erase button is how somebody gets erased from four of six), and any dashboard widget. `-api` declined a transmission endpoint, a plan-accepting endpoint (a caller-supplied plan is a caller-supplied supplier), and any idempotency key. Three members with no reachable caller were deleted rather than shipped untested, and one unreachable `DateTimeInterface` branch with them.

### GitHub Actions was never the only test runner

Every wave since wave 0 has been built under a rule this repository states twice: *"Neither PHP nor Composer exists on this machine"* and *"GitHub Actions is the only test runner"*.

The second half is false, and has been for all sixteen prior waves. **Docker is installed here and its containers resolve DNS perfectly well.** `docker run --rm composer:2` and `php:8.5-cli` reach Packagist, and run install, Pint, PHPStan, Pest and `pecl install pcov` for coverage. Every gate in this wave was green locally before it was pushed; CI only confirmed it.

The first half is true and is the whole of the cause: PHP's libcurl is built against c-ares, which ignores the `options use-vc` that makes `curl`, `git` and `gh` work here. That is a property of the **host PHP binary**, not of the machine, and a container brings its own. The inference from *the host PHP cannot resolve* to *nothing here can run a suite* was never checked, and it survived sixteen waves because the fallback worked: a push to a runner is a slower loop but not a broken one, so nothing ever failed in a way that made anybody re-read the premise.

The same shape as wave 0's Composer note, which recorded *"composer hangs here"* and was wrong about the cause and therefore about the remedy. Both were blockers stated as symptoms, and a blocker recorded by its symptom stays a blocker for as long as nobody reads the error. This one was recorded by its *consequence*, which is worse, because a consequence sounds like a conclusion.

### The ratchets were not ratcheting

`package-tests.yml` takes `phpstan-level` and `coverage-threshold` as inputs precisely so each package sets its own from a measured baseline. `ecommerce-loyalty` set `1` and `80`, and being the most recent package, it became the template.

Measured this wave: the domain package is clean at **5**, `-api` and `-filament` at **8**, `-livewire` at **10** — the maximum. What holds loyalty at 1 is three one-line fixes: two `@return Builder<Model>` narrowings on `getEloquentQuery()` and one `Collection` covariance. Nothing about a Filament or Livewire package caps it anywhere near 1.

A ratchet copied is a ratchet stopped. The value is only a ratchet if the *next* package measures itself rather than inheriting; otherwise the fleet's floor is set forever by whichever package happened to be written first. Both briefs now say so, and the four packages here carry their own measured figures.

### Smaller things worth keeping

- **A missing type is where a validated value goes to fail late.** `RegisterSupplier` validates neither the currency code nor the timezone: `currency: 'gbp'` is stored as-is and throws `InvalidArgumentException` at **routing** time, inside `SourcingPlanFor::purchases()`, to an operator doing something else entirely. `Money` and `PublishSupplyOffer` both validate carefully; the record that feeds them does not. The panel currently upper-cases on arrival and offers a `DateTimeZone::listIdentifiers()` select, which puts the guarantee in the one place the next surface will not inherit it. [#2](https://github.com/liberusoftware/module-ecommerce-dropshipping/issues/2).
- **An action that returns half its answer.** `RoutePurchases::__invoke()` returns `list<Purchase>` and discards the `UnsourcedLine`s beside them, so a caller using the published action rather than the two-step `SourcingPlanFor` + `fromPlan()` silently ships part of an order. [#1](https://github.com/liberusoftware/module-ecommerce-dropshipping/issues/1).
- **Two identifier namespaces searched as one.** `FindSupplier` matches its single argument against `reference` **or** `code` with no ordering, and nothing constrains the two columns against each other, so one supplier's `code` equal to another's `reference` returns an arbitrary row — within a tenant, where the scope cannot help. [#3](https://github.com/liberusoftware/module-ecommerce-dropshipping/issues/3).
- **A supplier's error code can carry the supplier's name.** `ProblemReport::$code` is validated as a short token, which forbids prose but not `acme_address_invalid`. Safe on a merchant panel, unrenderable to a shopper, and nothing in the domain said which. `-livewire` drops the code entirely and renders one neutral sentence.
- **`costVariance()` returns `null` for two opposite situations** — the supplier has not stated a cost (resolves itself), and the supplier invoiced in a currency the offer was never priced in (somebody must go and fix it). Refusing to invent a rate was right; saying only "no answer" loses which kind.
- **A denylist of secrets became an allowlist of fields.** Host fault 16 was `Api/DropshippingController.php:31-38` copying `name` and `description` out of a supplier array by hand to avoid leaking the API key, so a new config field on a supplier leaks by default. `Present::supplier()` inverts it: a new column is absent by default.
- **Deleting a status changes a statutory report.** `Order::STATUS_SUPPLIER_QUEUED` and `STATUS_SUPPLIER_FAILED` are both listed in `OssReportService::REPORTABLE_STATUSES` and in `EcSalesListService`, so dropping the two states without mapping them removes orders from an EU VAT return and an EC sales list. It is in the module's `docs/adoption.md` because nothing about the state machine says it.
- **Nine of the addendum's eighteen `file:line` citations were wrong**, by 2 to 19 lines, every one still naming the right file and the right defect. Worth stating because the addendum is written from a survey and read as though it were the code: an agent that trusts a line number instead of re-reading the file will eventually find a plausible wrong thing there.
- **The supplier catalogue import is not extracted.** `DropxlService` upserts into `Product` and `ProductCategory` — Catalog's tables — keyed on a slug derived from the product *name*, so two supplier products named alike collapse into one row, categories are created untenanted, and price arrives as whatever the provider sent. It belongs to Catalog Import and Export ([#831](https://github.com/liberusoftware/ecommerce-laravel/issues/831)) and PIM ([#893](https://github.com/liberusoftware/ecommerce-laravel/issues/893)); its faults are handed on in `docs/adoption.md` rather than carried.
- **`purchase_reference` is not `'order-'.$order->id`.** The host's only correlation with a supplier was a global auto-increment id, identical across two merchants on separate installs.

**Eighty-eight packages now exist across twenty-two modules, and none is on Packagist.**

---

## Wave 18 — Social Commerce, and a catalogue somebody else holds — ✅ **shipped**

Four packages, green on Tests, Install and Compatibility. 410 tests, 3,886 assertions, every one at **100.0% coverage and PHPStan level 10** — the ceiling PHPStan 2.x offers. [#916](https://github.com/liberusoftware/ecommerce-laravel/issues/916) records what shipped.

| Package | Tests | Assertions | Coverage | PHPStan |
| --- | ---: | ---: | ---: | ---: |
| `ecommerce-social-commerce` | 118 | 1,154 | 100.0% | 10 |
| `…-api` | 120 | 1,060 | 100.0% | 10 |
| `…-filament` | 91 | 888 | 100.0% | 10 |
| `…-livewire` | 81 | 787 | 100.0% | 10 |

Namespace `Liberu\Ecommerce\SocialCommerce\`. **Five tables**, `social_`-prefixed, every `tenant_id` NOT NULL. The domain package is at `0.1.1`; the three surfaces at `0.1.0`, requiring `^0.1`.

Picked by §1's rule with the tier diagram exhausted — most-code-first among the remaining epics, at 917 lines over fifteen files. **Saved Lists measures comparably and was eliminated on the same test that eliminated Categories and Navigation last wave**: `module-ecommerce-customer-accounts` already ships `SavedList`, `SavedListItem` and `SavedListShare`. A second epic naming code a shipped module owns is not a second module; checking the shipped tree is now part of picking.

### The catalogue is a copy somebody else holds

The host's Facebook cluster — added a week before this wave, with 766 lines of tests already covering its happy paths — kept its record of what Meta held in `product_facebook_listings`, hanging off `product_id` and `product_variant_id` with `cascadeOnDelete` on both:

```php
$table->foreignIdFor(Product::class)->constrained()->cascadeOnDelete();
$table->foreignIdFor(ProductVariant::class)->nullable()->constrained()->cascadeOnDelete();
```

Delete a variant and the row goes. Meta still has the item: live, buyable, and no longer known to the application. `Product` handles this — `Product::booted()`'s `deleting` hook reads the retailer ids before the cascade — and `ProductVariant::booted()` hooks only `saved`.

Nothing in the host deletes a variant today; neither panel has a variant editor. So the fault is **latent, and the module built on it anyway**, because the Filament package this wave shipped is the most plausible place a variant editor arrives, and the constraint cannot be retrofitted once rows exist.

**A record of something published to a third party is not a cache of local state and cannot be deleted with it.** The module's answer went further than the addendum asked. A listing's subject is an opaque string, never a foreign key:

```php
// No cascade from the shop: a listing outlives the shop row, because
// the network still holds the item after a merchant disconnects.
$table->foreignId('shop_id')->constrained('social_shops')->restrictOnDelete();
// Opaque, and never a foreign key.
$table->string('subject_ref');
```

There is no cascade path into the table at all. Withdrawal is a state a listing has to reach, and it survives the subject that is gone.

### A read nobody performed, reported as a read that found nothing

Host fault 11 is the one that stops anybody noticing the others. `FacebookCatalog::readItemStatuses()` returns `[]` on transport failure, on a non-2xx response, and on a catalogue that genuinely holds nothing. `ReconcileFacebookCatalog` then skips every listing, prints `Reconciled 0 listing(s).` and returns `SUCCESS` — hourly, from `Console/Kernel.php:20`. A merchant whose token was revoked last week has an hourly green tick.

So the module publishes three outcomes where the host had two: it answered, it answered nothing, or **it could not be asked**. `ReconcileOutcome::asked` is the flag, and partial coverage is a counted number rather than the host's `Log::warning` about a catalogue exceeding one page of 1,000.

**And the module reproduced the fault anyway, one step removed.** `ReconcileShopListings` returned early for a shop with nothing to reconcile — *before* opening the channel — so a shop with no adapter bound, no credentials or a revoked token reported `asked: true, considered: 0`, indistinguishable from a shop that answered and had nothing to say. A caller watching `asked && isComplete()` got green from a shop nobody could reach. Found by the `-filament` package, fixed in `0.1.1` by moving one line above the early return: a shop that cannot be reached has not been asked, whether or not it had anything to ask about.

Worth keeping, because it is the wave's own thesis failing its own test: **an early return is a claim about what happened, and a cheap one is the easiest place to make a false claim.** The three-outcome discipline was designed, implemented, tested, and then bypassed by the path that had nothing to do.

### A refusal is a fact, and the wire format has to carry it

The seam is unbound by default, so a publish records the intent and refuses the transmission. `-api` made that visible rather than papering it: a refused publish answers `4xx`/`5xx` **carrying `data` beside `error`** — the persisted listing — because the row exists and a bare error would be a false statement about what happened. A reconciliation with `asked: false` answers **503** carrying `considered`, never 200. `answered: 0` is unreachable as a success.

The four `RefusalReason` cases get four statuses because they are four remedies: `no_channel_bound`→503, `no_credentials`→409, `channel_refused`→409, `channel_unreachable`→502. That breaks the loyalty surface's invariant that `resubmittable` ⟺ 422/503 — `shop_not_connected` is a resubmittable 409 — and the package documented the departure with its own asserted invariant rather than bending to the older one.

`-filament` rendered the same distinction at four sentences, not two: could-not-ask, nothing-to-re-read, complete, and partial-naming-the-silent, asserted as exact strings and colours. `-livewire` carried it to the shopper: a shop is named only when the listing is published **and** the network confirmed it, and when something is silent, *"not on any of our social shops"* is suppressed rather than shown.

### The module's principal action is unreachable by default, on purpose

Wave 17 found a rule about what a module may hold making its own principal action impossible, and found it in a surface. This wave made it structural in the domain instead: no adapter ships here — no Graph URL, no SDK, no API version — so `Contracts\ShopChannel` is unbound and every publish has two outcomes a caller must distinguish.

The domain agent went one step past the instruction and **dropped the `ChannelUnavailable` exception**, on the grounds that an exception is a `catch` a caller can forget and a return value is not. `TransmissionOutcome` carries `transmitted` and a `RefusalReason`. That is the right shape: the build brief's own rule against shipping a class nothing exercises made the exception unreachable once the outcome existed.

### Erasure strands what the network still holds

The sharpest thing found and **not** fixed. `Actions\RedactSubject` sets `subject_ref` to one constant for the whole tenant, so after erasure `RequireWithdrawal($tenant, $originalRef)` returns 0 — the subject-driven path to withdrawal, the one the module was shaped around, can no longer find the listing — and `RequireWithdrawal($tenant, 'redacted')` becomes a tenant-wide write across every erased subject. The action's own docblock promises the opposite.

Three shapes would close it, with different consequences for a published contract, and picking one while closing a wave is the wrong moment. [#1](https://github.com/liberusoftware/module-ecommerce-social-commerce/issues/1) carries the analysis. It is the module's sharpest known defect and it is open.

### Seventeen findings, of which one is fixed

The three surface packages filed sixteen issues against the domain and the domain package fixed one. That ratio is the point of building the surfaces separately: a package that only ever adapts over its own design agrees with itself.

- **`RecordCheckoutHandoff` returns another item's record on a colliding order reference.** `firstOrCreate` keys on `(tenant, shop, order_ref)` with `item_ref` create-only, so recording order X against item B after X was recorded against item A returns **A's row, looking successful**. `-api` detects it and answers `409` — a comparison, not a fix; every other caller still gets the wrong row silently. [#8](https://github.com/liberusoftware/module-ecommerce-social-commerce/issues/8), [#16](https://github.com/liberusoftware/module-ecommerce-social-commerce/issues/16).
- **`ReconcileOutcome` cannot say why it could not ask.** `asked: false` collapses four causes that `PublishListing` distinguishes with a `RefusalReason`. An operator cannot tell *bind an adapter* from *reconnect the shop* from *the network is down*. [#6](https://github.com/liberusoftware/module-ecommerce-social-commerce/issues/6).
- **No query enumerates a tenant's shops**, so `-api` ships no `GET /shops` and `-filament` pays one `FindShop` per listing to name one. Writing the scoped read in a controller would put tenancy into three transports. [#5](https://github.com/liberusoftware/module-ecommerce-social-commerce/issues/5), [#7](https://github.com/liberusoftware/module-ecommerce-social-commerce/issues/7).
- **`CustodyPolicy::ownsHandoff()` has no caller anywhere**, because there is no handoff query — the same gap from the other end. [#12](https://github.com/liberusoftware/module-ecommerce-social-commerce/issues/12).
- **A refused publish has no queue naming it and no retry inside the module.** The consequence of storing no copy of the content sent: a `pending` listing has no way back from a panel, and `-filament` reported it rather than inventing a form that would publish hand-typed content under a merchant's name. [#14](https://github.com/liberusoftware/module-ecommerce-social-commerce/issues/14).
- **No way to ask whether a shop can transmit without decrypting its secret.** `ChannelHandle::open()` computes it and reveals as a side effect, so the panel reassembles the check itself. [#13](https://github.com/liberusoftware/module-ecommerce-social-commerce/issues/13).

### Smaller things worth keeping

- **`IsTenantModel` is not a scope.** It is a `creating` hook and a `team()` relation — no global scope, unlike `IsStoreScoped` beside it. So `FacebookConnection` is scoped by exactly one thing, the explicit `where('team_id', …)` inside `forTeam()`, and every other query reads **every merchant's Meta credentials**. A trait named for the boundary it does not enforce is worse than no trait, because the name is what the next model's author reads.
- **A cross-tenant write justified by a comment.** `RemoveProductFromFacebookCatalog` selects listings by `whereIn('retailer_id', …)` with no tenant predicate, over the note *"retailer_id is unique across the table, so the ids alone are the scope"*, while carrying a `$teamId` it never compares. Only one caller reaches it and that caller passes the product's own ids, so it is unreachable today — the shape is still wrong and the comment is still wrong.
- **Float money, published publicly and buyable.** `CatalogItemMapper::money()` is `number_format((float) $price, 2)` plus one platform-wide `DEFAULT_CURRENCY`, on a host that has `Currency` and `ProductCurrencyPrice`. A merchant pricing in EUR publishes `12.99 USD` to a social network. The module stores minor units, an ISO 4217 code and an exponent, and a price that cannot name a currency **cannot be built**.
- **Every merchant's products branded with the platform.** `'brand' => config('app.name')`, twice. And the public link falls back to `url()` — the platform's own host — when a store has no channel, published where shoppers click it.
- **The publish is queued from a model hook with no `afterCommit`**, and every queue connection in `config/queue.php` sets `'after_commit' => false`. There are three dispatch sites, not the two the addendum found: `adjustInventory()` at `Product.php:461-462` queues directly because `increment()`/`decrement()` bypass `saved`.
- **The addendum's citations held.** Two loose ranges out of nineteen, no wrong file and no wrong defect, against nine wrong out of eighteen last wave. The only thing that changed is that each `file:line` was read from its own file rather than from a `cat -n` of several at once — which is exactly how the wave 17 errors were produced, and it took a wave to notice.
- **A brief that generalised from one package was wrong about two.** `presentation-brief.md` said the shipped loyalty surfaces carry two test suites. `-api` carries three, correctly wired; `-filament` and `-livewire` carry two, differently broken. Found by an agent checking the claim instead of following it. The brief now carries the table and says to pattern on `-api`.
- **`CustodyPolicy` cannot be a Filament policy.** Its methods are `(Model, string $tenantId)`, not Gate's `(User, Model)`, so the presentation brief's *"you call it, you do not re-derive it"* is not what happens in a panel: enforcement is the tenant-scoped `getEloquentQuery()` plus the custody check the domain actions run internally.
- **The reference package calls `view()`, which its own boundary rule forbids.** `view()` lives in `laravel/framework`, not `illuminate/support`. `module-ecommerce-loyalty-livewire` ships the violation and its boundary test misses it because `view` is absent from the helper list — masked by that package analysing at PHPStan level 1, where the `view-string` error does not surface.
- **A version bump that touched one of two files reached CI.** `composer.json` moved to `0.1.1` and `module.json` stayed at `0.1.0`; the testbench's boundary assertion caught it, which is what it is for. The local gates had been run *before* the version was edited rather than after. The tag was deleted and recreated, which was available only because nothing consumed it yet.
- **Three catch blocks were removed rather than left untested**, because the custody checks they guarded cannot fail when the record is resolved through a tenant-scoped query. An unexercised branch is a lying constraint by the same rule that killed `ChannelUnavailable`.

**Ninety-two packages now exist across twenty-three modules, and none is on Packagist.**

---

## Wave 19 — Customer Service Workspace, and a conversation nobody can return to — ✅ **shipped**

Four packages, green on Tests, Install and Compatibility. 476 tests, 4,900 assertions, every one at
**100.0% coverage and PHPStan level 10**. [#847](https://github.com/liberusoftware/ecommerce-laravel/issues/847)
records what shipped.

| Package | Tests | Assertions | Coverage | PHPStan |
| --- | ---: | ---: | ---: | ---: |
| `ecommerce-customer-service-workspace` | 118 | 1,277 | 100.0% | 10 |
| `…-api` | 149 | 1,691 | 100.0% | 10 |
| `…-filament` | 106 | 984 | 100.0% | 10 |
| `…-livewire` | 103 | 948 | 100.0% | 10 |

Namespace `Liberu\Ecommerce\CustomerServiceWorkspace\`, tables `csw_`-prefixed, every `tenant_id`
NOT NULL. All four at `0.1.0`; the three surfaces require `^0.1`.

### The epic that was picked and the epic that owned the code

§1's rule put **Commerce Copilot ([#838](https://github.com/liberusoftware/ecommerce-laravel/issues/838))**
at the top on 1,105 lines of host code, and it was claimed on that basis. Then the epic text was
read. #838 is permission-bounded product/order/customer search, summaries, catalog assistance, safe
operational actions and confirmation — a merchant's assistant. The code measured is a customer-to-agent
live-chat helpdesk: a queue, agent assignment, response times, a satisfaction rating.

Grepped across all 105 epic bodies: **no epic in the map says "chat"**, and none says conversation,
message, queue, agent or transcript either. The word "queue" appears in #838 only in the
shared-foundation list, meaning a job queue.

The cluster is placed here because this module owns the support agent's console and the epic's
"CRM/Support handoff", and a support workspace with no inbound channel has nothing to work on.
#838 was released back to Todo and the placement stated in the module's ADR, per §1.1's third
standing constraint.

**Most-code-first measures a module's code, and measuring it requires reading what the module is.**
The ranking was right and the label on the largest cell was wrong. Last wave added "check the
shipped modules' `src/Models`" to picking; this one adds "read the epic body before believing the
count".

### Two session identifiers, and neither is the other

`ChatWidget::mount()` mints one into the browser session and the blade looks conversations up by it:

```php
// app/Livewire/ChatWidget.php:22
$this->sessionId = session('chat_session_id', Str::uuid()->toString());
```

But it starts conversations with an empty body, so the server mints a different one
(`app/Services/ChatService.php:21`). The id the widget searches by is never the id the server
stored, so `loadConversation()` cannot succeed. `let conversationId = null` re-runs on every page
load, so the customer's first message on each page starts a **new queued conversation** and strands
the last. The widget is on every authenticated page.

One value was doing three jobs — lookup key, ownership proof, and something two places each felt
entitled to generate. The module separates them: a conversation has an internal identifier, and a
**participant claim** is issued once, by the module, and is the only thing that proves a claim.
Resumption is a first-class operation with its own test file, every case of which crosses a
component instance — the host's resume path was never exercised because its only test passed it an
id created in the same test, which is the one condition the broken production path never meets.

### Three things get called a note

| Table | Written by | What it is |
| --- | --- | --- |
| `order_status_history` | `Order::transitionTo()` | The state-machine audit row. Real, in use, and the orders module's |
| `order_notes` | nothing | An agent's note. Model, migration and unit tests; zero callers |
| `order_events` | nothing | A timeline entry with a JSON payload. Same |

`transitionTo()` takes a `$notes` argument and writes it into `order_status_history.notes`. So the
one note that gets written goes to the state machine's audit row, and the two tables built for
notes and for a timeline have everything except a caller. Both come here; the audit row stays with
orders.

### A twentieth fault, found by the module rather than the survey

The wave addendum named nineteen host faults. The domain package found a twentieth while building
against them: `chat_conversations.store_id` and `team_id` are both `nullable()`. The migration
argues the case at length and the argument is sound for a backfill — but a conversation created off
a resolved host then belongs to no merchant, and nothing later makes it belong to one.

Of the nineteen, all nineteen were confirmed real. Seven citations pointed a few lines off — at the
enclosing declaration rather than the statement — and one named the wrong method: `Order.php:131`
declares `statusHistory()`. Every citation had been read from its own file rather than from a
concatenated dump, which is what produced nine wrong citations in wave 17; the remaining drift is a
smaller error with a different cause.

### Fifteen findings, of which none is fixed

Three surfaces reported fifteen defects in the domain package, [all filed](https://github.com/liberusoftware/module-ecommerce-customer-service-workspace/issues).
Two were found independently by two surfaces, which is the argument for building them separately
rather than as one package with three folders.

The sharpest is **erasure**. `ForgetParticipant` overwrites `participant_ref` with a single
configured token shared by every erasure, and looks rows up by that same column — so an export for
the token returns every person ever erased, and erasing the token re-erases all of them. The
reference was made NOT NULL precisely so that a guest has a route to their own data, host fault 15
being that they had none. Erasure then puts every guest back on the same route. **The module
reproduced the identifier confusion it was built to fix, in the one path where it does the most
damage.**

Second: `Conversation::transitionTo()` decides against the loaded model and saves, with no lock and
no version column. Two agents taking the same queued conversation both pass the check and both
write. Ratings and action requests are arbitrated by unique indexes, so the module already accepts
that a guard in PHP is not a guard; state is the one place it does not.

Third: `Outcome::refused()` carries a reason and no id, while `RequestAction` persists the row
before transmitting. A caller told "refused" is told nothing was recorded, and a row exists. That is
decision 5's own rule failing at one remove, and both the API and Filament surfaces worked around it
independently, differently.

### Smaller things worth keeping

- Two durable brief corrections outlast this wave. `module-build-brief.md` said an append-only rule
  is enforced "by an index, not by a guard" — wrong, and the shipped reference package already does
  both: an index arbitrates a concurrent append, and nothing about a unique index stops an `UPDATE`.
  And `presentation-brief.md` gained a gates section, because §6 is the Livewire package and a
  dispatch that sends an agent there for the gate commands sends it to the wrong place.
- The testbench forbids importing a domain model anywhere in an `-api` package's `src/`, including
  for a closure type hint or a constant. Neither brief mentioned it; a first draft would have gone
  red in CI.
- The API package throttles every route rather than a chosen subset, on the grounds that a list of
  which routes deserve a limit is a list that falls behind. Host fault 16 is that list, three routes
  in.
- The participant claim travels in a header. A cookie was rejected explicitly: ambient authority the
  browser attaches by itself is a second session-shaped value beside the reference, which is this
  wave's shaping fault one identifier along.

**Ninety-six packages now exist across twenty-four modules, and none is on Packagist.**

---

## Wave 20 — Invoices and Documents, and a document that changes after it is issued — ✅ **shipped**

Four packages, green on Tests, Install and Compatibility. 438 tests, 4,348 assertions, every one at
**100.0% coverage and PHPStan level 10**. [#863](https://github.com/liberusoftware/ecommerce-laravel/issues/863)
records what shipped.

| Package | Tests | Assertions | Coverage | PHPStan |
| --- | ---: | ---: | ---: | ---: |
| `ecommerce-invoices-and-documents` | 138 | 629 | 100.0% | 10 |
| `…-api` | 115 | 1,331 | 100.0% | 10 |
| `…-filament` | 105 | 1,528 | 100.0% | 10 |
| `…-livewire` | 80 | 860 | 100.0% | 10 |

Namespace `Liberu\Ecommerce\InvoicesAndDocuments\`, tables `invoicing_`-prefixed, every `tenant_id`
NOT NULL. All four at `0.1.0`; the three surfaces require `^0.1`.

### The host's invoice is a live view, not a document

Almost everything the invoice prints is read through a relation at render time. The lines are a
`belongsToMany` straight onto the live catalogue (`app/Models/Invoice.php:87-90`), the rendered line
description is the product's current name (`resources/views/invoices/show.blade.php:27`), and the
buyer's identity is read the same way (`:11`). Only the pivot's quantity and price and the header
total are frozen.

So renaming a product rewrites every past invoice that contained it; deleting a product **deletes
invoice lines** (`invoice_product.product_id` is `cascadeOnDelete`) while the frozen header total
keeps printing, leaving a document whose lines no longer add up to its total; and because `Product`
is store-scoped, an invoice read on a request that resolved to a different channel silently returns
fewer lines than it was issued with.

That is the module: **issue freezes.** A document copies, once, everything it will ever display —
seller, buyer, each line's description, quantity, unit price, rate, tax, the totals and the
currency — and afterwards reads none of it from anywhere else. An issued document has no update
path and no delete path, in the domain, the API or the panel; a correction is a credit note.

### Three things get called an invoice number

| What | Where | What it is |
| --- | --- | --- |
| `#{{ $invoice->id }}`, labelled "Invoice #" | `resources/views/invoices/index.blade.php:13`, `:23` | The `invoices` primary key, shared across every store and tenant |
| The "Order" column | `app/Filament/App/Resources/Invoices/InvoiceResource.php:69` | The `orders` primary key. The host has no `order_number` column at all |
| `OrderNumberSequence::format()` | `module-ecommerce-commerce-core` | A real per-store allocated sequence — for orders, in a module nothing consumes yet |

The `invoices` table has no number column. A customer looking at their invoice is being shown the
platform's running total of invoices ever issued.

### The boundary that had to be argued rather than asserted

`commerce-core` shipped `AllocateOrderNumber` in wave 1 and wrote its trade-off down: *"A gap is
fine and a duplicate is not … and holding the lock until the order commits would put the payment
gateway's latency inside a lock every other checkout waits on."*

Both halves are correct for an order number and neither carries to a fiscal document series. A gap
is not fine — several EU jurisdictions require the series to be gapless — and the latency argument
does not apply, because nothing like a gateway sits inside issuing a document: the sale was copied
at draft, the lines are already rows, and the issuing transaction is one `UPDATE` and one `INSERT`.
So this module holds the lock, spends the number inside the transaction that writes the document,
and a rollback returns it. The grain differs too: commerce-core's sequence is unique on
`(store_id, prefix)`, and the obligation to file belongs to the merchant, so a document series
belongs to one tenant.

Gaplessness landed as a **per-series policy** rather than a universal invariant, since jurisdictions
differ, with a burned-number record accounting for any hole. §1.1's third standing constraint was
satisfied by a boundary notice on
[#839](https://github.com/liberusoftware/ecommerce-laravel/issues/839), which is closed — a comment
rather than a reopen.

### Twenty-five faults, and nine more the module found

The wave addendum named twenty-five host faults from primary sources. Building against them turned
up nine more, of which four are worse than anything the survey caught:

- **Invoices are invisible to the merchant that issued them.** `Invoice` uses `IsStoreScoped` and
  not `IsTenantModel`; `team_id` is nullable with no default and nothing writes it. The App panel is
  Team-tenanted, so `Team::invoices()` returns empty forever — and because the store stamp is also
  absent on anything generated from a webhook, those invoices are visible in neither the panel nor
  the storefront.
- **Both VAT filings aggregate every merchant on the deployment into one return.** `vat:oss-report`
  and `vat:ec-sales-list` have no `--store` or `--team`, and the store scope is inert in a console
  context. Bigger than the fault the survey had named, which was only that the returns cite no
  invoice.
- **Both filings print a currency they never read**: `config('ecommerce.currency', 'EUR')`, where
  the key is `default_currency` and its default is `'USD'`. A filing labelling dollar amounts as
  euro.
- **Three independent cascade paths delete financial records.** Deleting a customer, an order or a
  team hard-deletes invoices, and there are no soft deletes — while `InvoicePolicy` ships
  `restore`, `restoreAny`, `forceDelete` and `forceDeleteAny` as if there were.

Of the twenty-five, all were confirmed. One citation was wrong: fault 16 gave
`Pages/EditInvoice.php:29` in a 20-line file, because that pair of small files had been read in one
`cat -n` — the cumulative-numbering mistake the addendum's own header warns about, made in the
document that warns about it. Two surfaces reported it independently.

### Fourteen findings, of which none is fixed

Three surfaces reported fourteen defects in the domain package,
[all filed](https://github.com/liberusoftware/module-ecommerce-invoices-and-documents/issues).

The sharpest is **delivery**. `RecordDelivery` writes the attempt row — spending the
`(tenant_id, reference)` unique index — and only then asks for a transport. With none bound it
refuses, having already spent the key, so repeating the identical call reports `already_recorded`
and never transmits. The attempt sits `Pending` under that reference permanently. "Persist before
transmitting" was followed; what the rule also needs is that the persisted row stay retryable. The
API package had to publish three delivery failures as **not** resubmittable because of it, and tell
operators to resend under a new reference, which is idempotency advice that defeats idempotency.

Second: **`ForgetParticipant` erases the note before the retention check that would have refused.**
The action declines to redact buyer identity on a retained document on the stated grounds that the
law requires the document unchanged — having already nulled that document's note, which routinely
carries a statutory statement such as "reverse charge applies". The refusal it returns is accurate
about what it declined and silent about what it did.

Third: **`CustodyPolicy::buyerMayRead` has no state condition**, so a draft passes. A buyer-facing
surface built on the policy alone would show a customer an unnumbered, unissued document — the
host's defect reappearing one layer up. The Livewire package excluded it in its own components,
which is a custody decision made in a surface rather than in the policy.

Fourth, and the one a test found rather than a review: **`BurnedNumber` has no immutability guard**
while `Document`, `DocumentLine` and `DocumentEvent` all have one. A burned number is the only row
that accounts for a hole in a series, and deleting it makes the hole unexplained.

### Smaller things worth keeping

- **Two brief bugs that would have cost every later wave.** `presentation-brief.md` §6a gave the
  PHPStan command without `-l`, and the shared `phpstan.neon` declares no level on purpose — so as
  written it analysed nothing and answered *"use the --level option"*. And `module-build-brief.md`
  required `permissions: contents: read` in prose while its copyable YAML snippet omitted it. Both
  fixed at source; the workflow snippet now carries what the sentence asked for, because a snippet
  is what gets copied.
- **Two decisions in the addendum were too narrow and the domain agent widened them, correctly.**
  `void` reachable only from `issued` leaves no way to discard a draft in a module with no delete
  path, and a delivered document found wrong must still be voidable. And the per-rate tax summary
  shipped as a computed value rather than a table, because a stored derivation is exactly what the
  build brief forbids.
- **The `-api` package made the refusal reason the wire error code structurally**, via a `match`
  over all twenty `RefusalReason` cases, so a reason added to the domain fails static analysis in
  the adapter rather than becoming a 500.
- **No package ships a PDF.** Rendering is an unbound seam and a domain package installing a PDF
  library forces one on every consumer. The module produces a render model; a host that binds a
  renderer serves it from its own route.
- **The Filament package declares the `app` panel, not `admin`.** Both host panels resolve `Team`
  tenancy, but defaulting the filing cabinet onto the platform-admin panel is where host fault 14
  lives.

**One hundred packages then existed across twenty-five modules, and none was on Packagist.**

---

## Wave 21 — Recommendations, and a feature nobody ever switched on — ✅ **shipped**

`ecommerce-recommendations` `0.1.0` and its three presentation packages
(`-api`, `-filament`, `-livewire`), all `0.1.0`, all green on Tests, Install and Compatibility.
401 tests, 3,778 assertions, 100.0% coverage and PHPStan level 10 across all four, every figure
read from the CI job logs rather than a build report.

Picked by §1's rule with the tier diagram long exhausted: most-code-first among clusters no shipped
module already owns. Two candidates measured larger and both were mirages — Categories and
Navigation, because `module-ecommerce-catalog` had already taken `Category`, `ProductCollection`,
`Tag`, `ProductOption` and `ProductVariant`; Saved Lists, because Customer Accounts had already
taken `SavedList`, `SavedListItem` and `SavedListShare`. Checking a headline count against the
shipped modules' own `src/Models` before believing it is now the third time that step has changed
the answer.

### The feature was never switched on

The host has a recommender in the sense that it has the files. It has never had one in the sense
that a shopper could see a recommendation, because every path into and out of it is disconnected,
and each disconnection is separately verifiable.

`app/Http/Controllers/Frontend/ProductController.php:120-142` is the product page. The
`BrowsingHistory::create()` at `:122-128` is commented out. The call to the recommender at
`:130-134` is commented out. Line `:141` returns the view with the product and nothing else — while
`:19-22` still resolves `UserHistoryRecommender` out of the container on every product-page request
to ask it nothing at all.

`ProductInteraction::track()` (`app/Models/ProductInteraction.php:44-61`) has no caller outside the
engine's own `trackView`, `trackAddToCart` and `trackPurchase`
(`app/Services/ProductRecommendationEngine.php:207`, `:221`, `:234`), and those three have no caller
anywhere in `app/`, `routes/` or `resources/views/`. So `product_interactions` is written by nothing
and `browsing_histories` is written by nothing.

`app/Console/Kernel.php:13-21` schedules two commands, `shipping:prune-quotes` and
`facebook:reconcile-catalog`. `recommendations:generate` is not one of them and is not in
`routes/console.php` either, so the one generator never runs.

And there is no surface: no Filament resource, no policy, no API controller, no GraphQL field, no
Blade partial anywhere mentions a recommendation.

The consequence is the design constraint the module was built around. `getUserRecommendations()`
falls back to trending when a shopper has no interactions
(`ProductRecommendationEngine.php:56-58`); trending joins `product_interactions` (`:113-119`), which
is always empty; so the fallback returns an empty collection, and **the caller cannot distinguish a
new shopper from a system that has never recorded anything from a generator that has never run.**
Three operational states, one indistinguishable output, in a feature whose entire job is to be
quietly absent when it has nothing to say.

So the module's rule is that silence must be legible. Every serve path carries why it is what it
is — which strategy answered, how many candidates each contributed, which exclusions fired and how
often, and when the answer is empty, which precondition failed. The `-api` package turns that into
a wire distinction between *nothing bound*, *nothing generated*, *everything excluded* and *this
shopper is new*; the `-filament` package turns it into a Readiness page that says "has never run"
and "has never succeeded, the last attempt ended Failed" in different sentences; the `-livewire`
slot renders nothing at all to the shopper while exposing all of it to the developer embedding it.

### Three tables of one feature, three different answers about tenancy

`RecommendationRule` uses `IsTenantModel` (`app/Models/RecommendationRule.php:12`).
`ProductRecommendation` uses neither tenancy trait (`app/Models/ProductRecommendation.php:9-11`).
`ProductInteraction` uses neither (`app/Models/ProductInteraction.php:9-11`). The rule table got its
`team_id` from the sweep migration
(`database/migrations/2026_08_09_000001_add_team_id_to_the_remaining_tenant_models.php:40`) rather
than its own, which is how the other two came to be missed.

The wave also corrected a belief this document had inherited. `IsTenantModel` is **not a read
scope**: `app/Traits/IsTenantModel.php:38-54` is a `creating` hook that stamps `team_id`, plus a
`team()` relation, and nothing else. So
`RecommendationRule::firstOrCreate(['type' => 'collaborative'], …)` at
`ProductRecommendationEngine.php:163-170` reads across every tenant from a panel, an HTTP request
and the console alike — not only in console, which is the shape wave 20's `vat:oss-report` fault
had. The wave-21 brief asserted the console-inertness version, reasoning by analogy from
`IsStoreScoped`, and was corrected by the build. It is recorded here because the failure was an
inference from a sibling trait stated with more confidence than the reading supported, and that is a
cheaper mistake to remember than to repeat.

Both co-purchase queries make it worse by leaving Eloquent entirely:
`ProductRecommendationEngine.php:128-137` and `:173-183` are raw `order_items` self-joins with no
store predicate, so one merchant's shoppers drive another merchant's suggestions.

### A score normalised against a number somebody guessed

`ProductRecommendationEngine.php:186-188`:

```php
$maxFrequency = 100; // Assume max frequency
$score = min(1, $pair->frequency / $maxFrequency);
```

A pair bought together a hundred times and a pair bought together five thousand times both score
`1.0`. The generator also never retracts — `:185-201` upserts every qualifying pair and removes
nothing, so a pair that falls below the `having('frequency', '>=', 3)` threshold at `:182` keeps its
last score forever — and it counts order *lines* rather than orders, so one order carrying a product
twice inflates the pair quadratically.

The module answers all three: a score is stored with the `evidence_count` and `subject_count` that
produced it and normalised per strategy at serve time; a generation run is a first-class record and
an affinity the newest successful run does not reassert is superseded with an audit row; and a
signal carries a `group_ref` so two lines of one order are one co-occurrence.

### Twenty-six faults, and a green suite that depends on the test database being lax

The survey found twenty-six, every citation read from its own file. The sharpest is not in the
application code at all.

`tests/Unit/ProductRecommendationEngineTest.php:55` writes `'score' => 85.5` and `:60` asserts it
back; `:129` and `:135` write `50.0` and `90.0` and `:141` asserts `90.0`. The column is
`decimal(5,4)` — ceiling 9.9999
(`database/migrations/2024_02_16_000002_create_product_recommendations_table.php:28`). The suite runs
on sqlite `:memory:`, which does not enforce decimal precision, and the assertions compare the
in-memory model rather than a re-read row. On MySQL those three inserts raise *"Out of range
value"*. **A green suite, a schema violation, and no test anywhere that would notice.**

Two coverage gaps beside it: `tests/Unit/UserHistoryRecommenderTest.php` is 38 lines and never calls
`getRecommendations()`, leaving the 96-line service's only public method untested; and
`ProductRecommendationEngineTest` exercises `getPersonalizedRecommendations` alone, so
`getAlsoBoughtRecommendations`, `getSimilarProducts`, `getTrendingProducts`,
`generateCollaborativeRecommendations` and all three `track*` methods have no test at all.

Three other faults worth carrying forward. `product_recommendations`' unique key is
`(product_id, recommended_product_id)` with no rule (`…:32`), which forbids the four-rule design the
`type` column at `:15` advertises — the second rule to claim a pair silently overwrites the first.
Both product foreign keys hard-cascade (`:25`, `:26`) while `Product` soft-deletes
(`app/Models/Product.php:27`), so a deleted product's recommendations survive pointing at an
invisible product, get filtered out at `ProductRecommendationEngine.php:73-77`, and shorten the
result below the requested limit with nothing saying why. And
`UserHistoryRecommender.php:47` sorts the merged candidate set by `rating`, an attribute only
`getHighlyRatedProducts()` attaches (`:91`) — so the purchase and browsing branches, two of the
three declared signals, sort as null and can never win.

### Twenty findings in what shipped, of which none is fixed

Filed on the module repositories, in the wave's own discipline of recording rather than repairing.
Four are worth naming here.

**The affinity unique key does not fit MySQL.** `2026_08_27_000003_create_recommendations_affinities_table.php:39`
is `unique(['tenant_id', 'strategy', 'from_ref', 'to_ref'])` over four `varchar(255)` columns —
4,080 bytes under `utf8mb4`, against InnoDB's 3,072-byte index key limit. The host is `utf8mb4`
(`config/database.php:55`) and `Schema::defaultStringLength()` is never called anywhere in the tree,
so `php artisan migrate` fails on the database the stack actually deploys against. It is invisible
because the package suite runs on sqlite — **the same failure mode as the host test fault above,
reproduced in the module that had just finished documenting it.**

**A curated claim cannot be retracted.** `WithdrawAffinity` takes an Eloquent model
(`src/Actions/WithdrawAffinity.php:17`) and no published query finds one from a reference, so no
presentation package can offer withdrawal. Generation runs only supersede computed claims, and a
manual claim has no run.

**Nothing can list what the module currently claims.** `Queries/ListAffinities.php:14` returns
Eloquent models, which the boundary rule forbids a presentation package from naming; and its
`$anchorRef` defaults to `''`, which is a real stored value rather than a wildcard, so even inside
the domain the default returns popularity claims only.

**The placement cannot say "withheld below the k-anonymity floor".** Only the run report carries
that; the placement answers `no_affinities_for_anchor` whether generation found nothing or withheld
everything. It is the one distinction §2's own argument demands that the module does not yet make.

### Smaller things worth keeping

- **The boundary against Attribution and Analytics had to be argued rather than asserted.** Three of
  this epic's scope items — recently viewed, popularity, context — are behavioural signals, which is
  what wave 14 shipped. The split the ADR states: **analytics owns the observation, recommendations
  owns the inference.** An analytics event is a consent-governed record kept for measurement; a
  recommendation signal is a derived, purpose-limited input to a ranking. They are not the same row
  even when they describe the same click, and collapsing them makes the recommender's retention
  policy hostage to the analytics consent model. Boundary notices went to #885, #871 and #872 as
  comments, never reopens, per §1.1's third constraint.
- **Erasure is deliberately asymmetric, and the asymmetry is the ADR's riskiest claim.** Erasing a
  person deletes their signals and their placements and leaves the affinities their behaviour
  contributed to standing, because an affinity is a statement about a pair of products rather than
  about a person. What makes that defensible is the k-anonymity floor on generation, configurable
  and defaulting to five distinct subjects — and the build's own objection, recorded rather than
  resolved, is that on a small store a floor of five suppresses nearly every collaborative and
  popularity claim, so the module ships silent in exactly the shops that need it most.
- **`IsStoreScoped` could not come along.** Both host traits read ambient context through
  `App\Services\StoreContext`, which the boundary suite forbids a module to import. The module
  carries the substance instead: `tenant_id` NOT NULL with no default on all six tables, leading
  every index and every unique key, an explicit tenant on every action and query, and a guarded
  relation trait — with `docs/adoption.md` recording that a host passes its `store_id` as
  `tenant_id`.
- **Determinism is enforced, not hoped for.** Same store, same shopper, same catalogue state, same
  window gives the same slot; where a strategy wants variety it takes a seed stored on the
  placement. The `-livewire` package pins it with a boundary case: `src/` may not name
  `inRandomOrder`, `shuffle`, `rand(`, `usort` or `sort(`. The host's `getSimilarProducts()`
  (`ProductRecommendationEngine.php:145-155`) is the thing that rule exists to prevent — a ±30% price
  band and `inRandomOrder()`, so the same shopper reloading the same page gets a different answer
  with no seed and no explanation.
- **A shopper-facing package cannot draw a product card**, and that is the boundary working rather
  than failing. `Data\CatalogueItem` carries a reference, stock, suppression and taxonomy refs —
  no title, no image, no URL — because decision 3 forbids joining the catalogue. The slot emits
  `data-product-ref` and the host supplies the template. It should have been stated as a consequence
  up front instead of discovered by the last agent to build.

**One hundred and four packages now exist across twenty-six modules, and none is on Packagist.**

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
| **3+** — extractions | **Yes before the first tag, no after.** After a tag, demotion breaks every consumer and the honest move is deprecation. **Catalog, Pricing, Inventory Ledger, Cart, Checkout, Orders, Fulfillment, Returns, Payment Operations, Refunds, Gift Cards, Multi-Tender Payments, Tax, Shipping, Reviews and Ratings, Promotions, Commerce Customers, Attribution and Analytics, Customer Accounts, Loyalty, Dropshipping, Social Commerce, Customer Service Workspace, Invoices and Documents and Recommendations are all past it** — all one hundred and four packages are tagged. Nothing consumes them yet, which is not the same thing | See §2 |

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
