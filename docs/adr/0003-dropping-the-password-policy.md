# Adopting the foundation drops this repo's password policy

**Status**: accepted

`app/Providers/AppServiceProvider.php:32` installs a `Password::defaults()` rule whose production branch calls `uncompromised()` — a HaveIBeenPwned k-anonymity lookup that rejects passwords found in known breaches. No boilerplate foundation module provides an equivalent, so adopting `identity-core` **drops the breach check**.

## Consequences

Users could register passwords known to be compromised. This is a real reduction in account security, not a formality, and it is why the loss is recorded here rather than absorbed silently.

The rule stays in the host as a deliberate local override until [an upstream issue against `identity-core`](https://github.com/liberusoftware/module-identity-core/issues/2) restores it. Same shape as [ADR 0002](./0002-dropping-the-content-security-policy.md).
