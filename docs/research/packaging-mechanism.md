# The `modules/` + `liberusoftware/composer-installer` packaging mechanism

**Research for [#928](https://github.com/liberusoftware/ecommerce-laravel/issues/928)** (part of #925). Answers: how does the boilerplate's module packaging actually work, in enough detail to reproduce it in `ecommerce-laravel` without re-reading the sources.

Every claim below cites the file it came from. Source repositories, all read at their checked-out state:

| Short name used below | Repository |
| --- | --- |
| `boilerplate-laravel/…` | `liberusoftware/boilerplate-laravel` (the reference host) |
| `composer-installer/…` | `liberusoftware/composer-installer` v1.1.0 |
| `package-testbench/…` | `liberusoftware/package-testbench` v1.8.0 |
| `documentation/…` | `liberusoftware/documentation` |

---

## 1. The one-sentence model

> A module is a Composer package that a host **installs** without **booting**.
> — `documentation/modules/authoring/README.md:18`

Three separate states, from the same file (lines 20-25):

```text
Installed   composer require put the code in /modules
Enabled     the manifest said default_enabled, or the deployment overrode it
Booted      the module resolver returned this provider, in dependency order
```

Everything else in this document follows from one constraint: **a module package declares no `extra.laravel.providers`**, so Laravel's own package discovery finds nothing and installing a package can never boot it (`documentation/modules/authoring/README.md:26`, `documentation/modules/authoring/WALKTHROUGH.md:105`). That rule is machine-enforced — see §9.

`documentation/architecture/MODULES.md:366-377` states the full gate chain: `Installed -> Enabled -> Entitled -> Authorized`, evaluated independently.

---

## 2. The host `composer.json` shape

Read from `boilerplate-laravel/composer.json`.

### 2.1 `repositories` — a VCS entry per package

The root is `"type": "project"`. Every Liberu package is pulled from its own GitHub repository via a `vcs` repository entry — one object per repo, 48 of them:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/liberusoftware/composer-installer.git" },
    { "type": "vcs", "url": "https://github.com/liberusoftware/analytics-contracts.git" },
    { "type": "vcs", "url": "https://github.com/liberusoftware/module-identity-core.git" },
    { "type": "vcs", "url": "https://github.com/liberusoftware/module-identity-core-filament.git" },
    { "type": "vcs", "url": "https://github.com/liberusoftware/theme-default.git" }
]
```

Note the asymmetry, confirmed by `documentation/modules/authoring/WALKTHROUGH.md:40`: **the repository name carries a `module-` prefix and the Composer package name does not.** `liberusoftware/module-identity-core` on GitHub publishes as `liberusoftware/identity-core` on Packagist. Theme repositories carry no prefix (`theme-default` → `liberusoftware/theme-default`).

The VCS entries are only needed because the fleet is not fully on Packagist; `documentation/modules/authoring/RELEASING.md:93` says first publication is a one-time manual Packagist submission. Once a package is on Packagist its `repositories` entry becomes redundant, but the boilerplate keeps them all.

### 2.2 `require` — installer plugin plus flat package list

```json
"require": {
    "php": "^8.5",
    "filament/filament": "~5.1",
    "laravel/framework": "^13.0",
    "livewire/livewire": "^4.0",
    "liberusoftware/composer-installer": "^1.1",
    "liberusoftware/module-manager": "^1.0",
    "liberusoftware/module-manager-filament": "^1.1",
    "liberusoftware/identity-core": "^1.0",
    "liberusoftware/identity-core-filament": "^1.0",
    "liberusoftware/settings": "^1.0",
    "liberusoftware/settings-filament": "^1.0",
    "liberusoftware/theme-support": "^1.0",
    "liberusoftware/theme-support-livewire": "^1.1",
    "liberusoftware/theme-base": "^2.0",
    "liberusoftware/theme-default": "^1.0"
}
```

There is no nesting and no metapackage in the reference host: the application explicitly requires each module and each `-filament`/`-livewire`/`-api` companion it wants, plus the installer plugin. `documentation/architecture/MODULES.md:195` makes this mandatory: *"An application must explicitly require both the selected modules and the installer plugin."*

A host-side architecture test asserts the list stays complete — `boilerplate-laravel/tests/Architecture/ModuleBoundariesTest.php:130-140` walks every `modules/*/composer.json` and `themes/*/composer.json` and requires the root `composer.json` `require` to contain each package name, *and* requires the root's own `autoload.psr-4` to contain no `Liberu\` namespace at all. Composer owns every module autoload boundary; the host must not hand-map one.

### 2.3 `config.allow-plugins`

```json
"config": {
    "platform": { "php": "8.5" },
    "platform-check": false,
    "optimize-autoloader": true,
    "preferred-install": "dist",
    "sort-packages": true,
    "allow-plugins": {
        "liberusoftware/composer-installer": true,
        "pestphp/pest-plugin": true,
        "php-http/discovery": true
    }
}
```

`liberusoftware/composer-installer: true` is the load-bearing line — without it Composer refuses to run the plugin and every module lands in `vendor/` instead. `documentation/architecture/MODULES.md:197` puts ownership of this entry at the application root only: *"The application root is the single owner of `liberusoftware/composer-installer` and its `allow-plugins` entry. Individual modules declare their package type and installer name but do not repeat the installer plugin as a runtime requirement."* No module's `composer.json` requires the installer — verified across `boilerplate-laravel/modules/*/composer.json`.

### 2.4 `extra` and stability

The root `extra` is minimal — the module system needs nothing there:

```json
"extra": { "laravel": { "dont-discover": [] } },
"minimum-stability": "dev",
"prefer-stable": true
```

`minimum-stability: dev` + `prefer-stable: true` is what lets `dev-main` branches of the VCS repos resolve while still preferring tags.

### 2.5 Scripts

```json
"scripts": {
    "post-autoload-dump": [
        "Illuminate\\Foundation\\ComposerScripts::postAutoloadDump",
        "@php artisan package:discover --ansi",
        "@php artisan filament:upgrade"
    ],
    "test": "vendor/bin/pest"
}
```

Standard Laravel; `package:discover` deliberately finds no Liberu module (§9).

---

## 3. The installer: package types and install paths

Source: `composer-installer/src/Plugin.php` and `composer-installer/src/LiberuInstaller.php`. The whole plugin is two classes, ~90 lines.

### 3.1 Registration

`composer-installer/composer.json`:

```json
{
    "name": "liberusoftware/composer-installer",
    "version": "1.1.0",
    "type": "composer-plugin",
    "require": { "php": "^8.5", "composer-plugin-api": "^2.6" },
    "extra": {
        "class": "Liberu\\ComposerInstaller\\Plugin",
        "plugin-modifies-install-path": true
    }
}
```

`plugin-modifies-install-path: true` is required by Composer 2 for any plugin that relocates packages. `Plugin::activate()` (`composer-installer/src/Plugin.php:12-15`) does exactly one thing:

```php
$composer->getInstallationManager()->addInstaller(new LiberuInstaller($io, $composer));
```

`LiberuInstaller` extends Composer's stock `LibraryInstaller`, so download, extraction, update and removal are all inherited — only the *path* is overridden.

### 3.2 Recognised types

`composer-installer/src/LiberuInstaller.php:27-30`:

```php
public function supports(string $packageType): bool
{
    return in_array($packageType, ['liberu-module', 'liberu-theme'], true);
}
```

Exactly two types. `composer-installer/tests/Unit/LiberuInstallerTest.php:59-70` pins this: `library`, `composer-plugin`, `liberu-modules` and `''` all return false. Everything else — Laravel, Filament, vendor SDKs — stays in `vendor/`, as `documentation/architecture/MODULES.md:193` requires.

### 3.3 Path computation

`composer-installer/src/LiberuInstaller.php:32-45`:

```php
public function getInstallPath(PackageInterface $package): string
{
    $name = $package->getExtra()['liberu']['name'] ?? null;

    if (! is_string($name) || ! preg_match(self::NAME_PATTERN, $name)) {
        throw new InvalidArgumentException("Package [{$package->getPrettyName()}] has an invalid Liberu installer name.");
    }

    $target = ($package->getType() === 'liberu-theme' ? 'themes' : 'modules').'/'.$name;

    $this->guardAgainstCollision($target, $package->getPrettyName());

    return $target;
}
```

| Composer `type` | Install path |
| --- | --- |
| `liberu-module` | `<project-root>/modules/{extra.liberu.name}` |
| `liberu-theme` | `<project-root>/themes/{extra.liberu.name}` |

This matches the table in `documentation/architecture/MODULES.md:186-190`.

**The directory name comes from `extra.liberu.name`, not from the Composer package name.** `composer-installer/tests/Unit/LiberuInstallerTest.php:44-48` asserts this explicitly: `liberusoftware/module-identity-core-filament` with `extra.liberu.name = "identity-filament"` installs to `modules/identity-filament`. In practice the fleet keeps them aligned (`liberusoftware/identity-core-filament` → `identity-core-filament`), and the boundary suite forces `extra.liberu.name` to equal `module.json`'s `name` (`package-testbench/src/BoundaryAssertions.php:292`), but the installer's authority is `extra.liberu.name`.

### 3.4 Name validation

`composer-installer/src/LiberuInstaller.php:17`:

```php
private const NAME_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
```

Anchored, and admits no `/`, `\` or `.`, so traversal and absolute paths cannot reach the computed target. `composer-installer/tests/Unit/LiberuInstallerTest.php:72-98` covers the rejections: missing, empty, `Search`, `my_module`, `-search`, `search-`, `search--core`, non-string, array, `../evil`, `/etc/passwd`, `nested/path`, `back\slash`, `.`, `..`.

### 3.5 Collision detection

`composer-installer/src/LiberuInstaller.php:52-84` guards two ways:

1. **Within one run** — an in-memory `$targets` map of `target path => package that claimed it`.
2. **Against the working tree** — `packageDeclaredAt()` reads `<root>/<target>/composer.json` and returns its `name`. A directory left behind by a renamed or removed package outlives the process that created it and would otherwise be silently installed over.

An absent, unreadable or nameless `composer.json` is *not* evidence of ownership, so only a different, named package blocks the install (`composer-installer/tests/Unit/LiberuInstallerTest.php:125-138`). Re-installing the same package into its own directory is fine (lines 116-123).

---

## 4. `modules/` and `themes/` are both Composer targets and Git-tracked

This is the part that looks wrong at first glance. `boilerplate-laravel/.gitignore:1-10`:

```gitignore
/vendor/
# Composer installs Liberu packages into these directories; both are intentionally tracked.
!/modules/
!/modules/**
!/themes/
!/themes/**
# ...except the dependencies a package installs when its own suite is run standalone.
/modules/*/vendor/
/themes/*/vendor/
/modules/*/composer.lock
/themes/*/composer.lock
```

So: the installed package source is committed, but each package's *own* `vendor/` and `composer.lock` (created when you run that package's suite standalone) are not.

The policy is `documentation/architecture/MODULES.md:199-213` (§6.2 "Tracked `/modules` policy"). Its obligations, verbatim from lines 205-211:

- run Composer commands to add/update/remove modules; never edit installed module files directly in a consuming application;
- make source changes in the module's independent repository, release a version, then update the consuming application;
- commit `composer.json`, `composer.lock`, and the corresponding `/modules` changes together;
- review installed-code diffs and release notes during dependency updates;
- **CI performs a clean locked install and fails if it produces an uncommitted `/modules` diff**;
- security/dependency tooling scans both `/vendor` and `/modules`;
- merge conflicts in generated installed content are resolved by Composer from the intended lockfile, not by hand-merging module code.

The zero-diff gate is `git status --porcelain` after `composer update` — `documentation/modules/authoring/WALKTHROUGH.md:340` and `documentation/modules/authoring/RELEASING.md:44,55`. `RELEASING.md:57`: *"A non-empty diff means the published package and the tracked tree disagree. **The published side is authoritative**… Resolve it by publishing the missing change, not by committing the diff."*

The current tracked tree in the boilerplate: 40 directories under `modules/`, 4 under `themes/` (`base`, `clear-signal`, `dark`, `default`).

---

## 5. What a module package looks like

### 5.1 `composer.json`

Real file, `boilerplate-laravel/modules/identity-core/composer.json`:

```json
{
  "name": "liberusoftware/identity-core",
  "description": "Provider-neutral identity policy, normalization, and versioned security events.",
  "type": "liberu-module",
  "license": "MIT",
  "require": {
    "php": "^8.5",
    "illuminate/support": "^13.0",
    "liberusoftware/module-manager": "^1.0"
  },
  "autoload": { "psr-4": { "Liberu\\Foundation\\Identity\\": "src/" } },
  "extra": { "liberu": { "name": "identity-core" } },
  "version": "1.4.1",
  "autoload-dev": { "psr-4": { "Liberu\\Foundation\\Identity\\Tests\\": "tests/" } },
  "require-dev": {
    "liberusoftware/package-testbench": "^1.5",
    "pestphp/pest": "^5.0"
  },
  "scripts": { "test": "pest" },
  "config": { "allow-plugins": { "pestphp/pest-plugin": true } }
}
```

Four non-obvious things (`documentation/modules/authoring/WALKTHROUGH.md:103-110`):

- **No `extra.laravel.providers`, and there must not be.** See §9.
- **`version` is present, deliberately**, against Composer's usual guidance, because the resolver compares the manifest version against `Composer\InstalledVersions` at boot. Consequence: CI runs `composer validate` **without** `--strict`, because `--strict` promotes the "version should be omitted" warning to an error (`documentation/modules/authoring/RELEASING.md:81-87`).
- **No `orchestra/testbench`** — it arrives transitively through `package-testbench`.
- **`config.allow-plugins.pestphp/pest-plugin: true` is mandatory**, and the host asserts it: `boilerplate-laravel/tests/Architecture/ModuleBoundariesTest.php:41-54` fails any package missing it, because *"its standalone `composer update` aborts before installing Pest."*

Package repos ship no lock file (`boilerplate-laravel/modules/module-manager/.gitignore`):

```gitignore
# A library must not ship its own dependency tree: a consumer resolves its own,
# and a committed vendor/ only publishes whoever last ran composer update.
/vendor/
/composer.lock
/.phpunit.cache
/.phpunit.result.cache
```

### 5.2 `module.json` — the runtime manifest

Real file, `boilerplate-laravel/modules/identity-core/module.json`:

```json
{
  "$schema": "https://schemas.liberu.dev/module/v1.json",
  "name": "identity-core",
  "display_name": "Identity",
  "description": "Provider-neutral authentication policy, identifier normalization, and security events.",
  "version": "1.4.1",
  "category": "foundation",
  "provider": "Liberu\\Foundation\\Identity\\IdentityServiceProvider",
  "requires": {
    "php": "^8.5",
    "laravel": "^13.0",
    "packages": { "liberusoftware/module-manager": "^1.0" }
  },
  "suggests": {
    "liberusoftware/jetstream-bridge": "^1.0",
    "liberusoftware/two-factor-authentication": "^1.0",
    "liberusoftware/profiles": "^1.0"
  },
  "capabilities": ["identity.authenticate", "identity.recover"],
  "default_enabled": true,
  "features": [
    "Provider-neutral authentication policy",
    "Identifier normalization",
    "Security events"
  ]
}
```

Required keys, enforced by `boilerplate-laravel/modules/module-manager/src/Manifest.php:18-22`: `name`, `display_name`, `description`, `version`, `category`, `provider`, `requires`, `capabilities`, `features`, `default_enabled`. Further validation in the same file:

| Rule | Line |
| --- | --- |
| `name` matches `/^[a-z0-9]+(?:-[a-z0-9]+)*$/` | `Manifest.php:24` |
| `category` ∈ `foundation, contracts, capability, adapter, product, presentation, distribution` | `Manifest.php:9,32` |
| `default_enabled` must be a real boolean | `Manifest.php:36` |
| each capability matches `/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/` | `Manifest.php:41` |
| at least one feature; each trimmed, non-empty, ≤120 chars; no case-insensitive duplicates | `Manifest.php:46-58` |

`presentation.filament` is optional and read by `Manifest::filamentPlugins(string $panel)` (`Manifest.php:132-137`) — see §7.

**`default_enabled` is the enablement decision, made in the package, not the host** (`documentation/modules/authoring/WALKTHROUGH.md:138`): *"A host's `config/modules.php` names no modules at all — it holds only an enable list and a disable list, both empty by default."*

### 5.3 `phpunit.xml`

Identical across the fleet — `boilerplate-laravel/modules/module-manager/phpunit.xml` and `.../identity-core-filament/phpunit.xml` are byte-equal apart from nothing:

```xml
<phpunit bootstrap="vendor/autoload.php" colors="true">
    <testsuites>
        <testsuite name="Package"><directory>tests</directory></testsuite>
        <!-- Shipped by the testbench, not by this repository -->
        <testsuite name="Boundary">
            <directory suffix="Test.php">vendor/liberusoftware/package-testbench/tests/Boundary/Module</directory>
        </testsuite>
    </testsuites>
    <source><include><directory>src</directory></include></source>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
    </php>
</phpunit>
```

Themes point at `tests/Boundary/Theme`, contract packages at `tests/Boundary/Contract` (`documentation/modules/authoring/WALKTHROUGH.md:232`).

### 5.4 Full directory shape

`documentation/architecture/MODULES.md:217-256` gives the permitted layout. Real, minimal example — `boilerplate-laravel/modules/identity-core/`:

```text
identity-core/
├── .github/workflows/{compatibility,install,tests}.yml
├── CHANGELOG.md  LICENSE.md  README.md
├── composer.json
├── module.json
├── phpunit.xml
├── config/identity.php
├── database/migrations/2026_06_29_123953_create_passkeys_table.php
├── src/
│   ├── Contracts/  Events/  Listeners/  Support/
│   └── IdentityServiceProvider.php
└── tests/.gitkeep
```

**`tests/.gitkeep` is load-bearing** (`documentation/modules/authoring/WALKTHROUGH.md:53-63`). Git does not track empty directories; a package whose only tests are the shared boundary suite publishes a tarball with no `tests/` at all, and Pest aborts with *"The test directory [tests] does not exist."* In the reference fleet this shipped in **34 packages at once** and passed the pre-release sweep, because the directory existed on the machine that ran it.

---

## 6. Discovery, the registry, and the enable/disable lifecycle

All in `boilerplate-laravel/modules/module-manager/`.

### 6.1 Bootstrapping

`boilerplate-laravel/bootstrap/providers.php` — the only Liberu provider the host names:

```php
return [
    ModuleManagerServiceProvider::class,
    AdminPanelProvider::class,
    AppPanelProvider::class,
];
```

`ModuleManagerServiceProvider::register()` (`modules/module-manager/src/ModuleManagerServiceProvider.php:17-42`) does the whole job:

```php
$this->mergeConfigFrom(__DIR__.'/../config/modules.php', 'modules');
$this->app->singleton(RegistryCache::class);
$this->app->singleton(ModuleRegistry::class, fn () => $this->app->make(RegistryCache::class)->load(
    (array) config('modules.paths', [base_path('modules')]),
    (bool) config('modules.cache', false),
    (string) config('modules.cache_path'),
));

$registry = $this->app->make(ModuleRegistry::class);
$modules  = $registry->resolve(config('modules.enabled', []), config('modules.disabled', []));
$selected = [];
foreach ($modules as $module) { $selected[$module->name()] = $module; }

$this->app->make(ModuleValidationGuard::class)
    ->ensureValid(new ModuleRegistry($selected), Application::VERSION);

foreach ($modules as $module) {
    if ($module->name() !== 'module-manager') {
        $this->app->register($module->provider());
    }
}
```

Registration happens in `register()`, not `boot()`, so module providers get their own full `register()`/`boot()` cycle. `module-manager` skips itself — it is already registered.

### 6.2 Discovery

`modules/module-manager/src/ModuleDiscovery.php:11-66`. Two path sources, unioned:

```php
if (class_exists(InstalledVersions::class)) {
    foreach (InstalledVersions::getInstalledPackagesByType('liberu-module') as $package) {
        $installPath = InstalledVersions::getInstallPath($package);
        if (is_string($installPath)) { $paths[] = dirname($installPath); }
    }
}
$paths = array_values(array_unique(array_filter(array_map(realpath(...), $paths))));

foreach ($paths as $root) {
    foreach (glob(rtrim($root, '/').'/*/module.json') ?: [] as $path) { … }
}
```

So: `config('modules.paths')` (default `base_path('modules')`) **plus** the parent directory of every Composer package of type `liberu-module`. In a host those are the same directory; in a package's own standalone suite the Composer path is what makes siblings under `vendor/` reachable. Dedupe is on `realpath`.

Per candidate, discovery enforces (`ModuleDiscovery.php:33-59`):

- `module.json` parses and validates via `Manifest::fromFile()`;
- a sibling `composer.json` exists and has a `name` — otherwise `InvalidManifest`;
- no duplicate module name, unless it is the *same* Composer package at the *same* version (the dedupe case);
- no duplicate Composer package name;
- no duplicate capability across the whole registry.

Result is `ksort`ed by module name.

### 6.3 The registry and resolution

`modules/module-manager/src/ModuleRegistry.php`. Surface: `has()`, `get()`, `all()`, `enabled()`, `searchFeatures()`, `providingFeature()`, `resolve()`.

`resolve(array $enabled, array $disabled): list<Manifest>` (`ModuleRegistry.php:74-160`), in order:

**1. Selection** (lines 76-83):

```php
foreach ($this->modules as $name => $manifest) {
    if (! in_array($name, $disabled, true)
        && ($manifest->defaultEnabled() || in_array($name, $enabled, true))) {
        $selected[$name] = $manifest;
    }
}
```

`disabled` beats both the manifest and `enabled`.

**2. Ownership maps** (lines 84-98): `packageOwners[composer name] => module name` from each manifest's sibling `composer.json`; `capabilityOwners[capability] => module name`.

**3. Dependency checks** (lines 100-127), throwing `DependencyResolutionFailed`:

- a required package with no module owner must be installed and satisfy its constraint per `Composer\InstalledVersions` + `Semver` — this covers third-party libraries;
- a required package owned by a module must satisfy the constraint against *that module's manifest version*, and must itself be selected — *"Module [X] requires enabled package [Y]."*;
- a required capability must exist and its owner be selected and version-satisfying.

**4. Topological sort** (lines 129-157): depth-first over required packages and capabilities, with `$visiting` cycle detection (*"Circular module dependency involving [X]."*), iterated over a `sort()`ed name list so the tie-break is stable.

`boilerplate-laravel/tests/Unit/CanonicalModuleDiscoveryTest.php:16-24` pins the ordering: `module-manager` before `settings` before `settings-filament`; `search` before `search-api`.

### 6.4 Enable / disable — how a deployment overrides

Host config, `boilerplate-laravel/config/modules.php`:

```php
return [
    // Composer-installed module packages. Local paths may be appended for development.
    'paths' => [base_path('modules')],

    // Which modules boot is the manifests' decision, not this file's: ModuleRegistry::resolve()
    // selects every installed module whose module.json declares default_enabled, so installing a
    // package is what offers it and its own manifest is what turns it on. The two lists below are
    // deployment overrides on top of that, empty by default.
    'enabled'  => array_values(array_filter(explode(',', (string) env('MODULES_ENABLED', '')))),
    'disabled' => array_values(array_filter(explode(',', (string) env('MODULES_DISABLED', '')))),

    'cache'     => env('MODULES_CACHE', false),
    'cache_key' => 'liberu.modules.registry.v1',
];
```

The package ships its own default (`modules/module-manager/config/modules.php`), merged under the host's via `mergeConfigFrom`; the host file omits `cache_path`, so the package value wins:

```php
'cache_path' => base_path('bootstrap/cache/liberu-modules.php.cache'),
```

So the levers are exactly two env vars: `MODULES_ENABLED` and `MODULES_DISABLED`, comma-separated module names. A host architecture test forbids putting names in the file itself — `boilerplate-laravel/tests/Architecture/ModuleBoundariesTest.php:56-83` asserts both arrays are `[]` and that what `resolve([], [])` returns equals exactly the set of manifests with `default_enabled: true`. Lines 85-107 assert both levers behave: `MODULES_ENABLED` turns on a module its manifest leaves off (`analytics-google`), `MODULES_DISABLED` beats it, and disabling `search` throws `Module [search-api] requires enabled package [liberusoftware/search ^1.0]` rather than quietly dropping it.

### 6.5 Validation guard

Every boot runs `ModuleValidator` over the *selected* registry (`modules/module-manager/src/ModuleValidator.php:12-77`), and `ModuleValidationGuard` (`ModuleValidationGuard.php:9-15`) throws a `RuntimeException` listing all errors. Checks per module:

| Check | Line |
| --- | --- |
| `composer.json` `extra.liberu.name` == manifest `name` | 20 |
| `composer.json` `type` == `liberu-module` | 24 |
| Composer package installed per `InstalledVersions` | 29 |
| installed pretty version == manifest `version` | 31 |
| `require` entries under `liberusoftware/` == manifest `requires.packages` | 35-42 |
| provider class autoloadable and a `ServiceProvider` subclass | 44-48 |
| PHP version satisfies `requires.php` | 50 |
| Laravel version satisfies `requires.laravel` | 54 |
| only `presentation` modules declare Filament plugins; declared plugin classes exist (panels `admin`, `app`) | 58-67 |
| `resolve()` does not throw | 70-74 |

The version check is why `documentation/modules/authoring/RELEASING.md:18` warns: *"Bumping a manifest in a monorepo that also **installs** the package makes the host unbootable until the package is published and `composer update`d."*

### 6.6 Registry cache

`modules/module-manager/src/Cache/RegistryCache.php`. `load()` returns an unserialized `ModuleRegistry` when `modules.cache` is on and the file exists, else re-discovers. `write()` serializes to `<path>.<pid>.tmp` then moves — atomic (`RegistryCache.php:33-43`). An invalid cache throws *"The module registry cache is invalid; run module:clear."*

### 6.7 Console surface

Registered in `boot()` when `runningInConsole()` (`ModuleManagerServiceProvider.php:48-50`), from `modules/module-manager/src/Console/`:

| Command | Class | What it does |
| --- | --- | --- |
| `module:list` | `ListModulesCommand.php:10` | table of Module / Version / Category / Enabled / Capabilities / Features count |
| `module:status {name}` | `ModuleStatusCommand.php:10` | installed, enabled, version, provider, dependencies, capabilities, features, path |
| `module:features` | `ListFeaturesCommand.php` | searches `ModuleRegistry::searchFeatures()` |
| `module:validate` | `ValidateModulesCommand.php` | runs `ModuleValidator` and reports |
| `module:cache` | `CacheModulesCommand.php:15-22` | re-discovers and atomically writes the registry cache |
| `module:clear` | `ClearModulesCommand.php` | deletes the cache file |

`documentation/architecture/MODULES.md:381-390` describes this as the deterministic seven-step startup; §12's lifecycle table (lines 395-401) states the semantics: **Disable** stops entry points and schedules while retaining data; **Uninstall** requires an explicit retention choice and preserves data by default. Rule 13 (line 82): *"Disabling or uninstalling a module never silently deletes data."*

---

## 7. What a module ships, and how each part is wired

Everything below is done by the module's own service provider — the one class `module.json`'s `provider` key names. `documentation/architecture/MODULES.md:508`: *"`register()` binds contracts and merges configuration without request state or side effects. `boot()` registers package-owned routes, policies, commands, events, schedules, views, and publishable resources."*

### 7.1 Migrations and config — `identity-core`

`boilerplate-laravel/modules/identity-core/src/IdentityServiceProvider.php`:

```php
public function register(): void
{
    $this->mergeConfigFrom(__DIR__.'/../config/identity.php', 'identity');
    $this->app->singleton(RegistrationPolicy::class, fn () => new ConfiguredRegistrationPolicy((string) config('identity.registration', 'open')));
    $this->app->bind(InvitationValidator::class, RejectingInvitationValidator::class);
}

public function boot(): void
{
    $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    Event::listen([Failed::class, Login::class, Logout::class], EmitAuthenticationEvent::class);
    $this->publishes([__DIR__.'/../config/identity.php' => config_path('identity.php')], 'identity-config');
}
```

Plain Laravel package conventions — nothing module-manager-specific. `loadMigrationsFrom` means module migrations run from `php artisan migrate` in the host with no publishing step; `publishes(…, 'identity-config')` gives an opt-in override. 21 of the 40 modules carry `database/migrations`.

`boilerplate-laravel/modules/settings/src/SettingsServiceProvider.php` shows the publish-only variant for a directory the host must own (spatie settings migrations):

```php
$this->loadMigrationsFrom(__DIR__.'/../database/migrations');
$this->publishes([__DIR__.'/../database/settings' => database_path('settings')], 'settings-migrations');
```

### 7.2 Views

`$this->loadViewsFrom(__DIR__.'/../resources/views', '<namespace>')` — e.g. `modules/module-manager-filament/src/ModuleManagerFilamentServiceProvider.php:11` registers namespace `module-manager-filament`, consumed as `protected string $view = 'module-manager-filament::pages.foundation-operations';` (`modules/module-manager-filament/src/Pages/FoundationOperations.php:11`). Same pattern in `theme-support`, `theme-support-livewire`, `localization-core-livewire`, `sessions-devices-filament`.

### 7.3 Routes

`$this->loadRoutesFrom(...)` in `boot()`. Only two modules in the fleet own routes:

- `modules/search-api/src/SearchApiServiceProvider.php:11` → `routes/api.php`
- `modules/application/src/ApplicationCoreServiceProvider.php:26` → `routes/health.php`

`documentation/architecture/MODULES.md:299` requires package-prefixed route names (`billing.invoices.show`).

### 7.4 Translations

**No module in the reference fleet currently ships translations.** A grep for `loadTranslationsFrom`/`loadJsonTranslationsFrom` across `boilerplate-laravel/modules/*/src/**.php` returns nothing. The permitted location is `resources/lang/` per the standard layout (`documentation/architecture/MODULES.md:233-234`), and the wiring would be the ordinary `$this->loadTranslationsFrom(__DIR__.'/../resources/lang', '<namespace>')` in `boot()`. Localization behaviour itself lives in the `localization-core` capability, not in per-module lang files. **Treat this as an unexercised path** if ecommerce modules need translations.

### 7.5 Livewire components

`boilerplate-laravel/modules/theme-support-livewire/src/ThemeSupportLivewireServiceProvider.php` — the whole file:

```php
final class ThemeSupportLivewireServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'theme-support-livewire');
        Livewire::component('theme-switcher', ThemeSwitcher::class);
    }
}
```

Explicit `Livewire::component()` registration in `boot()`, plus a view namespace. Same shape in `modules/localization-core-livewire/src/LocalizationLivewireServiceProvider.php:13-14` (`language-switcher`). There is no `presentation.livewire` manifest key — Livewire components are registered by the provider, not declared to the host.

### 7.6 Filament plugins — the one thing that *is* declared, not registered

A Filament module's provider registers **no** Filament anything. `boilerplate-laravel/modules/identity-core-filament/src/IdentityFilamentServiceProvider.php` is literally:

```php
final class IdentityFilamentServiceProvider extends ServiceProvider {}
```

The plugin is declared in the manifest instead (`modules/identity-core-filament/module.json:17-23`):

```json
"presentation": {
  "filament": {
    "admin": ["Liberu\\Foundation\\IdentityFilament\\IdentityFilamentPlugin"]
  }
}
```

The plugin class is an ordinary Filament plugin (`modules/identity-core-filament/src/IdentityFilamentPlugin.php`):

```php
final class IdentityFilamentPlugin implements Plugin
{
    public static function make(): self { return new self(); }
    public function getId(): string { return 'liberu-identity'; }
    public function register(Panel $panel): void { $panel->resources([UserResource::class]); }
    public function boot(Panel $panel): void {}
}
```

The host composes it. `boilerplate-laravel/app/Filament/ModulePlugins.php` — the entire bridge:

```php
final readonly class ModulePlugins
{
    public function __construct(private ModuleRegistry $modules) {}

    /** @return list<Plugin> */
    public function forPanel(string $panel): array
    {
        $plugins = [];
        $resolved = $this->modules->resolve((array) config('modules.enabled', []), (array) config('modules.disabled', []));

        foreach ($resolved as $module) {
            foreach ($module->filamentPlugins($panel) as $pluginClass) {
                $plugin = method_exists($pluginClass, 'make') ? $pluginClass::make() : app($pluginClass);
                if (! $plugin instanceof Plugin) {
                    throw new RuntimeException("Module [{$module->name()}] returned an invalid Filament plugin.");
                }
                if (isset($plugins[$plugin->getId()])) {
                    throw new RuntimeException("Duplicate Filament plugin id [{$plugin->getId()}].");
                }
                $plugins[$plugin->getId()] = $plugin;
            }
        }

        return array_values($plugins);
    }
}
```

Only **enabled** modules contribute. Duplicate plugin ids and non-`Plugin` returns are hard failures.

Panel providers call it with their own id — `boilerplate-laravel/app/Providers/Filament/AdminPanelProvider.php:65`:

```php
->plugins(app(ModulePlugins::class)->forPanel('admin'))
```

and `app/Providers/Filament/AppPanelProvider.php:56` with `'app'` (panel id set at line 30).

Panel ids in use across the fleet, from the manifests: `admin` for `identity-core-filament`, `organizations-teams-filament`, `module-manager-filament`, `roles-permissions-filament`, `settings-filament`; `app` for `sessions-devices-filament`. `ModuleValidator.php:58` only iterates `['admin', 'app']`, and `boilerplate-laravel/tests/Architecture/ModuleBoundariesTest.php:181` asserts `expect($panel)->toBeIn(['admin', 'app'])` — **adding a third panel means editing both.**

`documentation/modules/authoring/PRESENTATION.md:26` states the intent: *"This is what an application reads to compose its panels; nothing scans for plugins."*

### 7.7 Themes

Themes are `liberu-theme` packages installed to `themes/{name}` with a `theme.json` instead of `module.json` (`boilerplate-laravel/themes/default/theme.json`): `name`, `display_name`, `version`, `provider`, `type`, `parent`, `optimized_for`, `tested_with`, `required_capabilities`, `optional_capabilities`, `supports`, `assets.{css,js}`, `colors`. No `requires` key — *"themes declare a parent and their assets, and let Composer own dependencies"* (`package-testbench/src/BoundaryAssertions.php:106-111`).

`theme-support` (a `liberu-module`) discovers them: `modules/theme-support/src/Discovery/ThemeDiscovery.php:30-78` scans the tracked `themes/` directory **and** `InstalledVersions::getInstalledPackagesByType('liberu-theme')`, dedupes on `realpath`, and rejects a theme whose `composer.json` `type` is not `liberu-theme` or whose `extra.liberu.name` disagrees with `theme.json`. Its provider registers each theme's provider in `register()` (`modules/theme-support/src/Providers/ThemeServiceProvider.php:24-26`).

---

## 8. The domain / `-filament` / `-livewire` / `-api` split

`documentation/architecture/MODULES.md:78` rule 9: *"Domain packages never depend on Filament, themes, or another optional presentation layer."* §5.6 (lines 125-135) and §20 (lines 521-539) elaborate. The naming table (`MODULES.md:279-290`):

| Role | Composer name | Example |
| --- | --- | --- |
| Shared core | `liberusoftware/{capability}-core` | `liberusoftware/payment-core` |
| Filament adapter | `liberusoftware/module-{module}-filament` | `liberusoftware/identity-core-filament` |
| Livewire adapter | `liberusoftware/module-{module}-livewire` | `liberusoftware/theme-support-livewire` |
| API adapter | `liberusoftware/module-{module}-api` | `liberusoftware/search-api` |
| Contracts | `liberusoftware/{capability}-contracts` | `liberusoftware/analytics-contracts` |

Real dependency directions, from the packages:

| Package | `category` | `require` (Liberu) | Ships |
| --- | --- | --- | --- |
| `identity-core` (`modules/identity-core/composer.json`) | `foundation` | `module-manager` | config, migration, contracts, events |
| `identity-core-filament` (`modules/identity-core-filament/composer.json`) | `presentation` | `roles-permissions` + `filament/filament ^5.1` | plugin + `UserResource` + pages |
| `theme-support-livewire` (`modules/theme-support-livewire/composer.json`) | `presentation` | `theme-support` + `livewire/livewire ^4.0` | component + view |
| `search-api` (`modules/search-api/composer.json`) | `presentation` | `search` + `illuminate/http`, `illuminate/routing` | `routes/api.php` |

The arrow runs one way only (`documentation/modules/authoring/PRESENTATION.md:20`): *"The domain package must not know its surface exists, and must not gain an optional dependency on it."* Note `identity-core` does *not* require `identity-core-filament`; it lists related packages under `suggests` in `module.json`.

`documentation/architecture/MODULES.md:130` adds the granularity rule: **one `-filament` package per independent domain module**, covering all panels that module needs; *"umbrella product Filament packages must not combine several independent modules."* Same for `-api` (line 133).

Contract packages are a distinct shape: `type: library` (not `liberu-module`), no manifest, no provider, and no framework dependency at all (`package-testbench/src/BoundaryAssertions.php:140-169`). `analytics-contracts` and `localization-contracts` in the boilerplate's `require` are these.

Three boundary rules enforce the split without a host (`package-testbench/tests/Boundary/Module/ModuleBoundaryTest.php:51-70`):

- a non-`presentation` module's `src/` must contain no `Filament\` string;
- an `-api` package's `src/` must not `use Liberu\…\Models\…`;
- a `-filament` package must be `category: presentation` and declare at least one existing plugin class.

---

## 9. Why nothing auto-boots (the enforcement chain)

The rule "installing never boots" is asserted in three independent places:

1. **Package-side, in every repo's own suite** — `package-testbench/src/BoundaryAssertions.php:68`:
   ```php
   // Enablement is an explicit decision: nothing may boot by Laravel's
   // package discovery just because Composer installed it.
   Assert::assertSame([], $composer['extra']['laravel']['providers'] ?? [], "Module at [{$root}] must not auto-register its provider.");
   ```
2. **Host-side** — the host's `composer.json` `extra.laravel.dont-discover` is empty because there is nothing to not-discover; `boilerplate-laravel/tests/Architecture/ModuleBoundariesTest.php:134` additionally forbids any `Liberu\` PSR-4 entry in the root autoload.
3. **Runtime** — `ModuleManagerServiceProvider::register()` is the *only* thing that calls `$this->app->register($module->provider())`, and only for modules `resolve()` returned.

The same fact makes the testbench's provider walk safe (see §10): scanning every installed package's `extra.laravel.providers` picks up framework packages and nothing Liberu.

---

## 10. How a module's own test suite bootstraps

Source: `package-testbench/src/PackageTestCase.php`, `package-testbench/src/PackageRoot.php`, and `documentation/modules/authoring/TEST-BOOTSTRAP.md`.

### 10.1 The default: two lines

`boilerplate-laravel/modules/module-manager/tests/Pest.php`:

```php
use Liberu\PackageTestbench\PackageTestCase;

pest()->extend(PackageTestCase::class)->in('Unit');
```

(The canonical form is `->in('Feature', 'Unit')` — `TEST-BOOTSTRAP.md:18`.)

`PackageTestCase extends Orchestra\Testbench\TestCase` and does four things (`TEST-BOOTSTRAP.md:21-26`):

1. **Locates the package root** from `getcwd()` by walking up to the nearest `composer.json` (`PackageRoot::locate()`, `PackageRoot.php:26-43`). `getcwd()` rather than `__DIR__` because the boundary suite executes from inside `vendor/`.
2. **Sets an application key** (`PackageTestCase.php:36-39`) — fixed, not random. Without it anything rendering a view dies on *"No application encryption key has been specified"*.
3. **Registers the provider `module.json` names** — `PackageTestCase.php:83`.
4. **Registers the providers of dependencies**, by the rules below.

### 10.2 Which providers boot

`PackageTestCase::getPackageProviders()` (`PackageTestCase.php:59-86`):

```php
foreach (['require', 'require-dev'] as $section) {
    foreach (array_keys($composer[$section] ?? []) as $package) {
        $dependency = $root.'/vendor/'.$package;
        if (! is_dir($dependency)) { continue; }

        foreach ((array) (PackageRoot::composer($dependency)['extra']['laravel']['providers'] ?? []) as $provider) {
            $providers[] = $provider;
        }

        if ($section === 'require-dev') {
            $providers[] = PackageRoot::manifest($dependency)['provider'] ?? null;
        }
    }
}
$providers[] = PackageRoot::manifest($root)['provider'] ?? null;
```

Testbench runs **no** package discovery, so two sources supply everything:

- `extra.laravel.providers` of any **direct** dependency (`require` or `require-dev`) — Laravel's own discovery, scoped;
- the manifest provider of a sibling declared in **`require-dev` only**.

The asymmetry is deliberate (`TEST-BOOTSTRAP.md:50-52`): *"A `require-dev` entry on a sibling module is a statement about what this package is tested against. A `require` entry is a statement about what it runs against. Booting the latter would contradict the enablement rule the whole architecture rests on."*

To boot a `require` sibling, name it explicitly — and **do not** duplicate the package into `require-dev`, which earns a `composer validate` warning for saying nothing true (`TEST-BOOTSTRAP.md:54-67`). Real example, `boilerplate-laravel/modules/identity-core-filament/tests/TestCase.php:49-62`, which names `RolesPermissionsServiceProvider::class` with the reason in a comment.

### 10.3 The actor

No package owns the `users` table; the testbench supplies one (`TEST-BOOTSTRAP.md:71-101`). `use Liberu\PackageTestbench\UsesTestUser` loads the base `users` migration (`package-testbench/database/migrations/0001_01_01_000000_create_users_table.php`) and brings `RefreshDatabase`. `TestUser` implements **none** of the fleet's actor contracts, deliberately — doing so would drag Horizon, Pulse, Telescope, Jetstream, Socialite and Socialstream into every package's dev tree. A package needing a contract subclasses `TestUser` in its own `tests/Fixtures/` (real example: `modules/identity-core-filament/tests/Fixtures/RoledUser.php`).

### 10.4 The boundary suite

Shipped, not copied. `phpunit.xml` points a testsuite at `vendor/liberusoftware/package-testbench/tests/Boundary/Module`, so *"a new boundary rule is a testbench release every repository picks up, rather than a change applied by hand across the fleet"* (`package-testbench/tests/Boundary/Module/ModuleBoundaryTest.php:9-29`). Seven rules for a module:

| Rule | Assertion | Catches |
| --- | --- | --- |
| internally consistent metadata | `BoundaryAssertions::moduleMetadataIsConsistent` | `composer.json`/`module.json` disagreeing on type, version, `extra.liberu.name`; `requires.packages` ≠ vendor-filtered `require`; a non-empty `extra.laravel.providers`; unusable feature list |
| ships required files | `shipsRequiredFiles` | missing `composer.json`, `README.md`, `LICENSE.md`, `CHANGELOG.md` |
| registers its provider | `declaredProviderRegisters` | a manifest naming a class that does not boot |
| no host dependency | `doesNotDependOnHostApplication` | `use|new|extends|implements App\` in `src/` |
| Filament out of domain | `keepsFilamentOutOfDomain` (skipped for `presentation`) | UI leaking into a domain package |
| no domain models in `-api` | `apiAdapterAvoidsDomainModels` (skipped unless name ends `-api`) | transport coupled to storage |
| `-filament` declares plugins | `presentationDeclaresPanelPlugins` (skipped unless name ends `-filament`) | an installed, booted, invisible presentation package |

Conditions live in `->skip()` with a reason, not in guard clauses, so a rule that does not apply reports as skipped instead of passing having asserted nothing (`ModuleBoundaryTest.php:23-28`).

Note `moduleMetadataIsConsistent` also enforces `MAJOR.MINOR.PATCH` on the version (`BoundaryAssertions.php:287-291`) and derives the vendor prefix from the package's own name rather than hardcoding it (`PackageRoot::vendor()`, `PackageRoot.php:95-104`) — a constant would make the whole fleet red during a vendor migration.

### 10.5 When a `tests/TestCase.php` is warranted

Six situations and no others (`TEST-BOOTSTRAP.md:105-116`): reading host-supplied config; needing a user; needing an actor contract or model scope; extending a `require` sibling's registry; needing migrations beyond its own; being a Filament presentation package. Any override of `defineEnvironment()` **must** call `parent::` or it loses the application key (`TEST-BOOTSTRAP.md:124-135`).

### 10.6 Testing a Filament package

A resource is only reachable through a panel, so the package composes the smallest one — `boilerplate-laravel/modules/identity-core-filament/tests/Fixtures/TestPanelProvider.php`:

```php
final class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->default()->id('admin')->path('admin')
            ->plugins([IdentityFilamentPlugin::make()]);
    }
}
```

The id matches the manifest's `presentation.filament` key. It lives in `tests/Fixtures/`, which a directory suite does not collect (only `*Test.php`). **Do not copy the host's panel provider** — the reference host's is tenant-scoped to `Team`, Shield-gated and themed from site settings, and reproducing it asserts on the host's composition rather than on the resource (`PRESENTATION.md:86`).

Two extra requirements, both in `modules/identity-core-filament/tests/TestCase.php`:

- `Filament::setCurrentPanel('admin')` in `setUp()` — no route has resolved one (line 34);
- a **widened provider walk**. `PackageTestCase` registers `extra.laravel.providers` of *direct* dependencies, which for `filament/filament` is one provider; support, schemas, forms, tables, actions, notifications, widgets, Livewire and the icon packages are all transitive. `discoveredProviders()` (lines 73-90) reads `vendor/composer/installed.json` and collects every `extra.laravel.providers` entry in the tree — which is what Laravel's own discovery does in an application. Sibling Liberu modules are unaffected precisely because their manifests declare that array empty.

`PRESENTATION.md:128` marks this as deliberately living in the one package that needs it: *"a helper with one caller is a guess about the second."*

Also from `PRESENTATION.md:51-62`: **any Filament resource whose model has no `team()` relationship must override `isScopedToTenant(): bool { return false; }`**, or a tenant-scoped panel 500s on it. A package suite will not catch this and should not try — document it in the package README.

### 10.7 Where a test belongs

Two questions in order (`TEST-BOOTSTRAP.md:169-179`): does it need anything from a host (→ composition test, host suite), and could the owning package check it about itself (→ `package-testbench`, not a host architecture suite). What is left for the host is **whole-graph** properties — that every package installs standalone, that enablement derives from manifests, that theme parents resolve, that Composer owns every autoload boundary. That is exactly the content of `boilerplate-laravel/tests/Architecture/ModuleBoundariesTest.php`, and the host's `phpunit.xml` `<source>` includes only `app`:

```xml
<!-- The host measures the host. Each package runs and measures itself, against its
     own phpunit.xml and its own coverage threshold — a package's numbers are not
     the composition's to report. -->
<source><include><directory>app</directory></include></source>
```

### 10.8 The six failures worth memorising

From `TEST-BOOTSTRAP.md:139-165`:

| Message | Meaning |
| --- | --- |
| `Target class [livewire.finder] does not exist` | provider needs Livewire and nothing registered it |
| `Target [SomeInterface] is not instantiable` | adapter boots against a contract nothing binds → add a `require-dev` on a binder |
| `Composer package is not installed` | manifest version ≠ `InstalledVersions`; you bumped and did not publish |
| `The test directory [tests] does not exist` | published tarball has no `tests/`; **will not reproduce locally** |
| `No application encryption key has been specified` | a `defineEnvironment()` override missing `parent::` |
| `Cannot bind an instance to a static closure` | a `static fn` passed to Pest's `skip()` |

---

## 11. CI

Three workflow files per package repository, not one file with three jobs (`documentation/modules/authoring/WALKTHROUGH.md:261`, `documentation/architecture/MODULES.md:591-599`). All present in each `boilerplate-laravel/modules/*/.github/workflows/`:

| Workflow | Trigger | Evidence |
| --- | --- | --- |
| `tests.yml` | every push / PR to `main` | Pest suites, architecture and security checks, static analysis, coverage |
| `install.yml` | tags `[0-9]+.[0-9]+.[0-9]+` only | clean resolve from nothing, bootstrap, manifest validation, minimal-host install |
| `compatibility.yml` | tags only | declared min/current PHP, Laravel, DB, Filament/Livewire matrix |

Each is a thin caller of a reusable workflow in `liberusoftware/.github` (e.g. `uses: liberusoftware/.github/.github/workflows/package-tests.yml@main`), with a concurrency group:

```yaml
concurrency:
  group: ${{ github.workflow }}-${{ github.ref }}
  cancel-in-progress: true
```

Why the triggers differ (`RELEASING.md:61-67`): resolving from nothing and `--prefer-lowest` are questions about *declared constraints*, which change on release, not on every code push. At fleet scale one publish sweep queued **176 jobs** as one workflow calling three reusables; split with only `tests` on push it queues **44**.

---

## 12. Reproducing this in `ecommerce-laravel` — the checklist

Derived entirely from the above; each item names its source.

**Host repository:**

1. Add a `vcs` `repositories` entry per Liberu package repo (`boilerplate-laravel/composer.json`), until they are on Packagist.
2. `require` `liberusoftware/composer-installer: ^1.1` plus every module/theme package explicitly (`MODULES.md:195`).
3. `config.allow-plugins."liberusoftware/composer-installer": true` — root only, never in a module (`MODULES.md:197`).
4. `minimum-stability: dev`, `prefer-stable: true`.
5. `.gitignore`: `/vendor/`, then un-ignore `/modules/`, `/modules/**`, `/themes/`, `/themes/**`, then re-ignore `/modules/*/vendor/`, `/themes/*/vendor/`, `/modules/*/composer.lock`, `/themes/*/composer.lock` (`boilerplate-laravel/.gitignore:1-10`).
6. `bootstrap/providers.php`: `ModuleManagerServiceProvider::class` first, then panel providers.
7. `config/modules.php`: `paths`, `enabled`/`disabled` from `MODULES_ENABLED`/`MODULES_DISABLED` only, `cache`, `cache_key` (copy `boilerplate-laravel/config/modules.php`).
8. `app/Filament/ModulePlugins.php` verbatim, and `->plugins(app(ModulePlugins::class)->forPanel('<panel-id>'))` in each panel provider.
9. Host `tests/Architecture/` for the whole-graph rules; host `phpunit.xml` `<source>` = `app` only.
10. CI: clean locked install then `git status --porcelain` must be empty (`MODULES.md:209`, `RELEASING.md:55`).

**Per module package:**

11. Repo named `module-{name}`; Composer package `liberusoftware/{name}` (`WALKTHROUGH.md:40`).
12. `composer.json`: `type: liberu-module`, `extra.liberu.name`, explicit `version`, PSR-4 `autoload` + `autoload-dev`, `require-dev` on `package-testbench` + `pest ^5.0`, `config.allow-plugins.pestphp/pest-plugin: true`, **no `extra.laravel.providers`**, **no `orchestra/testbench`**.
13. `module.json` with all ten required keys, `requires.packages` exactly matching the vendor-filtered `require`, version identical to `composer.json`, `default_enabled` per §5.2.
14. One service provider named by the manifest; `mergeConfigFrom` + bindings in `register()`, `loadMigrationsFrom`/`loadViewsFrom`/`loadRoutesFrom`/`Livewire::component`/`publishes` in `boot()`.
15. `tests/.gitkeep`, `tests/Pest.php` extending `PackageTestCase`, `phpunit.xml` pointing its Boundary suite at `vendor/liberusoftware/package-testbench/tests/Boundary/Module`.
16. `.gitignore`: `/vendor/`, `/composer.lock`, `.phpunit.cache`, `.phpunit.result.cache`.
17. `README.md`, `LICENSE.md`, `CHANGELOG.md`, and the three workflow files — all four are boundary-asserted or spec-required.
18. Presentation in a separate `-filament` / `-livewire` / `-api` repository, depending on the domain package and never the reverse.

**Known gaps to decide before copying:**

- **Panel ids** are hardcoded to `['admin', 'app']` in `ModuleValidator.php:58` and in the host architecture test. Ecommerce likely wants a storefront/customer panel — that needs a `module-manager` change, not just a manifest.
- **Translations** are an unexercised path (§7.4) — no module in the reference fleet ships any.
- **`liberu-module` covers presentation packages too.** `search-api` and `identity-core-filament` are `type: liberu-module` with `category: presentation`; the Composer type does not distinguish them, only the manifest category does.
- **Version triple-coupling.** `composer.json` `version`, `module.json` `version` and the Git tag must all agree, and a bump makes a host that also tracks the package unbootable until it is published and `composer update`d (`RELEASING.md:9-23`). In a repo that both installs and edits packages, that is a real operational cost.
