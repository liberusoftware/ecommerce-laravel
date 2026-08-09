# CMS-owned code in this repository: reconciliation

**Verified 2026-08-09** against `liberusoftware/cms-laravel@main`, `liberusoftware/crm-laravel@main`,
`liberusoftware/documentation@main`, and this repository at `54b9da7`. Tracks [#942].

This document does not move any code and does not decide where the code goes. It records what is
actually on the other side of the boundary, so that when the move becomes possible it is a mechanical
operation rather than a discovery exercise. It applies the discipline of
[ADR 0008](../adr/0008-reviews-and-ratings-merge.md): where two implementations of the same thing
exist, name the difference field by field, and name what is lost by picking either — because *the
tests that pass are the tests that no longer exist*.

Every claim below is sourced to a path. Where a thing could not be established, §5 says so instead of
guessing.

---

## 1. What is actually where

### 1.1 The premise of #942 is wrong

#942 defers on the criterion *"The CMS product repository exists and the owning module is
published."* Both halves need correcting, in opposite directions.

**The CMS repository exists and is not an empty shell.** Reading `cms-laravel`'s `app/Models/`
does show only `Category, Collection, CollectionItem, ConnectedAccount, Membership, Role, Tag, Team,
User` — no `Article`, `Page`, `FAQ` or `SeoSetting`. That reading is accurate and its conclusion is
backwards. The host `app/` is thin **because the extraction already happened there**, not because it
never started. The tree carries twenty-one committed path packages under `packages/liberu-cms/`:

```
cms-admin  cms-api  cms-audit  cms-blocks  cms-content  cms-content-types  cms-contracts
cms-core   cms-forms  cms-hello  cms-media  cms-menus  cms-notifications  cms-observability
cms-pages  cms-posts  cms-search  cms-seo  cms-themes  cms-users  cms-widgets
```

`cms-laravel`'s `composer.json` requires twenty of them at `"*"` and resolves them from
`{"type": "path", "url": "packages/liberu-cms/*", "symlink": true}`. Its
`app/Filament/Resources/Pages/PageResource.php` imports `Liberu\Cms\Pages\Models\Page`, and
`database/migrations/2026_07_23_000000_drop_legacy_pages_table.php` drops the host `pages` table
outright with the comment *"Pages are now owned by the cms-pages module (`cms_pages`)."*

Its own `docs/OPEN-QUESTIONS.md` §3 records the same work in the same words this repository uses:

> **Page — RETIRED.** The host `App\Models\Page`, its factory, and the legacy `pages` table are
> gone; `PageController`, public routes, `PageResource`, the menu-builder config, `PageSeeder`, and
> all tests now run on the module `Liberu\Cms\Pages\Models\Page` (`cms_pages`). Suite green.

**Nothing is published.** This half of the criterion holds, and the check that established it used
the wrong names, so it is worth restating with the right ones.

| Name | Status |
| --- | --- |
| `liberu-cms/cms-pages`, `cms-seo`, `cms-forms`, `cms-core`, … | 404 on Packagist. They are path packages inside `cms-laravel`'s own tree; they exist nowhere else. The `liberu-cms` GitHub org holds `cms-old`, `cms-nuxt`, `cms-filament` and `.github` — not these. |
| `liberusoftware/module-cms-pages`, `-seo`, `-sitemaps`, `-form-builder`, `-knowledge-base`, `-editorial-content`, `-cms-core`, and ~240 siblings | The **repositories exist**. Every one probed is 2 KB, one blob, `README.md` only: no `composer.json`, no `src/`, zero tags, zero releases. |
| `liberusoftware/cms-core`, `cms-pages`, `cms-seo`, `cms-sitemaps`, `cms-form-builder`, `cms-knowledge-base`, `cms-editorial-content` | 404 on Packagist. These are the names [ADR 0004](../adr/0004-no-module-prefix-in-package-names.md) predicts — the `module-` prefix is a repository-name convention only, so `module-blog-core` the repository publishes `liberusoftware/blog-core` the package, and that one **is** on Packagist. The CMS equivalents are not. |

The earlier Packagist check against `liberusoftware/module-cms-core`, `module-pages`,
`module-editorial-content` and `module-seo` reached the right conclusion from names that could never
have existed: the actual repository is `module-cms-cms-core`, the siblings carry a `cms-` infix, and
by ADR 0004 none of them would publish under a `module-` prefix anyway. Checked under every plausible
name, nothing is published.

So the deferral stands, but not for the reason the issue gives. It is not that the code has no home.
It is that **the implementation exists and lives inside another application's repository**, under a
vendor the fleet does not use:

- `cms-laravel` implements the modules as `liberu-cms/cms-*`, committed in its own tree, resolved by
  a path repository, published nowhere.
- The repositories that will hold them, `liberusoftware/module-cms-*`, are empty placeholders whose
  README boilerplate advertises `composer require liberusoftware/module-cms-pages` — a name ADR 0004
  says will not be used.

The package name itself is therefore settled (`liberusoftware/cms-pages`, by ADR 0004). What is not
settled is who moves twenty-one packages out of `cms-laravel` and tags them, and that is not a
decision this repository can take.

### 1.2 `crm-laravel` holds CMS-catalog capabilities

`crm-laravel` is at `v2.0.1` and heavily built. It contains implementations of capabilities the CMS
catalog claims:

| CRM code | CMS catalog row that claims it | Real? |
| --- | --- | --- |
| `app/Models/KnowledgeBaseArticle.php`, `knowledge_base_articles` table, staff + portal Filament resources, `KnowledgeBaseController`, `tests/Feature/Portal/PortalKnowledgeBaseTest.php` | `module-cms-knowledge-base` — *"Article hierarchies, versions, feedback, review cycles, search tuning, related articles, support integration"* | **Yes.** Published/draft flag, categories, helpful / not-helpful counters, team scoping, a customer-portal read surface, four passing tests. |
| `app/Models/FormBuilder.php`, `form_builders` table, `FormBuilderResource`, `FormBuilderController` | `module-cms-form-builder` — *"Typed/conditional multi-step forms, reusable fields, uploads, validation, calculations, drafts, confirmations, embedding"* | **Partly, and dead.** The model implements multi-step and conditional-logic evaluation, but `FormBuilderController`'s twelve methods have **zero registered routes**, the Filament repeater never authors the `key` the logic matches on, and no public renderer or submission endpoint exists. |
| `app/Models/Menu.php`, `app/Models/SiteSettings.php` | `module-cms-navigation`, `module-cms-configuration-management` | Menu is real and tested. `SiteSettings` declares `$fillable = ['*']` (a literal string, not a wildcard — every attribute is unfillable) and **no migration creates its table**. |
| `app/Models/LandingPage.php` | Not CMS. `module-crm-landing-pages-and-funnels` legitimately owns *"landing/thank-you pages, templates, domains, SEO metadata, …"* | Correctly placed, though `landing_pages.campaign_id` has no migration despite being fillable, related and a required form field. |

So the suspicion is confirmed for two rows and refuted for one. Knowledge-base and form-builder are
CMS-catalog capabilities implemented in the CRM product; landing pages are not.

**`crm-laravel` has no SEO code of any kind.** A case-insensitive search of all 1,749 tree paths
returns zero hits for `seo` and zero for `sitemap`. It has no `app/Mail/` directory at all. Whatever
SEO and sitemap ownership turns out to be, the CRM is not a claimant.

---

## 2. Per-cluster verdicts

| Cluster | Local state | Verdict |
| --- | --- | --- |
| **Page** | Real, small, admin-only | **Duplicate.** `cms-pages` implements the same thing and is strictly ahead. §3.1 |
| **Article / FAQ** | Scaffold with no columns and no content | **Neither.** There is nothing to move; the move is a delete. §3.2 |
| **SEO (`SeoSetting`)** | Rich, tested, and **never read** | **Duplicate — inside this repository.** Its rival is `products.meta_*`, not the CMS. §3.3 |
| **Sitemap** | Real, tested, ecommerce-specific | **Not CMS-owned as written.** The mechanism is CMS's; every URL it emits is ecommerce's. §3.4 |
| **Contact form** | Real, tested, seven behaviours | **Contested, and correctly owned by neither claimant.** §3.5 |

---

## 3. The diffs

### 3.1 Page — duplicate, module ahead

Schema. Left is `pages` (`database/migrations/2024_11_25_223311_create_pages_table.php`); right is
`cms_pages` (`packages/liberu-cms/cms-pages/database/migrations/2026_01_04_000000_create_cms_pages_table.php`).

| Column | Here | `cms-pages` |
| --- | --- | --- |
| `title` | `string` | `string` |
| `slug` | `string` unique | `string` unique |
| `content` | `text` nullable | `longText` nullable |
| `excerpt` | — | `text` nullable |
| `template` | — | `string` default `'default'` |
| `status` | `string` default `'draft'` | `string` default `'draft'`, **indexed** |
| `published_at` | — | `timestamp` nullable |
| parent | `parent_page_id` → `pages`, **`cascadeOnDelete`** | `parent_id` → `cms_pages`, **`nullOnDelete`** |
| `featured_media_id` | — | `unsignedBigInteger` nullable |
| `team_id` | — (deliberately: `PageResource` sets `$isScopedToTenant = false`, and `pages` is absent from every `add_team_id_*` migration) | `unsignedBigInteger` nullable, indexed |
| `user_id` | — | `unsignedBigInteger` nullable |

Model. Here: 50 lines — `HasFactory`, two status constants, `getStatuses()`, `scopePublished()`,
`isPublished()`, `isDraft()`, a default-attribute for `status`. There: `final`, `strict_types`,
implements `PublishableInterface`, uses `HasRevisions`, `HasTenant`, `HasWorkflow`, has
`parent()`/`children()` relations, resolves `featuredMedia()` through `MediaRepositoryInterface`, and
auto-slugs on `saving` via `Slugger::unique()`.

Surfaces. Here: one Filament admin resource (title, slug, RichEditor content, status select; table of
title/slug/status/timestamps) and **no public route** — `routes/web.php` resolves no `Page`, and
there is no `PageController`. There: a Filament resource, `PageApiController`, `PageWriteController`
with `StorePageRequest`/`UpdatePageRequest`, a JSON API `PageResource`, `PageRepository` behind
`PageRepositoryInterface`, `PagePreviewSource`, `PageSearchSource`, and `PageSitemapProvider` — plus
a public `PageController` and route in the CMS host.

Tests. Here: `tests/Unit/PageModelTest.php`, seven methods, all model-level (create, default status,
`isPublished`, `isDraft`, published scope, `getStatuses`, constants). No resource test, no route
test. `database/factories/PageFactory.php` has an **empty `definition()`**. There:
`tests/Unit/PageModelTest.php`, `Feature/Cms/PageModuleTest.php`, `Feature/PageControllerTest.php`,
`Feature/PageContentSanitizationTest.php`, `Feature/Filament/CmsPageResourceTest.php`,
`Feature/Filament/PageResourceTest.php`, `Feature/Api/PagesApiTest.php`,
`Feature/Api/PagesWriteApiTest.php`.

**Lost by adopting `cms-pages`:** two things, both small and both real.

1. **`cascadeOnDelete` becomes `nullOnDelete`.** Deleting a parent page here deletes its subtree;
   in the module it re-parents the children to root. That is a behaviour change, arguably to the
   safer option, but it is a change and no test on either side pins it.
2. **`Page::getStatuses()`.** The local Filament select is built from it. The module has no
   equivalent helper; status options come from `HasWorkflow`.

The seven local unit tests are all superseded by module equivalents. Nothing else here is unique.

**Lost by keeping the local Page:** revisions, editorial workflow, tenancy, templates, excerpts,
`published_at`, featured media, automatic slugging, public routing, a read and write API, search
indexing, preview, and sitemap contribution.

### 3.2 Article and FAQ — nothing to move

This cluster is neither an orphan nor a duplicate. It is empty.

- `database/migrations/2024_09_25_110504_create_articles_table.php` creates `articles` with
  **`id()` and `timestamps()` and nothing else**. It later acquires `team_id`
  (`2026_07_15_000000_add_team_to_remaining_resources.php`) and `store_id`
  (`2026_08_08_000002_add_store_id_to_team_scoped_tables.php`) — scoping columns for a row that has
  no content columns to scope.
- `app/Models/Article.php` is 18 lines: `HasFactory`, `IsStoreScoped`, a `team()` relation. No
  `$fillable`, no casts, no scopes.
- `database/factories/ArticleFactory.php` has an empty `definition()`.
- `app/Filament/App/Resources/Articles/ArticleResource.php` declares `components([])` and
  `columns([])` — a form with no fields and a table with no columns.
- `app/Policies/ArticlePolicy.php` is a complete twelve-method Filament Shield policy guarding it.
- No route, no view, no test.
- `app/Filament/Admin/Pages/FAQ.php` is 14 lines of navigation metadata pointing at
  `resources/views/filament/admin/pages/f-a-q.blade.php`, which is three lines: an opening
  `<x-filament-panels::page>`, a blank line, and the close. No questions, no model, no data.

The corresponding module is real: `cms-posts` has `Post` on `cms_posts` with revisions, workflow,
tenancy, taxonomy (`Category`, `Tag`), featured media and auto-generated excerpts, covered by
`PostModuleTest`, `PostsApiTest`, `PostsWriteApiTest` and `CmsPostResourceTest`. `crm-laravel`'s
`KnowledgeBaseArticle` is also real and portal-facing.

There is no migration to write and no behaviour to preserve, because there is no behaviour. When
this moves, what moves is a decision — *editorial content and knowledge base come from the CMS* —
and what happens here is a deletion of five files and two empty factories. **Nothing is lost by
deleting it today except the Shield permission strings** (`view_any_article`, `create_article`, …),
which any seeder or role assignment referencing them would need updating for.

### 3.3 SEO — a duplicate, but not the one #942 names

`app/Models/SeoSetting.php` and `seo_settings` are substantial: eighteen columns, a
`seoable` morph, tenant scoping (`team_id` added by
`2026_08_09_000001_add_team_id_to_the_remaining_tenant_models.php`), default title and description
generation branching on `Product`/`ProductCollection`/`Page`, `generateStructuredData()` for
products, a hundred-point `calculateSeoScore()` rubric, and `scopeWithLowScore()`. Six unit tests in
`tests/Unit/SeoSettingModelTest.php`.

**None of it is read.** `SeoSetting` appears in exactly three files outside its own test:
`app/Models/SeoSetting.php`, `app/Models/Product.php` (a `morphOne`), and this document's evidence
trail. There is no Filament resource, no controller, no Blade template that touches it. What actually
renders the storefront's `<head>` is `resources/views/layouts/app.blade.php` yielding
`meta_description` and `og_title`, filled by `resources/views/products/show.blade.php` from
`$product->meta_title` and `$product->meta_description` — **columns on `products`**
(`2022_09_26_113708_create_products_table.php`) and on `product_categories`
(`2026_07_14_002001_add_meta_columns_to_product_categories_table.php`). Nothing writes `seo_settings`
either; there is no admin surface for it.

So the duplication here is internal, and it is the exact shape ADR 0008 describes: two parallel
stacks for one capability. The difference is that this pair has a clear loser. `products.meta_*` is
the stack that ships; `seo_settings` is a richer stack that has never been connected to anything.

The CMS side does not resolve it. `cms-seo` has **no per-record settings table at all**. It offers
config-driven defaults (`cms-seo.meta.site_name`, `default_description`, `twitter`), a
`<x-cms-seo::meta>` Blade component taking `title`/`description`/`canonical`/`image`/`type`/
`publishedTime` and emitting description, canonical, `og:*`, `twitter:card` and a JSON-LD block, and
a `RobotsController` rendering `robots.txt` from configured crawl groups. Tested by
`Feature/Seo/PageMetaTest.php`, `RobotsTest.php`, `SitemapTest.php`.

| | `seo_settings` here | `cms-seo` |
| --- | --- | --- |
| Per-record stored overrides | 18 columns, morph to any model | none — props passed at render |
| Structured data | stored `structured_data` JSON + a Product generator | generated at render from the props |
| Content scoring | `calculateSeoScore()`, `focus_keyword`, `scopeWithLowScore()` | none |
| Twitter / OG overrides | eight dedicated columns | derived; `twitter:card` inferred from whether an image is present |
| `robots.txt` | none | `RobotsController` + config |
| **Renders anything** | **no** | **yes** |

**Lost by adopting `cms-seo`:** the eighteen stored columns, the morph, the scoring rubric, the focus
keyword, and the six unit tests. Because none of it is read, the *user-visible* loss is zero — the
loss is the code and the tests.

**Lost by keeping it:** nothing user-visible, for the same reason.

The decision that matters for this cluster is therefore not *where does it go* but *is it wired up
before it goes anywhere*. Moving dead code into a module makes it somebody else's dead code, and
`cms-seo` would then own a scoring rubric it did not ask for and cannot render. The honest options
are: connect `seo_settings` to the `<head>` and merge `products.meta_*` into it, or delete it. Both
are ADR-worthy and neither is blocked on the CMS.

### 3.4 Sitemap — the mechanism is CMS's, the content is not

`app/Http/Controllers/SitemapController.php` enumerates `ProductCategory` then `Product`, caps the
total at `config('sitemap.max_urls', 50000)`, and writes every URL on
`ChannelResolver::current()?->primaryDomain()?->host` rather than on the host the crawler used —
keeping the request's scheme so a TLS-terminated deployment does not publish `http` URLs. Nine tests:
three in `tests/Feature/SitemapControllerTest.php`, six in `tests/Feature/SitemapCanonicalTest.php`.

`cms-seo`'s `SitemapController` is the same filename and the opposite design: `final readonly`,
`__invoke`, aggregating `SitemapRegistryInterface->providers()`, each yielding
`SitemapUrl(loc, lastModified, changeFrequency)`. `cms-pages` registers a `PageSitemapProvider`
contributing published pages.

| | Here | `cms-seo` |
| --- | --- | --- |
| Extensible by other modules | no — two hard-coded queries | yes — provider registry |
| URL host | resolved storefront's **primary domain** | `url()`, i.e. the request host |
| URL cap | 50,000, configurable | none |
| `lastmod` | `updated_at` on products | `publishedAt()` from the provider |
| `changefreq` | not emitted | per-URL on the DTO |

The catalog gives `module-cms-sitemaps` *"site/type/locale-aware indexes, exclusions, images/video/
news extensions, chunking, cache, and search-engine notification adapters"* — the mechanism. Every
URL the local controller emits is a `Product` or `ProductCategory` URL. Moving the controller as
written would carry `ChannelResolver`, `Product` and `ProductCategory` across a product boundary, and
the module contract in `projects/cms/features/sitemaps.md` explicitly forbids a module depending on
*"an application's `App\` classes"*.

The correct eventual shape is the module's: ecommerce implements a `SitemapUrlProviderInterface` for
products and categories; the CMS renders. **The blocker is that `SitemapUrl` cannot express what this
repository's sitemap does.** Three behaviours would be silently lost in that translation:

1. **The primary-domain canonical root.** Six of the nine tests exist for it — a storefront answers on
   an apex, a `www`, a merchant domain and a platform subdomain, and `url()` builds from whichever
   the crawler used, publishing duplicate content in the one file whose job is telling the crawler
   what to index. `cms-seo` has no equivalent concept and `SitemapUrl` has no field for it.
2. **The 50,000-URL cap.** The registry is unbounded. A 60,000-product catalogue would publish a
   sitemap that a crawler is entitled to ignore entirely.
3. **`lastmod` from `updated_at`.** `SitemapUrl::lastModified` can carry this; it is the one of the
   three that survives unchanged.

Points 1 and 2 require a change to the `cms-contracts` DTO **before** this move is possible. That is
a finding, not a checklist item, and it is the single largest piece of upstream work this document
identifies.

### 3.5 Contact form — contested, and neither claimant fits

What is here: `GET /contact` renders a Blade view; `POST /contact` is throttled `5,1` in
`routes/web.php`. `ContactController::send()` checks a honeypot on `website` **first and
deliberately**, answering exactly as a successful send would rather than validating it and naming
the field that caught the bot. It then validates `name` (required, max 100), `email` (required, max
254, **`email:rfc`** — chosen because it rejects the CRLF payload that would otherwise become header
injection through the `Reply-To`), `subject` (nullable, max 150) and `message` (required, min 10, max
5000, with a custom message). It mails `ContactMessage` to `GeneralSettings->site_email`, keeping the
app as `From` and the visitor as `Reply-To` so the mail does not fail SPF/DKIM. **Nothing is
persisted.** Seven tests in `tests/Feature/ContactFormTest.php`, including the honeypot, the throttle
and the header injection.

The three claimants, from `liberusoftware/documentation`:

- CMS `form-builder`: *"Typed/conditional multi-step forms, reusable fields, uploads, validation,
  calculations, drafts, confirmations, and embedding."*
- CRM `forms-and-surveys`: *"Drag-and-drop schemas, conditional fields, progressive profiling,
  validation, spam controls, consent, hidden attribution, embedding, submissions, and follow-up."*
- CRM `lead-capture`: *"Leads inbox, manual/import/API capture, forms, surveys, QR codes, chat,
  calls, advertisements, events, referrals, and source metadata."*

**The local contact form is none of these.** It does not build forms — there is no schema. It captures
no lead — no row is written. It has no submissions inbox, no consent, no attribution. It is a single
hard-coded form that relays an email. Whichever module wins the catalog argument, the code that
arrives is not a form builder and not a lead capturer.

Two working implementations exist to land on, and they are genuinely different features:

**`cms-forms`** — `cms_forms(name, slug, fields json, team_id)` with `FormField` value objects and a
`FormFieldType` enum; `cms_form_submissions(form_id, data json, meta json{ip, user_agent}, team_id)`;
`FormSubmissionController` validates against the form's own schema, stores, and dispatches
`FormSubmitted` on the event bus; honeypot field name from `config('cms-forms.honeypot')`, default
`_hp`; route throttled by a named limiter `throttle:cms-forms` with `config('cms-forms.rate_limit')`
defaulting to 10/min. Tested by `Feature/Forms/FormSubmissionTest.php` and two Filament resource
tests.

**CRM `LeadForm`** — `POST /forms/{leadForm}/submit`, public. Builds validation rules dynamically
from the stored `fields`, stripping `regex`/`not_regex` and any rule containing a backslash, falling
back to `['required']` if everything is stripped. Creates or updates a `Contact` keyed on email,
creates a `Lead` with `source = 'landing_page'`, scores it via `LeadScoringService`, and dispatches
`ExecuteWorkflowAction` for every workflow triggered on `lead_created`. Eight tests. **It sends no
mail.**

Note also that `crm-laravel` ships `resources/views/components/contact-form.blade.php` posting to
`/contact/send` — a route that **does not exist** in any of its five route files, with no controller
and no Mailable. The CRM does not have a contact form; it has an orphaned component. The contest is
between catalog rows, not between implementations.

**If this lands on `cms-forms`, five behaviours must be carried across explicitly. Each is a test
that would otherwise cease to exist:**

1. **The email itself.** `cms-forms` stores and announces; it never mails. `GeneralSettings->site_email`
   receiving the message *is* the feature. A `FormSubmitted` listener would have to be written, and
   it does not exist on either side today.
2. **`email:rfc`.** `SubmissionValidator` builds rules from `FormFieldType::rule()`, and
   `FormFieldType::Email` maps to plain **`'email'`**, not `'email:rfc'`. If the mail is reintroduced
   as a listener with that rule, the CRLF header-injection hole reopens silently.
   `ContactFormTest::header_injection_via_the_email_field_is_rejected` is the test that no longer
   exists.
3. **The rate limit halves in the wrong direction.** `throttle:5,1` here against a default of 10/min
   there, on a public spam-relay target.
4. **`min:10` on the message** and its custom copy (*"Please add a little more detail so we can
   help."*) — `SubmissionValidator` has no per-field minimum.
5. **`Reply-To` rather than `From`.** An SPF/DKIM decision documented in the Mailable and encoded
   nowhere in `cms-forms`, which has no mail path to encode it in.

The honeypot survives — both implementations answer a filled honeypot with success and discard the
input.

---

## 4. What the move costs

Ordered. Steps 1–3 are not this repository's to perform and block everything after them.

1. **Confirm the vendor.** ADR 0004 gives `liberusoftware/cms-pages`; `cms-laravel` currently builds
   against `liberu-cms/cms-pages`. One of the two changes, and the CMS side is the one out of step
   with the fleet.
2. **Publish the owning module.** `packages/liberu-cms/cms-pages`, `cms-seo` and `cms-forms` move out
   of `cms-laravel`'s tree into the `module-cms-*` repositories that already bear their names, gain a
   `composer.json` and a tag, and land on Packagist. Until then there is nothing to
   `composer require`.
3. **Extend `SitemapUrl` in `cms-contracts`** with a per-site canonical root and give the registry a
   URL cap — §3.4. Without this, moving the sitemap is a regression, not a migration.
4. **Delete the Article and FAQ cluster** (§3.2). Independent of steps 1–3: five files, two empty
   factories, one three-line Blade view, one twelve-method policy. Check no seeder or role assignment
   references the `*_article` Shield permissions before deleting the policy.
5. **Decide `seo_settings`' fate** (§3.3) — connect it or delete it. Also independent of 1–3, and it
   should be settled before the SEO cluster moves anywhere, because neither answer is "move it".
6. **Write the ADR for the Page merge.** It records the two losses in §3.1: `cascadeOnDelete` →
   `nullOnDelete`, and `getStatuses()`. ADR 0008's rule applies — the merge happens before extraction,
   not after, so it stays an in-host change.
7. **Move Page.** Require the published `cms-pages`; repoint `App\Filament\Admin\Resources\Pages` at
   `Liberu\Cms\Pages\Models\Page`; write the `pages` → `cms_pages` copy migration (`parent_page_id` →
   `parent_id`, and every row needs a `template` default); drop `pages`. `cms-laravel` has already
   run this exact sequence and its `docs/OPEN-QUESTIONS.md` names the data-copy step as the one
   thing fresh installs skip and existing deployments must not.
8. **Move the sitemap** — but as an inversion, not a relocation. Ecommerce implements
   `SitemapUrlProviderInterface` for `Product` and `ProductCategory`; `SitemapController` and
   `resources/views/sitemap/` are deleted; the nine tests are rewritten against the provider, and the
   six canonical-host tests must still pass against whatever step 3 produced.
9. **Move the contact form last, or not at all.** Its five behaviours (§3.5) are requirements to
   restate, not files to copy. If no form-builder module ever ships with a mail path, the correct
   answer is that this stays: it is a storefront page, not a CMS or CRM capability.

Steps 4 and 5 are available now and are the only ones that are.

---

## 5. What could not be determined

- **Whether the `liberu-cms/cms-*` packages are intended to become the `liberusoftware/module-cms-*`
  repositories, or are a parallel effort.** Both were pushed within a day of each other
  (`cms-laravel` 2026-08-06, the module placeholders 2026-08-05). Nothing read states the relationship.
- **Whether `cms-laravel`'s `main` is releasable.** Its only tag, `v13.0.0`, was published
  2026-05-30, before the module work landed. What is on `main` today has never been released.
- **The quality of the module code beyond what is quoted.** It was read, not run. This repository has
  no `vendor/` and could not execute either suite.
- **Whether the CMS-catalog capabilities in `crm-laravel` (knowledge base, form builder) are
  deliberate or accidental.** No ADR or issue in either repository was found addressing it. It is
  reported here as an observation for whoever owns the boundary, not as a defect claim.
- **Whether the local `*_article` Shield permissions are seeded or assigned anywhere.** Not searched
  exhaustively; step 4 above makes it a precondition rather than an assumption.

[#942]: https://github.com/liberusoftware/ecommerce-laravel/issues/942
