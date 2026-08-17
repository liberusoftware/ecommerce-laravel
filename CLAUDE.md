# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

See [AGENTS.md](./AGENTS.md) — it is the source of truth for agent configuration in this repo.

## House rules

- **The application is `src/`.** The repository root holds only the Docker compose files and
  `.docker/`. Every path in this document is relative to `src/`.
- **Do not write long comments.** Much of the existing tree carries essay-length docblocks
  explaining the defect a line prevents. Do not extend that style and do not add more of it: a
  short comment where the code cannot say it itself, and nothing else. The reasoning belongs in
  the commit message, the ADR or the issue.
- **No annotations in commits or pull requests.** No `Closes owner/repo#123`, no
  `Co-Authored-By`, no "generated with" footer, no trailer of any kind. Subject and body only.
- **Claiming an issue means claiming it on the board.** Any issue picked up that is on
  [project 10](https://github.com/orgs/liberusoftware/projects/10) moves to **In Progress** and is
  assigned to the maintainer *before* work starts, not after:

  ```bash
  ITEM=$(gh project item-list 10 --owner liberusoftware --format json --limit 200 \
    --jq '.items[] | select(.content.number == <issue>) | .id')
  gh issue edit <issue> --repo liberusoftware/ecommerce-laravel --add-assignee @me
  gh project item-edit --project-id PVT_kwDOCXeRJc4BfHfc --id "$ITEM" \
    --field-id PVTSSF_lADOCXeRJc4BfHfczhZcsso --single-select-option-id 47fc9ee4
  ```

  Ids, the `read:project` scope requirement and the `Done` option id are in
  [`docs/agents/issue-tracker.md`](./docs/agents/issue-tracker.md#project-board-10).

## Commands

```bash
composer lint          # pint --test
composer lint:fix      # pint
composer analyse       # phpstan, whole tree
composer test          # php artisan test
composer check         # all three, in that order

php artisan test --filter=CheckoutServiceTest          # one test class
php artisan test tests/Feature/CheckoutServiceTest.php # one file
php artisan test --testsuite=Unit                     # one suite

npm run dev            # vite
npm run build
```

Tests are PHPUnit classes extending `Tests\TestCase`, on sqlite `:memory:`; `phpunit.xml` holds the
env the suite needs (including the social-login provider list, which production leaves empty).

**Neither PHP nor Composer exists on this machine, so none of the above runs from a bare shell.
They run in Docker.** `docker run --rm composer:2` and `php:8.5-cli` resolve Packagist and run
install, Pint, PHPStan, Pest and `pecl install pcov` for coverage — verified 2026-08-17, and used
for every gate of wave 17 before anything was pushed.

The reason this was believed impossible is worth keeping, because it is the same shape as the
Composer note in `docs/MIGRATION_PLAN.md` wave 0. PHP's libcurl is built against c-ares, which
ignores the `options use-vc` that makes `curl`, `git` and `gh` work here. That is a property of the
**host PHP binary**, not of the machine or its network, and a container brings its own. The
inference from *the host PHP cannot resolve* to *nothing here can run a suite* was never checked,
and it stood for sixteen waves because the fallback worked: pushing to a runner is a slower loop,
not a broken one, so nothing ever failed in a way that made anybody re-read the premise.

**GitHub Actions remains the authority.** A local pass is not a result until CI agrees — push a
branch and open a PR, which is what fires `tests.yml` (and `lint.yml`) here and in every module
repo. Never report a gate you have seen pass in only one of the two places.

Two static gates, scoped differently on purpose: **Pint is a ratchet** — CI checks only the PHP
files a PR touches, so editing a long-unformatted file means adopting its formatting in the same
commit. **PHPStan is whole-tree** at level 0 with no baseline; `phpstan.neon` records the level
ladder and why level 8 is not reachable without Larastan.

## Architecture

**A request finds its merchant by hostname.** `ResolveChannel` middleware resolves the host through
`ChannelResolver` to a `Channel`; an unresolved host is a 404 with no default-merchant fallback. A
`Channel` belongs to a `Store`, a `Store` to a `Team` — the tenant boundary, inherited from
Jetstream. Commerce tables scope on `store_id`; everything else on `team_id`.
[`CONTEXT.md`](./CONTEXT.md) defines those five words and is worth reading before using any of them.

**Tenancy is enforced by global scopes, never at the call site.** `App\Traits\IsStoreScoped` and
`App\Traits\IsTenantModel` carry it. The ways out are deliberate and few:
`withoutGlobalScope('store')`, or `StoreContext::acrossAllStores()` for work that is about a person
rather than a shopfront — a GDPR export or erasure narrowed to one store is a wrong answer, not a
partial one. Filament panels are outside this stack: resources are Team-scoped by Filament tenancy,
and the store scope covers what that does not reach (relation managers, widgets, bare
`Model::query()`).

**Two Filament panels**, `app/Providers/Filament/AdminPanelProvider.php` (platform) and
`AppPanelProvider.php` (merchant, Team tenancy).

**Checkout has two entry points and one money core.** `CheckoutController` (session cart) and
`StorefrontSchema` → `HeadlessCheckoutService` (GraphQL `CartItem` cart) both call
`App\Services\CheckoutService` for the parts that are dangerous to get wrong: coupon revalidation
against the live subtotal and again under a row lock, guarded atomic stock reserve and release,
download grants, dropship queueing, capture. Money mechanics go there, not into a caller — the two
paths drifting apart is the failure that class exists to prevent.

**Payments** resolve through `PaymentGatewayFactory` to `App\Services\PaymentGateways\*`
implementing `App\Interfaces\PaymentGatewayInterface`. Stripe and PayPal each have a webhook
controller.

**Order state is a transition, not an assignment.** `Order::transitionTo()` writes the history row;
the `Order::STATUS_*` constants are the vocabulary. Never assign `status` directly.

**GraphQL is code-first** (webonyx), one schema in `app/GraphQL/StorefrontSchema.php`, wrapped by
`ExecutionDeadline`. Cart mutations mirror the REST cart controller's stock guard and `user_id`
scoping.

**Modules live in their own repositories** and are registered by `liberusoftware/module-manager`.
`modules/` in this host holds the manager only.

## Documentation map

| File | What it is |
| --- | --- |
| [`CONTEXT.md`](./CONTEXT.md) | The vocabulary this repo uses that the fleet architecture docs do not define |
| [`docs/CONFORMANCE.md`](./docs/CONFORMANCE.md) | A dated snapshot of the gap between this repo and the reference design. Not edited |
| [`docs/MIGRATION_PLAN.md`](./docs/MIGRATION_PLAN.md) | Living. Sequences the structural work and records each wave as it lands |
| [`docs/MODULE_DEVELOPMENT.md`](./docs/MODULE_DEVELOPMENT.md) | Package, repository and namespace naming; the dependency rules |
| [`docs/adr/`](./docs/adr/) | One decision per file |
| [`docs/agents/`](./docs/agents/) | Issue tracker, triage labels, domain docs |

## The wave workflow

One architecture epic at a time, built as four packages, recorded, closed. Run it in this order.

1. **Pick the epic.** The `Architecture: Ecommerce — <module>` epics are sequenced by
   [`docs/MIGRATION_PLAN.md` §1](./docs/MIGRATION_PLAN.md) — tier order, then most-code-first
   within a tier. Read that rule before choosing; `gh issue list` returns GitHub's order, not
   the plan's.
2. **Claim it.** Assign the issue and move it to **In Progress** on project board 10 — see
   [`docs/agents/issue-tracker.md`](./docs/agents/issue-tracker.md#project-board-10). Do this
   before any building, so the board reflects work in flight rather than work finished.
3. **Survey the host from primary sources.** Read the migrations, models and services the
   module replaces. Name each fault from the code, not from the issue text — the issue
   describes the capability, not what is wrong here.
4. **Write the wave addendum**, at
   `~/.claude/projects/-home-tom-code-ecommerce-laravel/briefs/wave<N>-addendum.md`. It carries
   the boundary statement, the host's faults, the one fact that shapes the module, and the
   decisions agents must implement rather than rediscover. Briefs live outside `/tmp`, which is
   not durable here.
5. **Dispatch the domain agent**, pointing it at `module-build-brief.md`, the wave addendum and
   `presentation-brief.md`, with the instruction to STOP if any is missing.
6. **Verify green independently** against the GitHub API before believing the agent — see
   "Verifying a package" below.
7. **Dispatch the three presentation agents** (`-api`, `-filament`, `-livewire`) in parallel once
   the domain package is tagged. Verify each as it returns, then bulk-verify all four.
8. **Record the wave** in `docs/MIGRATION_PLAN.md` on a `docs/wave-<N>-shipped` branch, open a
   PR, merge it, pull `main`.
9. **Close the epic**: comment the shipped summary, `gh issue close --reason completed`, and move
   the board item to **Done**.

### Verifying a package

Never report or close on an agent's own account of its results. Per package:

```bash
R=module-ecommerce-<module>
gh run list --repo liberusoftware/$R --limit 12 --json workflowName,headBranch,conclusion \
  --jq '[.[]|select(.conclusion!=null)]|group_by(.workflowName)|map(.[0]|"\(.workflowName)@\(.headBranch)=\(.conclusion)")|join(" ")'
gh api repos/liberusoftware/$R/tags --jq '[.[].name]|join(" ")'
gh api repos/liberusoftware/$R/commits --jq '.[].commit.message' \
  | grep -iE 'claude\.ai|Claude-Session|session_0' && echo VIOLATION || echo "trailer clean"
```

Green means **Tests@main**, **Install@0.1.0** and **Compatibility@0.1.0** all `success`, and the
`0.1.0` tag exists.

## Standing constraints

- **No session identifiers, anywhere.** No `Claude-Session:` trailer, no `claude.ai` URL, no
  session id in a commit message, PR body, issue comment, tag message or file. Pass this to every
  dispatched agent as an absolute rule and grep for it after every package.
- **Modules live in their own GitHub repositories** under `liberusoftware`, never in a `modules/`
  directory in this host.
- **Dispatched agents never commit to this repository.** They clone their own module repo into
  `~/work/wave<N>/` — not `/tmp`, which is not durable here and which concurrent agents contend
  over.
- **Pre-production: migrations are edited in place.** No corrective migrations.
- **Composer cannot run from a bare shell, but it runs in Docker.** PHP's libcurl is built against
  c-ares, which ignores the `options use-vc` that makes `curl`, `git` and `gh` work over TCP — a
  property of the host PHP binary, not of the machine. `docker run --rm composer:2` and
  `php:8.5-cli` resolve Packagist and run the whole suite; use them, and treat CI as the authority
  on the result. There is still no `vendor/` in this repository, and adding a package to *this*
  repository's `composer.json` still goes through `.github/workflows/composer-require.yml`, because
  the lock file has to be updated by the same toolchain CI installs with.
- **Pint runs locally** via the release phar, with the fleet config — not the stock `laravel`
  preset. See `module-build-brief.md` §6.
