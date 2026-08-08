# Vendor rename: `liberu-eccommerce` → `liberusoftware`

**Status**: accepted

`composer.json` declares `"name": "liberu-eccommerce/ecommerce-laravel"` — a vendor nobody else uses, containing a typo (`eccommerce`). Everything commerce publishes goes under **`liberusoftware`**, matching the other 40 packages, and the typo dies with the rename.

## Why the rename is free

Packagist shows `liberu-eccommerce/ecommerce-laravel` with **0 downloads, 0 dependents and no tags**. There is nothing to break. A rename that costs nothing today costs a redirect and a deprecation notice once anything depends on it.

## Consequences

The host's own package name changes, which is invisible to an application nobody `require`s. Every commerce module is unaffected — none has been published yet.
