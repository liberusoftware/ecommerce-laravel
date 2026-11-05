# AGENTS.md

Guidance for coding agents working in `liberusoftware/ecommerce-laravel`.

## Agent skills

### Issue tracker

Issues live in GitHub Issues on `liberusoftware/ecommerce-laravel`, driven by the `gh` CLI.
See `docs/agents/issue-tracker.md`.

### Triage labels

The five canonical roles, each label string equal to its name (`needs-triage`, `needs-info`,
`ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one root `CONTEXT.md` and one `docs/adr/`. See `docs/agents/domain.md`.

<!-- liberu-presentation:begin -->
## Ecommerce — Laravel and Filament architecture

Read the [product scope](https://github.com/liberusoftware/documentation/blob/main/projects/ecommerce/README.md), [presentation architecture](https://github.com/liberusoftware/documentation/blob/main/architecture/PRESENTATION.md), [module lifecycle](https://github.com/liberusoftware/documentation/blob/main/architecture/MODULES.md), and [Filament standard](https://github.com/liberusoftware/documentation/blob/main/standards/FILAMENT.md).

- This application composes panels, routes, tenancy and installed modules. Keep canonical domain behavior, validation, policies, persistence and durable events in the owning core package.
- Call reviewed public actions and queries from Filament resources, pages and widgets. Do not copy another module's records or treat hidden controls as authorization.
- Preserve existing identity, session and team integration. Installation, module enablement, entitlements and policy checks are separate responsibilities.
- Use the actual composer.json/package.json identities and locks. Follow the current README and scripts rather than inferring commands from repository slugs.
- Run existing tests and the declared asset build for relevant changes; verify allowed, denied and wrong-tenant journeys when modifying protected behavior.
- Other presentations live in separate project repositories linked from README.md. Keep their independent npm/pub dependencies outside this PHP host unless an explicit adapter requires assets.
<!-- liberu-presentation:end -->
