# Adopting `liberusoftware/module-manager` over the host's own module system

**Status**: **accepted — landed 2026-08-09**

The Composer network problem this ADR recorded as a blocker was diagnosed rather than waited out. PHP's libcurl is built against c-ares, which resolves over UDP; UDP :53 is black-holed on the maintainer's network, which is why `/etc/resolv.conf` carries `options use-vc`. glibc honours that and resolves over TCP — so `curl`, `git` and `gh` all work — and c-ares ignores it, so Composer alone could not resolve a single host. It was never a Composer fault or an offline machine. `.github/workflows/composer-require.yml` runs the require on a runner that has working DNS.

**One thing found at adoption time that this ADR did not predict**, and it is why the swap had to be a single commit rather than "install now, replace later": both systems name their config file `config/modules.php`. The host's declares `'cache' => ['enabled' => …, 'key' => …, 'ttl' => …]`, an array. `ModuleManagerServiceProvider::register()` reads the same key as `(bool) config('modules.cache')` — a non-empty array casts to `true` — and then `(string) config('modules.cache_path')`, which the host config does not define, giving `""`. Installing the package against the host's config would have switched the registry cache on and pointed it at the empty path, in `register()`, on every boot. There was no safe intermediate state to stop at.

`MIGRATION_PLAN.md` wave 0 has always said `module-manager` is *"the only registrar"* and that adopting it deletes `app/Modules/`. It described that directory as *"1,095 lines of unused scaffolding"*. That was wrong: `AppServiceProvider` registers it, `app/Console/Commands/ModuleCommand.php` drives it, `config/modules.php` configures it, a `modules` table backs it, and two test files cover it.

So the adoption is a **replacement**, and a replacement has a diff. This ADR is that diff, written before the swap rather than discovered during it — the same discipline [ADR 0008](./0008-reviews-and-ratings-merge.md) applied to the reviews and ratings stacks, for the same reason: *the tests that pass are the tests that no longer exist.*

## The decision

Adopt `liberusoftware/module-manager` (`1.4.2`, published) and delete `app/Modules/`, accepting that **runtime module enablement is not carried across**. Enablement becomes deployment configuration rather than mutable state.

## What the two systems actually are

They agree on almost nothing except the word "module".

| | `app/Modules/` (host) | `module-manager` `1.4.2` |
| --- | --- | --- |
| Where "enabled" lives | the `modules` **database table** | `config/modules.php`, read from `MODULES_ENABLED` / `MODULES_DISABLED` env |
| When it is decided | any time, at runtime | once, in `register()` |
| Default for an unknown module | **enabled** — `isModuleEnabled()` returns `true` when there is no row, and `true` again on any exception | **disabled** — *"Installed packages are disabled by default. The host application owns its explicit selection."* |
| Lifecycle | `install()` / `uninstall()` run migrations and publish or remove assets | none — installation is Composer's job |
| Events | `ModuleInstalled`, `ModuleUninstalled`, `ModuleEnabled`, `ModuleDisabled` | none |
| Discovery | scans directories for classes | `module.json` manifests, from `InstalledVersions::getInstalledPackagesByType('liberu-module')` and configured paths |
| Dependencies | a `dependencies` array, unresolved | `composer/semver` resolution, with `DependencyResolutionFailed` |
| Conflicts | none | duplicate module name, duplicate Composer package and **duplicate capability** all throw `InvalidManifest` |
| Validation | none | `ModuleValidationGuard` checks each selected module against the running Laravel version |
| Console | `php artisan module {action}` | six commands: list, status, validate, list-features, cache, clear |
| Caching | — | an opt-in compiled registry at `bootstrap/cache/liberu-modules.php.cache` |

## What is lost, and why it is affordable

**Runtime enablement, the `modules` table, and the four lifecycle events.** This is the whole of the loss, and it is a real capability, not a rounding error.

It is affordable because **nothing uses it.** `enable()`, `disable()`, `install()` and `uninstall()` have no callers in `app/` or `routes/` — only `php artisan module` and the two test files. There is no Filament page, no settings screen, no HTTP route that toggles a module. The capability was built and then never wired to anything a person could reach except a console command.

A runtime toggle with no operator surface is not an operational capability, it is an unexercised code path. Carrying it forward into the packaged world would mean re-implementing DB-backed state on top of a registrar that deliberately resolves once at boot — and *deliberately* is the key word: `module-manager` resolves in `register()` so the set of registered providers is a fixed function of the deployment, which is what makes a boot reproducible.

**The default inverts, and that is an improvement.** The host's `isModuleEnabled()` returns `true` for a module with no row, and `true` again from its `catch (\Throwable)`. So an unknown module runs, and a database error also makes every module run. `module-manager` starts from nothing enabled and requires the host to name what it wants. That is the same argument this plan made against `team_id`'s `default(1)`: *a default that answers the question on the row's behalf* is how something ends up enabled that nobody decided to enable.

## What the two test files mean afterwards

They do not fail. They stop applying, which is worse, because a deleted test makes no noise.

- `ModuleSystemTest::test_module_persists_enabled_state_to_the_database` and `ModuleStateTest::test_enabled_state_survives_a_cache_clear` pin the exact behaviour being dropped. Their docblocks record a real past bug — enablement once lived in a cache key, so `install()` never persisted it and `cache:clear` wiped all module state. **That bug cannot recur under configuration-based enablement**, because there is no state to lose. The tests go with the code, and this ADR is the record of why.
- `ModuleStateTest::test_a_module_without_module_json_has_a_usable_name_and_does_not_fatal` pins a different fault — `getName()` reading an uninitialised typed property and throwing `\Error` on every request. Under `module-manager` a module without `module.json` is not discovered at all, so the fault is structurally impossible rather than merely fixed. Worth confirming against the adopted version rather than assumed.
- The remaining `ModuleSystemTest` cases assert getters on `BaseModule`. They test the class being deleted and carry nothing forward.

## Consequences

`app/Modules/` goes: 43 files, including four stub modules (`Api`, `Core`, `Filament`, `Livewire`) that ship a `module.json`, a `composer.json` and empty route files each. `AppServiceProvider`, `app/Console/Commands/ModuleCommand.php` and `config/modules.php` are rewritten or deleted with it, and the `modules` table's migration goes — this is pre-production, so it is edited out rather than dropped by a new migration.

**One thing to check at adoption time rather than trust here.** `ModuleDiscovery` requires every discovered module to have a `composer.json` with a package name, and throws on a duplicate capability across modules. The four stubs in `app/Modules/` each declare one. If any of them is moved rather than deleted, it has to be checked against those rules — a stub that was harmless as scaffolding becomes a boot failure as a manifest.

The adoption needs `composer require`, and Composer has no network in the agent environment working this repository. It wants a human or a networked session. Everything downstream is ordinary work — tracked on [#972](https://github.com/liberusoftware/ecommerce-laravel/issues/972).
