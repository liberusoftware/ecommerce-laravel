# Across product boundaries: events and stable identifiers only

**Status**: accepted

Inside Ecommerce, a module may take a direct Composer dependency on another — the modules are strictly tiered and an architecture test enforces acyclicity. **Across products it may not.** No commerce module ever requires `billing-*`, `crm-*`, `cms-*` or `accounting-*`; it emits an event, or holds a stable identifier it does not resolve.

## Why this is an ADR

Like [ADR 0006](./0006-late-bound-host-model-resolution.md), this is an **extension** — `MODULES.md` describes product ecosystems without saying what may cross between them. A reader will find a direct dependency inside Ecommerce and reasonably assume the same is allowed to Billing.

## Considered options

Contracts packages at every product boundary were rejected as premature: a shared contract only earns its keep where a provider is genuinely swappable, and a contract with one implementation is an interface with one implementation.

## Consequences

Cross-product reads become eventually consistent by construction. A commerce module that needs a fact from Billing either holds the identifier and asks the host, or reacts to an event — it cannot query.

The rule is only as strong as its test. The architecture test that enforces acyclicity inside Ecommerce is the same one that must reject a cross-product `require`, and it lives upstream in `package-testbench`.
