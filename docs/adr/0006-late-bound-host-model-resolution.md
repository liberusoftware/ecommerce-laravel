# Modules resolve host models late, and never import them

**Status**: accepted

A commerce module needs the application's user and team models but must not depend on the application. So a module resolves them **at call time through configuration** — `config('auth.providers.users.model')` for the user, and a team resolver contributed to `organizations-teams` for the team — and never has `App\User` or `App\Models\Team` in a `use` statement.

Foreign keys stay inside a package's own tables. The single cross-boundary exception is the same late-bound form: `foreignIdFor(Teams::teamModel())`.

## Why this is an ADR

This is an **extension** of the documentation rather than a reading of it. `MODULES.md` forbids a module depending on `App\`, but does not say how a module is then supposed to reference the host's user — so a reader looking for the rule upstream will not find it, and a reader looking at the code will wonder why an obvious import is missing.

## Consequences

The team resolver does not exist yet; it is an upstream contribution to `organizations-teams`, and commerce modules cannot resolve a team until it lands. Not yet filed — it is a contribution rather than a defect report, and it lands with wave 1.

Static analysis cannot see through a config lookup, so a wrong model class is a runtime failure rather than a build failure. The host composition test is what catches it.
