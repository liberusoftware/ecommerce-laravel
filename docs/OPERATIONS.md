# Operations

Running an installed instance. Getting one installed is [`INSTALLATION.md`](./INSTALLATION.md).

---

## Console commands

Every command registered by the application, taken from `app/Console/Commands/`.

| Command | Does |
| --- | --- |
| `segments:calculate` | Recomputes customer segment membership. `--segment=*` limits it to specific segment ids |
| `metrics:update-customers` | Recomputes customer LTV, retention and related metrics. `--user=*` limits it to specific user ids |
| `recommendations:generate` | Regenerates product recommendations. This is the *generator* half of the recommendation system; the read path is served inline |
| `inventory:check-low-stock` | Scans inventory and raises low-stock notifications |
| `webhooks:retry-failed` | Re-dispatches webhook deliveries that failed |
| `shipping:prune-quotes` | Deletes expired shipping quotes. `--days=1` sets how far past expiry to prune |
| `vat:oss-report` | EU OSS VAT report. `--from=`, `--to=` (default: current quarter to today), `--csv` |
| `vat:ec-sales-list` | EC Sales List. Same three options |
| `tenants:distribution` | Read-only tenant counts, per-table distribution and cross-boundary mismatches — the [#944](https://github.com/liberusoftware/ecommerce-laravel/issues/944) checklist. See below |
| `module {action} {name?}` | Module scaffolding. **Does nothing useful** — see below |

### The tenant-distribution report, and running it without `vendor/`

`tenants:distribution` answers the [#944](https://github.com/liberusoftware/ecommerce-laravel/issues/944) checklist, which gates wave 2's backfill: how many tenants exist, how rows are distributed across them per table, and how many rows are **already** attributed across a tenancy boundary. It writes nothing.

It has to be run against each real environment — production, staging, any long-lived demo — because a development database answers with seeded data, which says nothing about how production's rows are attributed.

The machine that can reach production is not always the one with a working `vendor/`, so the report also runs with no Composer install, no autoloader and no deploy:

```bash
php tools/tenant-distribution.php
```

That reads `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` from `.env`, and any of them can be overridden by a real environment variable of the same name — which is how you point it at a read replica or a restored copy rather than at production itself:

```bash
DB_HOST=replica.internal php tools/tenant-distribution.php
```

Both entry points call `App\Support\TenantDistributionReport`, which needs a `PDO` and nothing else. Two implementations would drift, and the one nobody ran would be the one somebody trusted.

Paste the output onto #944, one comment per environment, without interpreting it — the rules for what the numbers mean are already fixed.

### Scheduled

Only one command is scheduled (`app/Console/Kernel.php:16`):

```php
$schedule->command('shipping:prune-quotes')->daily();
```

The other eight run on demand. `segments:calculate`, `metrics:update-customers` and `recommendations:generate` are the ones most likely to want a schedule — nothing currently recomputes any of them.

### `module` is inert

`ModuleCommand` drives `app/Modules/`, which is 1,095 lines of scaffolding that registers zero modules: no class implements `ModuleInterface` and `config/modules.php:66` enables none. The command runs without erroring and achieves nothing.

The whole directory is deleted in wave 0 of the [migration plan](./MIGRATION_PLAN.md#wave-0--make-a-module-loadable-and-make-the-rules-enforceable), replaced by `liberusoftware/module-manager`.

---

## The admin panels

Two Filament panels are registered.

| Panel | Path | Tenancy |
| --- | --- | --- |
| Admin | `/admin` | `->tenant(Team::class, ownershipRelationship: 'team')` — the default panel |
| App | `/app` | Same, plus `strictAuthorization()` |

Resources cover catalogue and orders, customers and segments, discounts and coupons, gift registries, A/B tests, cart-recovery campaigns, menus and pages, chat, and the DropXL import.

### What panel access actually gates

`canAccessPanel` gates the App panel on `$this->allTeams()->isNotEmpty()` — **panel access is team membership**, not a role.

Beyond that, it gates very little. There are no `can*` overrides and no `Gate::` calls across any of the 105 Filament classes, and 17 of 99 model files have a policy. Several privileged operations are reachable by any panel user: inventory adjustment, supplier bulk import, and segment recompute among them.

**Treat panel access as full access** until the authorization work in [`CONFORMANCE.md` §3.1](./CONFORMANCE.md#31-critical) lands. That is a statement about the current build, not a design intent.

### Two resources may be broken

`DiscountResource` and `MenuResource` are registered in a tenant-scoped panel while `discounts` and `menus` carry no `team_id` column. Opening `/admin/discounts` either raises an unknown-column error or lists every tenant's rows; **which one has not been established**, because it cannot be determined without a running instance.

If you have one: open the page and record what happens on [#958](https://github.com/liberusoftware/ecommerce-laravel/issues/958). Both outcomes need fixing; only one is urgent.

---

## Queues

A worker is required for normal operation, not just for throughput. Dropshipping, webhook delivery and order mail all run through it.

```bash
php artisan queue:work --tries=3
```

One thing worth knowing about the current queue configuration:

**`SendWebhookDelivery` hand-rolls its own retry** (`:14`) and re-dispatches itself rather than throwing, so a failed delivery never reaches `failed_jobs` and `queue:failed` will not show it. That is deliberate — a failing receiver must not bubble an exception into the order transition that triggered it — and it does not make failures invisible, only differently placed: every attempt is written to `webhook_deliveries` with its status code and `success`, and `webhooks:retry-failed` re-queues any `(endpoint, order, event)` tuple that never succeeded inside a 24-hour window. **Watch that table, not `failed_jobs`.**

### `after_commit` — corrected

An earlier revision of this document claimed that `after_commit` being `false` on all four connections was a fault, because "two checkout paths dispatch webhooks from inside open transactions". **That is wrong**, and the correction matters because the claim would send someone hunting a race that does not exist.

`false` is Laravel's shipped default, not a local choice. And no dispatch happens inside a transaction: both checkouts close their `DB::transaction` — which covers only order creation and stock reservation — before charging, and every `transitionTo` call in the codebase, which is what fires the outbound webhook, runs after that closure has returned. `queueDropship` is likewise called from outside it.

The [`CONFORMANCE.md`](./CONFORMANCE.md) snapshot still carries the original claim, by design: it is dated and superseded rather than revised.

---

## Troubleshooting

### Orders stuck at `supplier_queued`

The queue worker is not running, or the DropXL call is failing.

1. Confirm a worker is up: `php artisan queue:work --tries=3`.
2. Check `storage/logs/laravel.log`.
3. `DispatchDropshippingOrder::failed()` is a real compensating action, so a genuinely failed dispatch should leave a trace. If nothing appears in `failed_jobs` either, suspect the untransacted ordering race at `CheckoutService.php:102-107` — it can make the job's own guard drop a supplier order silently.

### Stripe charges fail

1. Validate `STRIPE_SECRET` in `.env`.
2. Confirm the publishable key is present in `config/services.php`.
3. If the key looks correct but resolves empty at runtime, run `php artisan config:clear` — the key is read through `config('services.stripe.secret')`, so a stale cached config serves the old value.
4. Review the logs for API errors.

### A configuration value is empty in production but fine locally

Check for `env()` outside `config/`. Under `config:cache`, `env()` returns `null` everywhere except during config loading, so the symptom is an empty value in production and a correct one locally.

**There are currently no such sites** — `DropxlService` and `SubscriptionController` were the two, and both now read through `config()`. This entry stays because the failure is silent and the mistake is easy to reintroduce.

### A widget or resource does not appear

One known cause remains, and it is wiring rather than permissions.

`app/Filament/Resources/CustomerSegmentResource` is discovered by **neither** panel — both discover only `Filament/Admin/Resources` and `Filament/App/Resources`, and this sits directly under `Filament/Resources`.

**Moving it is not the fix.** Both panels are tenant-scoped on `Team`, and `customer_segments` carries no `team_id` — so relocating the resource reproduces [#958](https://github.com/liberusoftware/ecommerce-laravel/issues/958) exactly, on a third table. It lands after the tenant scope does, in [wave 1.5](./MIGRATION_PLAN.md#wave-15--stores-channels-and-the-tenant-scope).

Two entries previously listed here have been resolved:

- **`SocialLinksWidget`** — deleted. `AppPanelProvider` pointed `discoverWidgets` at `Filament/App/Widgets/Home`, a directory that has never existed, so the widget never loaded; it also rendered a view that was never written and fell back to links belonging to a different Liberu product. Discovery now points at `Filament/App/Widgets`, so a widget placed there loads.
- **`MenuResource` registered twice** — not a defect. `FilamentMenuBuilderPlugin::register()` calls `$panel->resources([...])` with the same class that `discoverResources` finds, but `Panel::getResources()` returns `array_unique($this->resources)`, so the duplicate is dropped before anything reads it.

---

## Security notes for anyone running this today

Recorded here rather than only in the findings document, because they change how an instance should be deployed.

**Every merchant's catalogue is currently readable across tenant boundaries** on three surfaces: the authenticated REST API, the anonymous GraphQL endpoint, and the anonymous Blade storefront and sitemap. The root cause is a tenant trait that declares a relation but no global scope. Full detail in [`CONFORMANCE.md` §6](./CONFORMANCE.md#6-security-findings); the fix ships in [wave 1.5](./MIGRATION_PLAN.md#wave-15--stores-channels-and-the-tenant-scope).

**The sitemap is the one to act on first if you cannot wait for the fix.** It publishes every merchant's product URLs to search engines, and unlike the other two the harm persists after the fix until the index is re-crawled.

**`admin` and `super_admin` are global roles, not per-team ones** — `config/permission.php:125` sets `'teams' => false`. An admin of one merchant is an admin of all of them.
