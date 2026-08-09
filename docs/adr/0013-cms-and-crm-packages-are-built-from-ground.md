# CMS and CRM packages are built from ground; the local code is deleted at cutover, not moved

**Status**: accepted — 2026-08-09. Supersedes the deferral recorded in [#932](https://github.com/liberusoftware/ecommerce-laravel/issues/932) (D1) for the CMS and CRM clusters.

D1 said: code owned by a product whose repository does not exist yet **stays in the host as named debt**, rather than being extracted into module packages this effort has no mandate to found. [#942](https://github.com/liberusoftware/ecommerce-laravel/issues/942) and [#943](https://github.com/liberusoftware/ecommerce-laravel/issues/943) were the debt registers, each with the same exit criterion: *the repository exists and the owning module is published — the code moves then.*

**The packages are now being built from ground.** They are not extractions of this repository's code, and each module has its own issue. So the exit criterion describes a move that will not happen.

## Why the deferral had to be re-decided rather than re-applied

Both premises expired, in opposite directions, and neither expiry was visible from the issues themselves.

- **CRM.** `crm-laravel` `2.0.1` already carries `LiveChat`, `ChatMessage`, `Chatbot` and `ChatbotInteraction`. The local stack stopped being an orphan waiting for a home and became a **second implementation** of one that exists.
- **CMS.** `cms-laravel` holds **21 committed path packages** under `packages/liberu-cms/` — `cms-pages`, `cms-posts`, `cms-seo`, `cms-forms` and the rest. The implementation exists; only the tags do not.

A deferral whose premise has expired needs re-deciding. Re-applying it is how debt outlives the reason for it.

## The decision

1. **The fresh packages are authoritative.** Neither `cms-laravel`'s nor this repository's implementation is a source to copy from.
2. **This repository's CMS and CRM code is deleted at cutover, not moved.** When a module package covers a cluster, the local code goes in the same change that adopts it.
3. **The two reconciliation documents change role, and keep their value.** They were written as *how to move this*. They are now *what a fresh package must cover* — the requirements record, which is the more useful of the two readings and the reason closing the trackers loses nothing:
   - [`docs/reconciliation/cms-owned-code.md`](../reconciliation/cms-owned-code.md)
   - [`docs/reconciliation/crm-chat-stack.md`](../reconciliation/crm-chat-stack.md)
4. **#942, #943 and [#961](https://github.com/liberusoftware/ecommerce-laravel/issues/961) close.** The first two are superseded by this ADR; the third is a duplicate index, below.

## What the requirements record has to carry, because a fresh build will not rediscover it

These are the findings that cost the most to establish and are the easiest to lose. They are stated in full in the two documents; they are listed here so that closing the issues does not bury them.

- **`user_id` means the opposite thing on each side of the chat stacks.** Here it is the customer and `agent_id` is the agent; in `crm-laravel` it is the agent and `contact_id` is the customer. A column-name mapping files every customer as the agent.
- **Read receipts, `system` sender, rate limits, the IDOR guard and time-to-first-response** exist only in this repository's chat implementation. A fresh package that reads only `crm-laravel` will not have them, and 49 test cases here specify them.
- **`cms-forms` maps its email field to `'email'`, not `'email:rfc'`.** Adopting it as-is silently reopens a header-injection hole this repository already closed.
- **The sitemap is not CMS-owned as written.** `SitemapController` holds a primary-domain canonical root — 6 of its 9 tests — and a 50,000-URL cap. `cms-contracts`' `SitemapUrl` can express neither, so a fresh CMS package that models the sitemap on that contract regresses it.
- **`Page` adoption costs two things**: `cascadeOnDelete` becomes `nullOnDelete`, and `getStatuses()` has no counterpart.

## On closing #961

#961 tracked 23 upstream issues across nine repositories. All 23 are still open, and closing the tracker does not close them — **it stops maintaining a second copy of a list.** Each deviation's ADR already links its own upstream issue, and [`CONFORMANCE.md` chapter 8](../CONFORMANCE.md#8-upstream-gaps) holds the full table with the reasoning. A tracker that duplicates two authoritative places is a third place to forget to update.

Two things had to be handled before it could close, rather than being allowed to vanish with it:

- **One of the 23 is now withdrawn.** [`documentation` #20](https://github.com/liberusoftware/documentation/issues/20) argued that `THEMES.md` §3.2 should permit gitignoring `/themes`. [#972](https://github.com/liberusoftware/ecommerce-laravel/issues/972) withdrew ADR 0010, so this repository no longer wants that change. Recorded in [`adr/README.md`](./README.md); the upstream issue is another repository's to close.
- **One item was never filed anywhere.** The **team resolver** that `organizations-teams` needs for [ADR 0006](./0006-late-bound-host-model-resolution.md) is a contribution rather than a defect report, so it had no upstream issue and existed only inside #961. It is a **wave 1 prerequisite** — commerce modules cannot resolve a team until it lands — and it is now recorded in `MIGRATION_PLAN.md`'s wave 1 section, which is where a prerequisite belongs.

## Consequences

The `article_*` Shield permissions left behind by [ADR 0012](./0012-deleting-the-empty-cms-scaffolds.md) stay unused, unchanged by this.

The local CMS and CRM code keeps running until its module lands. Nothing is deleted by this ADR — it changes what happens *at* cutover, and cutover is each module's own issue.
