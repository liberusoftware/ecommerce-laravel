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
| `module {action} {name?}` | Module scaffolding. **Does nothing useful** — see below |

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

Two things worth knowing about the current queue configuration:

- **`after_commit` is `false` on all four connections** (`config/queue.php:42,51,62,71`), and `dispatchAfterCommit` is never used — while two checkout paths dispatch webhooks from inside open transactions. A job can therefore run against a transaction that has not committed yet.
- **`SendWebhookDelivery` hand-rolls its own retry** (`:14`) and re-dispatches itself rather than failing. Its failures never reach `failed_jobs`, so they are invisible to Horizon and to `queue:failed`.

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
3. If the key looks correct but resolves empty at runtime, check whether `config:cache` is active — `SubscriptionController.php:21` reads it via `env()` in the constructor, which returns `null` under a cached config.
4. Review the logs for API errors.

### `/products/compare` returns a 500

It will. `routes/web.php:188-191` registers four product-compare routes whose controller methods are all commented out at `Frontend/ProductController.php:222-259`. There is no configuration that fixes this; the routes need removing or the methods restoring.

### A configuration value is empty in production but fine locally

Check for `env()` outside `config/`. Under `config:cache`, `env()` returns `null` everywhere except during config loading. Two known sites: `DropxlService.php:23` and `SubscriptionController.php:21`.

### A widget or resource does not appear

Three known causes, all wiring rather than permissions:

- `app/Filament/Resources/` (149 lines) is discovered by **neither** panel — both discover only `Filament/Admin/Resources` and `Filament/App/Resources`.
- `App/Widgets/SocialLinksWidget.php` never loads: `AppPanelProvider.php:89` points at a directory that does not exist.
- `MenuResource` is registered **twice** inside one panel.

---

## Security notes for anyone running this today

Recorded here rather than only in the findings document, because they change how an instance should be deployed.

**Every merchant's catalogue is currently readable across tenant boundaries** on three surfaces: the authenticated REST API, the anonymous GraphQL endpoint, and the anonymous Blade storefront and sitemap. The root cause is a tenant trait that declares a relation but no global scope. Full detail in [`CONFORMANCE.md` §6](./CONFORMANCE.md#6-security-findings); the fix ships in [wave 1.5](./MIGRATION_PLAN.md#wave-15--stores-channels-and-the-tenant-scope).

**The sitemap is the one to act on first if you cannot wait for the fix.** It publishes every merchant's product URLs to search engines, and unlike the other two the harm persists after the fix until the index is re-crawled.

**`admin` and `super_admin` are global roles, not per-team ones** — `config/permission.php:125` sets `'teams' => false`. An admin of one merchant is an admin of all of them.
