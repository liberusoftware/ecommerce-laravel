# Research

Evidence behind [`CONFORMANCE.md`](../CONFORMANCE.md). Each document is a **point-in-time investigation**, measured against `2d1024c` and merged here from the throwaway `research/*` branch it was written on.

These are not maintained. They record what was true when the conformance snapshot was taken, which is exactly what makes them useful later: when a finding is disputed, this is the evidence it rested on.

| Document | Question it answered |
| --- | --- |
| [`standards-gap-audit.md`](./standards-gap-audit.md) | Where does this repository violate the 36 standards documents? One section per standard — requirement, measured evidence, gap, severity, mechanical-vs-structural |
| [`app-to-module-inventory.md`](./app-to-module-inventory.md) | Which `ECOMMERCE.md` module does each part of `app/` belong to? All 906 files bucketed as mapped / split / unmappable |
| [`foundation-module-matrix.md`](./foundation-module-matrix.md) | Which of the 28 foundation modules does this repository need, and what does each replace? One row per module with per-cell citations |
| [`packaging-mechanism.md`](./packaging-mechanism.md) | How do `modules/` + `composer-installer` + `module-manager` actually work in `boilerplate-laravel`? |

One caveat carries across all four: `app-to-module-inventory.md` reports **98 unmappable files**, which was an artifact of its brief — it was given two catalogues where the documentation carries thirteen. [`CONFORMANCE.md` §4.2](../CONFORMANCE.md#42-the-98-unmappable-files-were-an-artifact) has the corrected reading.
