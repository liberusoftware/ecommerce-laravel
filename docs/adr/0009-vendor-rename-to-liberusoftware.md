# Vendor rename: `liberu-eccommerce` → `liberusoftware`

**Status**: accepted — **landed 2026-08-09**

`composer.json` declares `"name": "liberu-eccommerce/ecommerce-laravel"` — a vendor nobody else uses, containing a typo (`eccommerce`). Everything commerce publishes goes under **`liberusoftware`**, matching the other 40 packages, and the typo dies with the rename.

## Why the rename is free

Packagist shows `liberu-eccommerce/ecommerce-laravel` with **0 downloads and 0 dependents**. Nothing consumes it, so nothing breaks.

**Correction to this ADR as first written:** it also claimed *no tags*. That was wrong — the package has **five published versions**: `v1.0.0`, `v1.0.1`, `v1.0.2`, `v1.0.3` and `v13.0.0`. The decision does not change, because tags are not what makes a rename expensive; dependents are, and there are none. But the two are not the same fact and the difference has a consequence, below.

A rename that costs nothing today costs a redirect and a deprecation notice once anything depends on it.

## Consequences

The host's own package name changes, which is invisible to an application nobody `require`s. Every commerce module is unaffected — none has been published yet.

**One step is outside this repository, tracked as [#1000](https://github.com/liberusoftware/ecommerce-laravel/issues/1000).** Those five published versions mean the old Packagist package still exists and still points at this repository, so the next push updates a package whose `composer.json` no longer declares that name. Somebody with maintainer rights has to either rename the package on Packagist — which keeps the old name as an alias, the tidy outcome — or mark it abandoned in favour of `liberusoftware/ecommerce-laravel` and submit the new one. Until that happens, `liberusoftware/ecommerce-laravel` is a name this repository declares and Packagist does not know.

The GitHub repository moved to the `liberusoftware` organisation some time ago and the old URL survives on a redirect, which is why none of this surfaced as an error.
