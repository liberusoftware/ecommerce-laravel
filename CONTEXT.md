# Ecommerce

A multi-merchant commerce platform: one shared deployment serving many independent merchants, each on its own domain.

This file is deliberately narrow. It defines **only** the terms this repository uses that [`MODULES.md` §2](https://github.com/liberusoftware/documentation/blob/main/architecture/MODULES.md) does not — its architectural vocabulary (repository, package, capability, module, product, application, theme) is authoritative and not restated here.

## Tenancy

The five terms below are routinely used as if interchangeable. They are not, and confusing them has produced real defects — see [`docs/CONFORMANCE.md`](./docs/CONFORMANCE.md)'s security chapter.

**Merchant**:
A business selling on this platform. The commercial party, not a database row — every other term here is some technical aspect of one.
_Avoid_: client, vendor, seller, shop

**Team**:
The tenant boundary. Data belonging to one `Team` must never be visible to another. Implemented by Jetstream's `Team`, which this repo did not choose so much as inherit.
_Avoid_: organisation, account, tenant (as a noun for the row itself)

**Tenant**:
The property of being scoped to a `Team` — "tenant-scoped", "the tenant boundary". A role, not a thing.
_Avoid_: using it to mean a `Team` record

**Store**:
A catalogue and its commercial configuration, owned by a `Team`. One `Team` may own several. Commerce tables scope on `store_id`; everything else scopes on `team_id`.
_Avoid_: shop, site, brand

> **Warning — the word is currently overloaded in the UI.** `app/Filament/Admin/Resources/Stores/StoreResource.php` sets `$model = Team::class` with `$modelLabel = 'Store'`, so the admin panel labels a `Team` a "Store" today. That is the *old* meaning and is being retired. No `Store` model or `stores` table exists yet.

**Channel**:
A way customers reach a `Store` — a web storefront, a mobile app, a marketplace. Hostnames are a channel property, which is how a request finds its merchant.
_Avoid_: storefront (that is one kind of channel), site, domain

## Reviews and ratings

Two parallel stacks exist for what sounds like one concept. Both survive; neither is redundant.

**Review**:
Written feedback on a product, subject to moderation before it is publicly visible.
_Avoid_: comment, feedback, testimonial

**Rating**:
A numeric score on a product. Independent of whether a `Review` accompanies it.
_Avoid_: score, stars, review

**Customer**:
A person's commercial relationship with one `Team`. One `User` may hold several — a shopper who has bought from three merchants has three `Customer` records.
_Avoid_: buyer, shopper, account, client

**User**:
An authenticated identity. Platform-wide and not tenant-scoped, which is why a `User` arriving on one merchant's domain while holding a `Customer` record at another is legitimate traffic rather than an attack.
_Avoid_: customer, member, account

## Where the rest lives

| Vocabulary | Source |
| --- | --- |
| Architectural terms — repository, package, capability, module, product, application, distribution, adapter, theme | [`MODULES.md` §2](https://github.com/liberusoftware/documentation/blob/main/architecture/MODULES.md) |
| Module names and boundaries | [`ECOMMERCE.md`](https://github.com/liberusoftware/documentation/blob/main/projects/ecommerce/ECOMMERCE.md) — authoritative; a module not in it requires a documented addition |
| Package, repository and namespace naming | [`docs/MODULE_DEVELOPMENT.md`](./docs/MODULE_DEVELOPMENT.md) |
| Theme vocabulary | [`THEMES.md`](https://github.com/liberusoftware/documentation/blob/main/standards/THEMES.md) |
