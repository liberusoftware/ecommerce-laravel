# Architecture Decision Records

One rule decides what lands here: **a deviation from the Liberu documentation, or a deliberate loss of behaviour.** Not "significant decision" — every decision this repository's conformance effort made is significant, and an ADR per decision would turn this directory into meeting minutes.

Gaps become findings, never silent exemptions. A deviation without an ADR is a bug.

| # | Decision | Kind |
| --- | --- | --- |
| [0001](./0001-coverage-floor-for-extracted-modules.md) | 80% coverage floor for extracted modules | deviation — `MODULES.md` §24 |
| [0002](./0002-dropping-the-content-security-policy.md) | Foundation adoption drops the CSP | behaviour loss |
| [0003](./0003-dropping-the-password-policy.md) | Foundation adoption drops the breach-check password policy | behaviour loss |
| [0004](./0004-no-module-prefix-in-package-names.md) | Package names and namespaces drop the `module-` prefix | deviation — `MODULES.md` §9 |
| [0005](./0005-bare-version-tags.md) | Release tags are bare, not `v`-prefixed | deviation — `CI.md` §Release policy |
| [0006](./0006-late-bound-host-model-resolution.md) | Modules resolve host models late and never import them | extension |
| [0007](./0007-events-and-identifiers-across-product-boundaries.md) | Events and stable identifiers only across product boundaries | extension |
| [0008](./0008-reviews-and-ratings-merge.md) | Reviews/ratings merge keeps moderation, backfills customers | behaviour loss avoided |
| [0009](./0009-vendor-rename-to-liberusoftware.md) | Vendor rename `liberu-eccommerce` → `liberusoftware` | deviation — naming |
| [0010](./0010-modules-and-themes-are-gitignored.md) | ~~`/modules` and `/themes` are gitignored~~ | **withdrawn** — [#972](https://github.com/liberusoftware/ecommerce-laravel/issues/972); the repo conforms to `THEMES.md` §3.2 |
| [0011](./0011-adopting-module-manager.md) | Adopting `module-manager` drops runtime module enablement | behaviour loss |
| [0012](./0012-deleting-the-empty-cms-scaffolds.md) | The Article/FAQ and `seo_settings` scaffolds are deleted, not moved | behaviour loss |
| [0013](./0013-cms-and-crm-packages-are-built-from-ground.md) | CMS and CRM packages are built from ground; local code is deleted at cutover | supersedes a deferral |

Every deviation above also has an upstream issue filed against the document it deviates from — all 23 are filed. One of the 23, [`documentation` #20](https://github.com/liberusoftware/documentation/issues/20), is withdrawn along with ADR 0010: this repository no longer wants §3.2 changed.

They were tracked as [#961](https://github.com/liberusoftware/ecommerce-laravel/issues/961), which is **closed** — see [ADR 0013](./0013-cms-and-crm-packages-are-built-from-ground.md). The issues are still open upstream; what closed is a second copy of the list. Each ADR above links its own, and [`../CONFORMANCE.md` chapter 8](../CONFORMANCE.md#8-upstream-gaps) holds the full table with the reasoning behind each. A reader hitting one of these ADRs needs to know the disagreement is filed rather than forgotten, and the ADR itself is where that now lives. They are listed in [`../CONFORMANCE.md`](../CONFORMANCE.md)'s upstream chapter — a reader hitting one of these ADRs needs to know the disagreement is filed rather than forgotten.

## Adding one

Sequential numbering, `NNNN-slug.md`, next number after the highest here. Keep it short: what the context was, what was decided, why. Options and consequences only where a future reader would otherwise re-litigate the decision or be surprised by a downstream effect.
