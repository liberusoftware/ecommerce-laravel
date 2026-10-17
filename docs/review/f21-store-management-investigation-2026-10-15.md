# F-21 — Managing stores, channels and domains: investigation and proposed scope

Status: **decided.** Team stays the Filament tenant and workspace; Store owns commerce data. The approved plan, with the conflicts found against the decisions, is the F-21 section of [the development plan](development-plan-2026-10-15.md).

Method: read the models, migrations, middleware, panel providers, resources and policies, plus the
design record in `docs/MIGRATION_PLAN.md` ("Wave 1.5 — stores, channels, and the tenant scope") and
`CONTEXT.md`. One throwaway probe submitted the existing admin "Stores" create form.

---

## 1. What exists today

### Models and relationships

```
User ──(Jetstream membership: team_user, ownership: teams.user_id)──▶ Team
Team 1──n Store            stores: id, team_id (nullable), name, slug (unique)
Store 1──n Channel         channels: id, store_id, name, theme (default 'theme-ecommerce')
Channel 1──n ChannelDomain channel_domains: id, channel_id, host (unique), is_primary
Commerce rows (products, orders, customers, …): store_id + team_id
```

| Concept | Where | What it does today |
|---|---|---|
| **Team** | `App\Models\Team` (Jetstream) | The **Filament tenant** in both panels (`->tenant(Team::class, ownershipRelationship: 'team')`). Membership and ownership come from Jetstream. Has `products()`, `orders()` and similar relations, but **no `stores()` relation**. |
| **Store** | `App\Models\Store` | "A catalogue and its commercial configuration, owned by a Team. One Team may own several" (`CONTEXT.md`). Only `name`, `slug` and `team_id`. The **store scope** (`IsStoreScoped` with `StoreContext`) isolates commerce data per store. |
| **Channel** | `App\Models\Channel` | "A way customers reach a Store — a web storefront, a mobile app, a marketplace" (`CONTEXT.md`). Carries a `theme` reference that nothing reads yet. |
| **ChannelDomain** | `App\Models\ChannelDomain` | Hostnames, several per channel, one marked primary for canonical URLs. Hosts are normalised (lower-case, no port), and saving or deleting a domain clears the trusted-hosts cache. |

### Request → store resolution

- `ResolveChannel` (on the `web` and `api` groups) maps **host → Channel → Store → Team** through
  `ChannelResolver`. An unknown host gets a **404**, with no fallback to a default merchant.
- `TrustHosts` trusts the application URL's subdomains plus every `channel_domains.host`, regex-quoted,
  from one cached list. **Mass deletes bypass the cache clear** (documented in Wave 1.5).
- Resolution is **by hostname only**. No storefront route is prefixed by store, path or domain.
- The initial-channel migration creates store 1, channel 1 and domains from `APP_URL`, `localhost` and
  `127.0.0.1`. Stores created later for other teams have **no channel**, so no hostname reaches them
  ("unreachable, rather than served from somebody else's domain").

### Panels and authorization

- `/admin`: `super_admin` only (`User::canAccessPanel`). It doesn't use strict authorization.
  Platform-wide resources opt out of tenancy with `$isScopedToTenant = false`, and
  `AdminPanelTenancyTest` requires that to be declared on the resource itself.
- `/app`: any user with a team; strict authorization; resources are team-scoped by Filament. Since
  Phase 2, the panel switches the user's current team to the tenant being viewed.
- No `StorePolicy`, `ChannelPolicy` or `ChannelDomainPolicy` exists. Roles are global (Spatie
  `teams => false`, F-15). No Jetstream team roles are defined.

### Installation configuration and feature flags

- **No representation of the operating model** (single store, SaaS or marketplace) exists in config,
  the database or `setup.sh`.
- Feature availability is environment flags in `config/ecommerce.php` (tax, loyalty, preorders,
  wholesale, refunds) and `MODULES_ENABLED` for the module manager.
- `GeneralSettings` (Spatie settings: site name, email, currency, footer) is **installation-wide, not
  per store**.

## 2. What is incomplete or inconsistent

| # | Finding | Evidence |
|---|---|---|
| 1 | **No resource manages `Store`, `Channel` or `ChannelDomain`.** Onboarding a merchant or adding a hostname needs database access. | No Filament resource for those models. |
| 2 | **The admin "Stores" resource edits `Team`, and creating one fails with a server error.** | `StoreResource::$model = Team::class`. The probe got `NOT NULL constraint failed: teams.user_id`, because the form has only `name`. |
| 3 | **`Team` has no `stores()` relation.** | `App\Models\Team`. |
| 4 | **Deleting a store would orphan its catalogue and orders.** `store_id` on commerce tables is unconstrained by design; `channels` and `channel_domains` cascade. | `2026_08_08_000002_add_store_id_to_team_scoped_tables.php`. |
| 5 | **Orders don't record their channel.** There's `store_id` but no `channel_id`, so "where did this sale originate" can't be answered. | `orders` schema. |
| 6 | **Domains are trusted as soon as they're saved.** There's no verification state. That's fine while only platform admins add domains; not safe for merchant self-service. | `TrustHosts::channelDomainPatterns()`. |
| 7 | **Panels answer on every trusted host, including merchant storefront domains** (for example `merchant.com/admin`). | Panels aren't behind `ResolveChannel`. |
| 8 | **`Channel.theme` is stored but not used** to select views. | No reader outside `Channel`. |

## 3. A conflict to resolve before any implementation

The brief says **"a Store represents a business and is the primary tenant."** In this codebase:
- the **Team** is the Filament tenant and holds membership and ownership;
- a **Store** is one catalogue a Team owns, and a Team may own several.

Making `Store` the Filament tenant would add a second tenancy model next to the one that exists. It
would mean re-keying panel membership, roles and every panel resource from `team_id` to `store_id`,
which is exactly the "competing tenancy abstraction" the brief warns against.

**Recommendation: keep the Team as the tenant**, and treat it as the business or merchant account,
with its Store as that business's catalogue and commercial unit. In SaaS and single-store
deployments a Team will normally have one Store, so "operate within Store 1's context" is satisfied
by operating within its Team. Multiple stores per Team already work (the store scope covers every
store the team owns). A panel store selector would be later work, not F-21.

⚑ **Decision needed:** confirm Team = tenant and business, Store = its catalogue. If you want Store to
become the Filament tenant instead, that is a separate, larger project and should be planned on its
own.

## 4. Proposed F-21 scope: the smallest usable implementation

Platform administrators manage stores, channels and domains in `/admin`. Merchants can see their
storefront addresses in `/app`. **No schema changes are required.**

| # | Change | Where |
|---|---|---|
| 1 | **Store resource (platform):** create, edit and list real `Store` records (name, slug, owning team). Delete is refused while the store has any store-scoped rows (products, orders, customers…), which addresses inconsistency 4. A `StorePolicy` limits it to `super_admin`. Declared `$isScopedToTenant = false`. | `app/Filament/Admin/Resources/Stores/` (replaces the Team-backed resource there) |
| 2 | **Team resource (platform):** the existing Team-backed resource is relabelled "Teams", and creating one requires choosing an owner, which fixes the server error. | moved from `Stores/` |
| 3 | **Channels on a store:** a relation manager on the Store edit page (name; `theme` kept as plain text with the existing default). | `StoreResource` |
| 4 | **Domains on a channel:** a Channel edit page with a domains relation manager. | `ChannelResource` (admin, unscoped) |
| 4a | Hosts are normalised (lower-case, no scheme or port) and validated as hostnames, and must be unique. | |
| 4b | Exactly one primary per channel: setting one clears the others. | |
| 4c | Deletes are per record, so `ChannelDomain`'s model events clear the `TrustHosts` cache. No bulk query deletes. | |
| 4d | The last domain of a channel that serves the `APP_URL` host can't be removed, so the root storefront can't be taken offline by accident. | |
| 5 | **`Team::stores()`** relation. | `App\Models\Team` |
| 6 | **Merchant view (read-only):** a "Storefront" page in `/app` listing the current team's stores, channels and their URLs (primary domain first). No editing. | `app/Filament/App/Pages/` |
| 7 | *(Optional)* **Default subdomain:** if `STOREFRONT_BASE_DOMAIN` is set, a Store gets a "Create storefront" action that adds a channel with `{slug}.{base}`. Unset means the action is hidden, so nothing about domains is forced on an installation. | `config/ecommerce.php`, `StoreResource` |

**Why subdomains rather than subpaths as F-21's zero-configuration default:**
- Host resolution already exists end to end, including `TrustHosts`, which already trusts the
  application's own subdomains.
- `*.localhost` resolves to 127.0.0.1 in current browsers, so `store-a.localhost:8000` works in local
  development without DNS.
- Subpaths (`example.com/store-a`) would need storefront route prefixing, URL generation, canonical
  links and cookie and cart behaviour per path. That's real routing work, and it's listed below as
  separate.

### Tests the implementation would add (existing conventions: HTTP requests for panel tenancy, one panel per request)

- **Authorization:** a non-`super_admin` can't reach the Store, Team or Channel resources. A merchant's
  `/app` Storefront page lists only their own team's stores.
- **Domain rules:** normalisation, rejecting invalid and duplicate hosts, a single primary per
  channel, and protecting the `APP_URL` host's last domain.
- **Domain resolution:** a domain added through the panel resolves to its store over HTTP and serves
  only that store's catalogue. A removed domain returns 404 and is no longer trusted (cache cleared).
- **Data safety:** deleting a store with catalogue or orders is refused; deleting an empty store works.
- **Team creation:** the Team resource's create works and records the chosen owner.
- **Tenant isolation:** a store created for team B is invisible to team A in `/app` and on team A's
  storefront.

## 5. Separate work, not in F-21

| Concern | Why it is separate | Prerequisites |
|---|---|---|
| **Merchant self-service domains** | Needs a verification state (for example `channel_domains.verified_at` plus a DNS TXT challenge), and unverified hosts must not be trusted or resolved. Domain squatting and host-header abuse depend on it. | A schema change (`verified_at`), a verification job, `TrustHosts` and `ChannelResolver` reading only verified hosts |
| **TLS for custom domains** | Infrastructure (on-demand TLS in the proxy, cert-manager in `k8s/`), not application code | Deployment design |
| **Charging for subdomains or custom domains** | Must stay outside the core. Suggested hook: a gate such as `manage-custom-domains`, which defaults to allowed and can be overridden by a hosted deployment | Billing design |
| **Subpath storefronts** (`example.com/store-a`) | Storefront route prefixing, URL generation, canonical links, cookie and cart scoping per path | A routing design |
| **Operating model at install time** (single store, SaaS, marketplace) | No representation exists; it changes what the root domain shows and which capabilities exist | A config or setting, plus `setup.sh` |
| **Marketplace** (root domain aggregating seller storefronts) | Nothing in the code aggregates across stores; the store scope deliberately prevents it | The operating-model decision |
| **Per-store settings** (display name, contact, currency) | `GeneralSettings` is installation-wide | A settings design |
| **Sales-channel attribution** (`orders.channel_id`, a physical-shop channel) | Orders don't record their channel today | A schema change |
| **Theme selection by channel** | `Channel.theme` is stored but not read | Theme packages |
| **Panels on merchant domains** | `/admin` and `/app` answer on every trusted host | Restrict panels to the platform host |
| **Store as the Filament tenant** | See §3 | A tenancy redesign |
| **F-23** (menu-builder script not published) | Unrelated asset issue | — |

## 6. Decisions requested

1. **Tenancy:** confirm Team = Filament tenant and business, Store = its catalogue (§3).
2. **Scope:** approve items 1–6, and say whether to include the optional item 7 (default subdomain
   from `STOREFRONT_BASE_DOMAIN`).
3. **The current Team-backed "Stores" resource:** relabel it "Teams" and fix its create (proposed),
   or remove it.
