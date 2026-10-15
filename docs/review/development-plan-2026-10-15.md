# Development plan — 2026-10-15

Built from the findings register in [application-review-2026-10-15.md](application-review-2026-10-15.md).

Each phase is one small commit (or a few), with a regression test that fails before the change. Phases
are ordered by risk and dependency: data integrity and tenancy first, then broken workflows, then
cleanup. **Decisions needed before a phase can start are marked ⚑.**

Working rules for every phase:
- Start with a failing test.
- Run the full suite, PHPStan, and Pint on changed files.
- Review the diff.
- Commit locally with no trailers.
- Don't push without approval.

---

## Phase 1 — Fresh install produces an owned, visible catalogue (F-01, F-17)

**Status: done.** Existing databases are re-seeded (decision: local data need not be preserved); no backfill command.

**Goal:** after migrate and seed, the seeded admin sees and can edit the demo catalogue in both panels.

**Scope:**
- `DefaultTeamSeeder`: create the default team, and assign the unowned default store to it.
- `UserSeeder`: set the admin's `current_team_id`.
- Remove the dead `Branch` import and fix the hard-coded `user_id => 1` (F-17).
- Delete the unused `TeamSeeder`.
- No change to `StoreContext`: once the store has an owner, `teamForWrites()` produces `team_id = 1`
  for everything seeded afterwards.

⚑ **Decision:** should existing databases get a one-off backfill (an artisan command that copies
`stores.team_id` onto rows with a `store_id` and no `team_id`), or a documented re-seed? Re-seeding
deletes all data.

**Acceptance criteria:**
- After `migrate` and `DatabaseSeeder`:
  - `stores.team_id = 1`;
  - no row in a store-scoped table has `store_id = 1` and `team_id IS NULL`;
  - `/admin/1/products` and `/app/1/products` list the 21 products;
  - categories, menus and collections are listed in their resources.
- The storefront output is unchanged.

**Tests:**
- New `FreshInstallTest`: seed, log in as admin, request each panel's product and category lists over
  HTTP, one panel per test (see F-13).
- Extend `DummyDataSeederTest` with team attribution.

## Phase 2 — Panels keep `current_team_id` in step with the Filament tenant (F-03)

**Status: done.** The listener alone; `StoreContext` was not changed to read the Filament tenant, since the listener runs before any page query on every panel request.

**Depends on:** Phase 1.

**Goal:** the store scope always uses the team the user is actually working in.

**Scope:**
- Implement `App\Listeners\SwitchTeam::handle()`: switch the user's team when they belong to the
  tenant.
- Register the same listener for the `/admin` panel. Today only `AppPanelProvider::boot()` registers it.
- Correct the `StoreContext::panelTeamId()` docblock.

⚑ **Decision:** should `StoreContext` also prefer `Filament::getTenant()` while a panel is being served
(defence in depth), or rely on the listener alone?

**Acceptance criteria:**
- Visiting `/app/{teamB}` sets `current_team_id = B`.
- Store-scoped widgets and dropdowns then show only B's stores.
- A user can't switch to a team they don't belong to.

**Tests:**
- Panel tenant switch sets `current_team_id`.
- A user in two teams sees team B's data, not A's, after switching. This is the suspected mismatch
  case; the test confirms it or rules it out.

## Phase 3 — Orders change payment state only through the state machine (F-04)

**Status: done.** Manual creation kept (decision), with required line items and computed totals. Cancel is offered only for pending orders; cancelling a paid order needs the refund workflow.

**Goal:** a back-office user can't make an order "paid", or change its total, without an audited
transition.

**Scope:**
- `/app` `OrderResource`: make `payment_status`, `total_amount` and `status` read-only in the form.
- Add header actions on `EditOrder` for the transitions `Order::TRANSITIONS` allows from the current
  status. Each action calls `transitionTo()` with the acting user's id.

⚑ **Decision:** should manual order creation stay in `/app`? If yes, require line items, compute the
total from them, and start the order at `pending`.

**Acceptance criteria:**
- The form can't change those fields; a crafted Livewire payload can't either.
- A "Mark paid" action writes `status=paid`, `payment_status=paid`, a history row and an invoice.
- Illegal transitions aren't offered.

**Tests:** Livewire `EditOrder` tests for the read-only fields, each action, and an illegal transition.

## Phase 4 — Financial records can't be erased by a delete (F-05)

**Status: done.** Retention as decided: paid orders, invoices and refunds can't be erased by back-office actions; GDPR erasure is unchanged. Account deletion still cascades return requests (`return_requests.customer_id → users`), which is outside this decision and recorded as remaining.

⚑ **Decision required first:** the retention policy. Should customers and orders be deletable at all?
GDPR erasure already exists (`GdprErasureService`) and is the correct path for personal data.

**Goal:** no back-office action, and no cascade, permanently removes paid orders, invoices or refunds.

**Scope (proposed, smallest option):**
- Policies: refuse `delete` for a customer that has orders, for an order at or beyond `paid`, and for
  any invoice.
- Remove the delete actions from `InvoiceResource`.
- Change `orders.customer_id`, `invoices.customer_id` and `invoices.order_id` to `restrictOnDelete`.
  This is pre-production, so edit the migrations in place.

**Acceptance criteria:** the delete actions are hidden or refused for those records, and deleting a
customer at database level with orders fails instead of cascading.

**Tests:** policy tests; a Filament bulk delete over a mix of deletable and protected records; a
database-level restrict test.

## Phase 5 — Logged-in users never hit `/home` (F-06)

**Status: done.**

**Goal:** guest-only pages redirect a logged-in user somewhere that exists.

**Scope:** set `RouteServiceProvider::HOME` to `/`, or reuse `LoginResponse`'s role targets in
`RedirectIfAuthenticated`.

**Acceptance criteria:** `/login`, `/register` and `/forgot-password` while logged in end at a 200 page.

**Tests:** one HTTP test per route.

## Phase 6 — Configuration and repository hygiene (F-02, F-07, F-16)

**Status: done.** Found F-21 (no panel manages real stores); it needs a product decision and is listed under "Not planned".

**Goal:** what the repo and `.env` say matches what the code does.

**Scope:**
- Remove `MULTITENANCY` from `.env.example`, and document that tenancy is always on.
- Delete the `modules/ecommerce-*` duplicates.
- Correct `CLAUDE.md` (the `src/` layout, PHP availability, the `/home/tom` paths) and `CONTEXT.md`
  (the Store warning).
- Correct the "every Jetstream personal team" sentence in `MIGRATION_PLAN.md` §352.

⚑ **Decision:** confirm that `modules/ecommerce-*` was not meant to be kept. CLAUDE.md says modules
live in their own repositories.

**Acceptance criteria:** nothing references the removed files, and the full suite and
`EveryClassLoadsTest` pass.

**Tests:** the existing suite. No new behaviour.

## Phase 7 — Small correctness fixes (F-08, F-09, F-10, F-11, F-18)

**Status: done.** Also fixed, as found during the work: F-20 (customer delete with reviews) and F-22 (invoices, reviews and ratings created outside a panel had no team). F-08 needed no change (decision: hide and defer; nothing was exposed).

These are independent of each other, so there's one commit each.

| Item | Change | Test |
|---|---|---|
| F-09 | Home page: `withAvg('ratings','rating')` for both product lists | Query-count assertion on `/` |
| F-10 | Invoice customer column uses the first and last name | Table renders the name |
| F-11 | Stripe `processSubscription` fails closed (as PayPal's does) | Gateway unit test |
| F-18 | Inventory log written from a locked read, or only `quantity_change` | Log matches the stock sequence |
| F-20 | Customer delete policy also refuses a customer with reviews or ratings | Policy test |
| F-08 ⚑ | Decide whether backorders and preorders exist; implement them in `reserveStock()` or hide the fields | Reserve beyond stock when `allow_backorders` is set |

## Phase 8 — Dependency advisories (F-12)

**Status: done (option B), `f9f23d6`.** Resolved to Filament 5.9.0, above every fixed-in version; the Filament family moves together because the settings plugin pins it. Before the decision it was **blocked**: The fixed versions fit the current constraints, so only `composer.lock` changes, and `composer-require.yml` can't run without pushing. Options:
- **A. Push and dispatch:** push the branch, then dispatch `composer-require.yml` with `filament/filament:^5.10.2`. This raises the constraint and updates the lock with CI's toolchain. `league/commonmark` would also need requiring directly, adding a direct dependency it doesn't need today.
- **B. Local lock update (recommended):** run `composer update filament/filament league/commonmark` here, lock file only and no constraint changes, then let CI (`ComposerLockIsCurrentTest`, `install.yml`) confirm on the next push.
- **C. Wait:** take the next routine dependency update. Neither advisory affects a feature this app uses.

**Goal:** `composer audit` is clean.

**Scope:** `filament/filament` to 5.8.2 or later, and `league/commonmark` to the fixed release, through
`.github/workflows/composer-require.yml` as the repo requires.

**Acceptance criteria:** `composer audit` reports no advisories; the full suite and the panel smoke
tests pass.

## Phase 9 — Test-harness tenancy reliability (F-13)

**Status: done.** Fixed in the base `TestCase` rather than with a one-panel-per-test rule. The full suite passed unchanged, so no existing test was relying on a missing scope.

**Goal:** no test passes because a panel's tenancy scope was never registered.

**Scope:** a test helper that serves one panel per test, or boots the panel explicitly. Audit the
existing panel tests that request both panels.

**Acceptance criteria:** a deliberately unscoped resource fails the panel tenancy tests.

---

## Not planned (improvements, pending a decision)

- **F-14:** per-store outbound webhooks.
- **F-15:** Spatie team-scoped roles, or removing the dead `setPermissionsTeamId` calls.
- **F-19:** the whole-tree Pint reformat stays a ratchet.
- **F-21** ⚑: an admin resource for stores, channels and domains, so merchants can be onboarded without editing the database.

---

## Progress

| Phase | Findings | Commit(s) | State |
|---|---|---|---|
| Baseline | Review and plan | `357efcc` | — |
| 1 | F-01, F-17 | `66c5355` | Done |
| 2 | F-03 | `cc6ea1e` | Done |
| 3 | F-04 | `85f5a72` | Done |
| 4 | F-05 | `492c647` | Done |
| 5 | F-06 | `ff2c5c2` | Done |
| 6 | F-02, F-07, F-16 | `1e0194b` | Done |
| 7 | F-20, F-09, F-10, F-22, F-11, F-18 (F-08 closed without change) | `9c9fe4c`, `e18b121`, `93ba169`, `8a97e7b`, `fd08742`, `69c0f4c` | Done |
| 8 | F-12 | `f9f23d6` | Done |
| 9 | F-13 | `9aac441` | Done |

Open, needing a decision: F-21 (store management, being investigated), F-14, F-15. Open, small: F-23. Unchanged by design: F-19.
