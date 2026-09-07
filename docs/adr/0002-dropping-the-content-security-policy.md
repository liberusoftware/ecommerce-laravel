# Adopting the foundation drops this repo's Content-Security-Policy

**Status**: accepted

`app/Http/Middleware/SecurityHeaders.php` sets a Content-Security-Policy that no boilerplate foundation module provides. Adopting the foundation's security middleware replaces it, so the CSP is **lost unless deliberately carried forward** — this ADR records that the loss is known, not accidental.

## Consequences

Between foundation adoption and the CSP's restoration upstream, the application ships without one. [An upstream issue against `module-application`](https://github.com/liberusoftware/module-application/issues/1) tracks adding it; until that lands, the middleware is kept in the host as a deliberate local override rather than deleted.

This is one of two behaviours the foundation silently drops — see [ADR 0003](./0003-dropping-the-password-policy.md).
