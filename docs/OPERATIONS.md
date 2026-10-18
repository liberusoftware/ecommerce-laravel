# Operations

Running an installed instance. Getting one installed is [`INSTALLATION.md`](./INSTALLATION.md).

---

## Console commands

Every command registered by the application, taken from `app/Console/Commands/`.

| Command | Does |
| --- | --- |
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

| Panel | Path | Who | Scope |
| --- | --- | --- | --- |
| Platform | `/admin` | `super_admin`, or platform staff granted `access_admin_panel` | Every store; no tenancy |
| Merchant | `/app` | Members of a team | The tenant team's stores |

Both panels run `strictAuthorization()`: a resource without a policy throws rather than allowing.

### Who may do what

Access is decided in one place, `App\Support\StoreAccess`, which every policy, storefront staff route and API controller uses.

- **Platform layer.** `super_admin` may do anything. Other platform staff hold Spatie permissions named `{ability}_{model}` (`view_any_order`, `update_product::category`), granted through roles in the Shield role editor. A platform permission applies to every store; that is what makes it a platform permission. Users, roles, teams, stores, channels and domains are `super_admin` only.
- **Merchant layer.** A team's owner holds every permission in that team. Other members hold the permissions of their team role (Jetstream roles, defined in `JetstreamServiceProvider`): `admin` (everything, including settings and members), `catalogue`, `orders` and `viewer`. A role in one team grants nothing in another, and owning or administering a team never reaches `/admin`.

Products, categories, orders and coupons are one resource class each, registered in both panels. In `/admin` a new record must name its store; `/admin` never guesses one.

### Merchant sign-up

On `saas` and `marketplace` installations any signed-in user can open a store from `/app`; the team and its store are created together, and the owner can manage it immediately. A store has no domain until the platform maps one under Stores. On a `single` installation there is no self-service sign-up.

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

Check the user's team role (merchant panel) or platform permissions (platform panel): navigation hides what the policy refuses. Platform pages and dashboard widgets each check their own permission (`view_any_order`, `view_any_product`, `view_any_customer`).

Two entries previously listed here have been resolved:

- **`SocialLinksWidget`** — deleted. `AppPanelProvider` pointed `discoverWidgets` at `Filament/App/Widgets/Home`, a directory that has never existed, so the widget never loaded; it also rendered a view that was never written and fell back to links belonging to a different Liberu product. Discovery now points at `Filament/App/Widgets`, so a widget placed there loads.
- **`MenuResource` registered twice** — not a defect. `FilamentMenuBuilderPlugin::register()` calls `$panel->resources([...])` with the same class that `discoverResources` finds, but `Panel::getResources()` returns `array_unique($this->resources)`, so the duplicate is dropped before anything reads it.

---

## Security notes

- A store's data is reachable only through its own hostname on the storefront and API (`ResolveChannel` runs before route-model binding), through its team's tenant in `/app`, or through `/admin` with a platform permission.
- Global Spatie roles and permissions are platform-wide by definition. Grant them only to platform staff; merchant staff get team roles.
- Webhook endpoints receive every store's order events and are `super_admin` only.
