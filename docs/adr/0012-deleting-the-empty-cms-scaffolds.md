# Deleting the empty CMS scaffolds rather than moving them

**Status**: accepted — landed 2026-08-09

[#942](https://github.com/liberusoftware/ecommerce-laravel/issues/942) holds three clusters of CMS-owned code as named debt, to move when a CMS module package is published. [`docs/reconciliation/cms-owned-code.md`](../reconciliation/cms-owned-code.md) read each cluster against the repository that will own it. Two of them turned out to have nothing in them.

They are deleted here. This ADR exists because deleting something that appears in a product catalogue is a **deliberate loss of a documented capability**, and a future reader finding `article_*` permissions in the Shield seeder with no `Article` model deserves better than `git log`.

## Article and FAQ

`articles` was:

```php
Schema::create('articles', function (Blueprint $table) {
    $table->id();
    $table->timestamps();
});
```

No title. No body. No slug, author, or published-at. `ArticleFactory::definition()` returned `[]`; `ArticleResource`'s form and table were `[]`; the FAQ page rendered a Blade view whose entire body was one empty `<x-filament-panels::page>`. A twelve-method `ArticlePolicy` guarded a model with no content, and — this is the part worth noticing — **that policy was real security work**. `ArticleResource` shipped with no policy at all, so Filament's `get_authorization_response()` returned `allow()` and every team member had full CRUD. The fix was correct and it was guarding nothing.

Moving this to `cms-laravel` would move two columns and a name. `cms-posts` and `cms-content` already implement the capability properly. There is nothing here to reconcile, and nothing to lose.

## `seo_settings`

Eighteen columns, six tests, and **zero readers**. The only reference in the entire application was `Product::seoSettings()`, a `morphOne` whose only callers were the tests of the model it pointed at. The storefront's `<head>` renders from `products.meta_*` and always has.

This is the harder of the two, because eighteen columns look like a feature. They are not: a table that nothing reads is not an SEO capability, it is a schema that describes one. `cms-seo` has no per-record settings table either, so moving this would not have landed it anywhere.

**What is genuinely lost**: nothing at runtime, and the *intent* that per-record SEO overrides should exist. That intent is real and this ADR is where it is recorded, because deleting the table deletes the only evidence anybody wanted it. When per-record SEO is wanted, it should arrive with a reader.

## What is deliberately not deleted

- **`Page`** — a real duplicate with the module ahead, and a genuine move once `cms-pages` publishes. It stays.
- **The sitemap** — `SitemapController` is not CMS-owned as written. It holds a primary-domain canonical root (6 of its 9 tests) and a 50,000-URL cap that `cms-contracts`' `SitemapUrl` cannot express. Inverting it to the module's provider registry today would regress it.
- **The contact form** — contested between CMS `form-builder` and CRM `forms-and-surveys`, and it matches neither. `cms-forms` maps its email field to `'email'` rather than `'email:rfc'`, so landing there as-is would silently reopen a header-injection hole this repository already closed.

## Consequences

`article_*` permissions remain in the Shield seeder and are now unused. They are left alone: removing them is a permission-set change that wants its own reasoning, and an unused permission grants nothing. `AppPanelAuthorizationTest`'s docblock keeps Article's name so a reader who meets those entries finds out why.

#942 shrinks to `Page` and the contact form. It does not close — the exit criterion is a published module package, and `cms-laravel`'s twenty-one packages are committed path packages with no tags, reachable by nobody.
