# Package names and namespaces drop the `module-` prefix

**Status**: accepted (scope narrowed after the naming audit)

`MODULES.md` §9 opens *"Composer names communicate domain, capability, and role"* and gives five presentation-adapter rows in the form `liberusoftware/module-{name}-filament`. **No published package follows them** — all 40 boilerplate packages drop the prefix, so `module-blog-filament` the repository publishes `liberusoftware/blog-filament` the package. Commerce follows the fleet.

The prefix survives as a **repository-name** convention only.

## Scope

This deviates from §9's five presentation-adapter rows and nothing else. Domain packages are conformant:

| §9 row | Convention | This fleet |
| --- | --- | --- |
| Product capability | `liberusoftware/{product}-{capability}` | `liberusoftware/ecommerce-cart` — conformant |
| Shared core | `liberusoftware/{capability}-core` | `liberusoftware/ecommerce-core` — conformant |
| Filament / Livewire / API / React Native / Flutter adapters | `liberusoftware/module-…` | `liberusoftware/ecommerce-cart-filament` — **deviates** |

The namespace deviation belongs here too rather than to an ADR of its own, being the same rows of the same table: namespaces derive mechanically from the package name, so `ecommerce-cart-filament` becomes `Liberu\Ecommerce\Cart\Filament\` — three segments against §9's two-segment `Liberu\{Domain}\{Capability}`.

## Considered options

Following §9 literally was rejected because the cost lands in every consumer's `require` block, to match a table 40 published packages already contradict.

Renaming the 40 published packages to match §9 was rejected because it breaks every existing consumer to satisfy a document an upstream issue is already filed against.

Adopting the `composer require liberusoftware/module-ecommerce-tax` line printed in the 414 provisioned stub READMEs was rejected because those repositories contain one generated README each — no `composer.json`, no tag, nothing on Packagist. The line is the repository slug pasted by a template, provably so: `module-crm-crm-core`'s README is titled `# Crm: Crm Core Core Module`.

## Consequences

A module carries four names — repository, Composer package, `extra.liberu.name`, namespace — in two forms, with only the repository keeping `module-`. `docs/MODULE_DEVELOPMENT.md` documents the mapping so it is derivable rather than looked up.
