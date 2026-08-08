# `/modules` and `/themes` are gitignored; a promoted package leaves the host tree

**Status**: accepted

`THEMES.md` §3.2 states *"the current decision, matching `/modules`, is **not** to add `/themes` to `.gitignore`"*, restated in §20's definition of done, and changeable *"only through an ADR and migration plan"* — the standard names the instrument, and this is it.

Commerce goes the other way for **both** directories. During the path phase a module's code is committed to the host, because that is where it lives. **At promotion the `.gitignore` flips per package**, its files disappear from the host tree, and Composer becomes the only source.

## Why both, in one ADR

§3.2 cites `/modules` as its own precedent, so deciding the two separately would break the symmetry the clause rests on.

## Considered options

`boilerplate-laravel`'s shape — keep the installed tree committed, guard it with `git diff --exit-code --stat -- modules themes` in `release.yml` — was rejected. It maintains a second copy of code whose authoritative source is elsewhere, and the guard exists solely to police a duplication that need not exist. 726 files are tracked under its `modules/` today while the same code is also installed from VCS.

## Consequences

**Promotion becomes observable**: whether a module is promoted is answerable by `ls`, not by reading a lockfile.

A fresh clone no longer contains module or theme source, so anyone reading the code must `composer install` first — the ordinary Laravel expectation, but a change from how this repository and boilerplate behave today.

[An upstream issue against §3.2](https://github.com/liberusoftware/documentation/issues/20) records the divergence, paired with [one asking `boilerplate-laravel`](https://github.com/liberusoftware/boilerplate-laravel/issues/653) to confirm its committed `modules/` tree is monorepo residue rather than intended vendoring.
