# Documentation index

What is in `docs/`, what each document is for, and whether you can trust it.

That last column is the reason this page exists. This directory holds documents with genuinely
different lifespans — two that are edited continuously, one that is frozen on purpose, eleven
immutable decision records, four dated research dumps, and a handful of feature documents of varying
age — and nothing on the page you are reading distinguishes them by filename. Reading a frozen
snapshot as current, or editing it because it looks stale, both cause real damage. So each entry
below says which kind it is.

Where currency could not be established by reading the code, the entry says **status unaudited**
rather than guessing.

---

## Living documents

Edited as the work lands. If your change makes one of these wrong, fix it in the same pull request.

| Document | What it is |
| --- | --- |
| [`MIGRATION_PLAN.md`](./MIGRATION_PLAN.md) | The ordered plan from the state recorded in `CONFORMANCE.md` to the shape `MODULE_DEVELOPMENT.md` describes. Sequences the structural work — the enforcement layer, the packaging mechanism, the tenancy fix, four data migrations, the extraction order. It does not build the 105 modules; the execution epics do that against it. This is also where corrections to the snapshot belong. |
| [`MODULE_DEVELOPMENT.md`](./MODULE_DEVELOPMENT.md) | How to build, test, promote and release an Ecommerce module. The document that outlives the migration: `CONFORMANCE.md` describes a codebase that starts changing the day wave 0 lands and `MIGRATION_PLAN.md` is finished when the last module ships, but this one stays useful. Deviations from the Liberu standards are marked **⚠ deviation** at the point they apply. |

## Frozen snapshot

| Document | What it is |
| --- | --- |
| [`CONFORMANCE.md`](./CONFORMANCE.md) | Where this repository stood against the Liberu standards, measured 2026-08-08 against commit `2d1024c`. **Never revised.** Every number counts the tree at that commit; it is superseded finding by finding by the execution epics, and the gap between it and `MIGRATION_PLAN.md` is the progress record. Correcting it in place erases that record — it has happened once and was reverted. Read it as history, and write corrections into the plan. |

## Decisions

| Document | What it is |
| --- | --- |
| [`adr/`](./adr/) | Eleven architecture decision records, plus a [README](./adr/README.md) that carries the index and the one rule for adding another: an ADR exists for a deviation from the Liberu documentation or a deliberate loss of behaviour, and nothing else. ADRs are immutable — a decision is superseded by a new record, not rewritten. Current as a set: the README's table is maintained. |

## Running the thing

| Document | What it is | Status |
| --- | --- | --- |
| [`INSTALLATION.md`](./INSTALLATION.md) | Getting the application running locally, manually, or under Docker: requirements, the interactive `setup.sh` path, Stripe configuration, DropXL setup. | Current. The path it documents is the one `install.yml` exercises on every pull request. |
| [`OPERATIONS.md`](./OPERATIONS.md) | Running an installed instance: every registered console command and what it does, the admin panels, queues, troubleshooting. | Current. The command table was generated from `app/Console/Commands/`. |

## Feature documentation

| Document | What it is | Status |
| --- | --- | --- |
| [`ANALYTICS.md`](./ANALYTICS.md) | The admin analytics dashboard — what it shows, how to read it, how to extend it. Merged from the former `ANALYTICS.md` and `ANALYTICS_GUIDE.md`, which documented the same feature twice. | Written against the tree as of 2026-08-08 and accurate then. Not re-checked since. |
| [`API_COLLECTIONS.md`](./API_COLLECTIONS.md) | Endpoint reference for the product-collections API: Sanctum authentication, request and response shapes. | **Status unaudited.** Last substantively touched before the conformance work, and it predates the API findings in [`CONFORMANCE.md`](./CONFORMANCE.md) and the [standards gap audit](./research/standards-gap-audit.md) — no versioning, no API Resources, raw model attributes in responses. The routes it documents do exist (`routes/api.php`, `Api/CollectionController`); whether every field and status code it lists is still right has not been verified. |

## Held debt

These four describe code that ships today but is not staying. They are kept because deleting the
description would not delete the duplication, and the next reader would rediscover it.

| Document | What it is |
| --- | --- |
| [`CHAT_SYSTEM.md`](./CHAT_SYSTEM.md) | Feature overview of the live-chat system — widget, agent console, analytics. |
| [`CHAT_ARCHITECTURE_DIAGRAM.md`](./CHAT_ARCHITECTURE_DIAGRAM.md) | Component and message-flow diagrams for the same system. |
| [`CHAT_IMPLEMENTATION_SUMMARY.md`](./CHAT_IMPLEMENTATION_SUMMARY.md) | What was built: migrations, models, services, components. |
| [`CHAT_QUICK_REFERENCE.md`](./CHAT_QUICK_REFERENCE.md) | Developer quick reference — commands, endpoints, configuration. |

All four carry the same banner and it is the important part: live chat belongs to the **CRM** product
(`chat-and-bots`), and `liberusoftware/crm-laravel` already ships its own chat stack. This is not code
waiting for a home, it is a second implementation of one that exists. Which stack survives is a
cross-repository merge decision, tracked as
[#943](https://github.com/liberusoftware/ecommerce-laravel/issues/943) and read alongside
[`CONFORMANCE.md` §4.2](./CONFORMANCE.md#42-the-98-unmappable-files-were-an-artifact). Describing the
duplicate accurately is in scope; describing it as the plan of record is not.

A fifth document sits alongside them and does the opposite job. The four above describe the duplicate;
this one prices the merge out of it.

| Document | What it is |
| --- | --- |
| [`reconciliation/crm-chat-stack.md`](./reconciliation/crm-chat-stack.md) | The two chat stacks reconciled column by column against `crm-laravel` `v2.0.1`: the entity map (`ChatConversation`↔`LiveChat`; two different tables both called `chat_messages`; `chat_analytics` and the chatbots with no counterpart at all), what each side loses if the other is adopted wholesale, and the ordered checklist for doing the merge when the module package exists. Written for the same reason as [ADR-0008](./adr/0008-reviews-and-ratings-merge.md) — the behaviour a merge drops is invisible afterwards. **Living until the merge lands, then deleted with the code it describes.** |

## Partly superseded

| Document | What it is |
| --- | --- |
| [`STABLE_RELEASE_TASKS.md`](./STABLE_RELEASE_TASKS.md) | A product-readiness backlog: 18 feature workstreams and a cross-cutting checklist. `MIGRATION_PLAN.md` covers structural conformance and does not replace it — the two cover different work. Its own banner names the three workstreams that **are** superseded (14 tenancy, 15 modules, 18 installer and Docker) and where each now lives. The other 15 stand. |

## Research

Point-in-time investigations, all measured against commit `2d1024c` and merged from the throwaway
branches they were written on. **These are not maintained, and that is what makes them useful:** when
a finding in `CONFORMANCE.md` is disputed, this is the evidence it rested on. Treat them the same way
as the snapshot — superseded, never revised.

[`research/README.md`](./research/README.md) is the index and carries the caveat that applies across
all four. In short:

| Document | Question it answered |
| --- | --- |
| [`research/standards-gap-audit.md`](./research/standards-gap-audit.md) | Where does this repository violate the 36 Liberu standards documents? One section per standard, with severity and a mechanical-versus-structural class. The 29-row summary table at the end is the ranked finding list the migration works through. |
| [`research/app-to-module-inventory.md`](./research/app-to-module-inventory.md) | Which `ECOMMERCE.md` module does each part of `app/` belong to? All 906 files bucketed. Its "98 unmappable files" figure is an artifact of the brief — `CONFORMANCE.md` §4.2 has the corrected reading. |
| [`research/foundation-module-matrix.md`](./research/foundation-module-matrix.md) | Which of the 28 foundation modules does this repository need, and what does each replace? |
| [`research/packaging-mechanism.md`](./research/packaging-mechanism.md) | How do `modules/`, `composer-installer` and `module-manager` actually work in `boilerplate-laravel`? |

Note that parts of the gap audit have been closed since it was written — it lists `LICENSE`, the ADR
directory and this index among the missing, and all three now exist. The audit is not edited to say
so; that is the same rule as the snapshot.

## Agent configuration

| Document | What it is |
| --- | --- |
| [`agents/domain.md`](./agents/domain.md) | How the engineering skills should consume this repository's domain documentation — read `CONTEXT.md` and the relevant ADRs before exploring. |
| [`agents/issue-tracker.md`](./agents/issue-tracker.md) | Issues live on GitHub; the `gh` invocations to read and write them. |
| [`agents/triage-labels.md`](./agents/triage-labels.md) | Maps the skills' five canonical triage roles onto this tracker's label strings. |

These support [`AGENTS.md`](../AGENTS.md) at the repository root, which is the source of truth for
agent configuration.

---

## Outside `docs/`

| Document | What it is |
| --- | --- |
| [`README.md`](../README.md) | Project identity, features, badges and the quick start. |
| [`CONTRIBUTING.md`](../CONTRIBUTING.md) | The quality gates, the five workflows, the ADR rule, and the two documents with opposite editing rules. |
| [`SECURITY.md`](../SECURITY.md) | How to report a vulnerability privately, and what is in scope. |
| [`CONTEXT.md`](../CONTEXT.md) | Domain glossary — merchant, team, store, channel, tenant. |
| [`AGENTS.md`](../AGENTS.md) | Agent configuration for this repository. |

There is no `CHANGELOG.md`. Nothing has been released under this vendor name yet, so `git log` is the
record of what changed; [`CONTRIBUTING.md`](../CONTRIBUTING.md) explains the reasoning and what will
change when the first gated release ships.
