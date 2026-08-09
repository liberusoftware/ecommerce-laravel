# Reconciling the two live-chat stacks

**Status**: living until the merge lands, then deleted with the code it describes.
**Measured against** `ecommerce-laravel` @ `1ae0fc0` and `liberusoftware/crm-laravel` `main` @ `b105608`
(released as `v2.0.1`). Re-verify before acting on it — see step 2 of the checklist.

[Issue #943](https://github.com/liberusoftware/ecommerce-laravel/issues/943) defers a live-support-chat
stack that belongs to CRM, behind the exit criterion *"the CRM product repository exists and the owning
module is published."* Neither `liberusoftware/module-chat-and-bots` nor `module-crm-core` is on
Packagist, so the code cannot move yet. This document is what can be done in the meantime.

It exists for the reason [`adr/0008-reviews-and-ratings-merge.md`](../adr/0008-reviews-and-ratings-merge.md)
exists. Merging two independently written implementations of one idea silently drops behaviour, and the
dropped behaviour is invisible afterwards: **the tests that pass are the tests that no longer exist.**
ADR-0008 named two load-bearing details — a moderation column and a customer backfill — that a schema
cleanup would have deleted without anyone noticing. The same job, done ahead of time, is below.

**This is not a move. It is a merge.** `crm-laravel` already ships its own chat stack. The names overlap
without matching, and one of them collides outright.

---

## 1. The two implementations

### 1.1 Entity map

| Concept | `ecommerce-laravel` | `crm-laravel` | Relationship |
| --- | --- | --- | --- |
| A conversation | `chat_conversations` / `App\Models\ChatConversation` | `live_chats` / `App\Models\LiveChat` | Same concept, different name, overlapping-but-not-equal columns |
| A message | `chat_messages` / `App\Models\ChatMessage` | `chat_messages` / `App\Models\ChatMessage` | **Same table name and same class name. Different table.** |
| Per-conversation metrics | `chat_analytics` / `App\Models\ChatAnalytics` | — (aggregates computed on demand; `rating`/`feedback` live on `live_chats`) | No counterpart |
| A bot | — | `chatbots` / `Chatbot`, `chatbot_interactions` / `ChatbotInteraction` | No counterpart |
| Service | `App\Services\ChatService` | `App\Services\LiveChatService` | Same role, disjoint method names |

Both repositories also have an unrelated `messages` table/`Message` model. CRM's
`2026_07_06_150000_create_chat_messages_table.php` carries a docblock saying exactly why live chat got
its own table rather than reusing it: *"the general `messages` table is a different domain
(channel/thread/ticket/unified-inbox)."* That reasoning survives the merge.

### 1.2 Conversation, column by column

`chat_conversations` (here) against `live_chats` (CRM).

| Here | There | Note |
| --- | --- | --- |
| `id` | `id` | |
| `session_id` string **unique** | `visitor_id` string, indexed, **not unique** | Different roles. `session_id` is `Str::uuid()` and is the security handle (§3.1). `visitor_id` is `uniqid('visitor_')` — time-seeded, guessable, and deliberately repeatable across a visitor's chats. |
| `user_id` → `users` — **the customer** | `contact_id` → `contacts` — the customer | The customer maps across the *name* boundary, not the column boundary. |
| `agent_id` → `users` — the agent | `user_id` → `users` — **the agent** | **`user_id` means the opposite thing on each side.** A column-name mapping files every customer as the agent. This is the single most dangerous row in this document. |
| `status` enum `queued\|active\|closed` | `status` string, constants `waiting\|active\|ended\|missed` | `queued`→`waiting`, `closed`→`ended`, and `missed` has no local equivalent. |
| `started_at` | `started_at` | **Not the same instant.** Here it is set when an agent is assigned. There it is set when the chat is created. |
| `ended_at` | `ended_at` | Same. |
| `queue_position` int | — | CRM orders `getWaitingChats()` by `started_at asc`. |
| `customer_name` | `visitor_name` (defaults to `'Anonymous'`) | |
| `customer_email` | `visitor_email` | |
| — | `contact_id` | Resolved at start by blind index, at end by `firstOrCreate`. |
| — | `visitor_ip`, `visitor_user_agent`, `visitor_location`, `page_url`, `referrer` | |
| — | `rating` int, `feedback` text | Here these live on `chat_analytics`. |
| — | `metadata` json | Holds the agent-transfer audit trail. |
| — | `team_id` | Added by `2026_07_06_000001_add_team_id_to_tenant_scoped_tables`. |
| Indexes: `status`, `agent_id`, `created_at` | Indexes: `(status, started_at)`, `visitor_id`, `contact_id` | |
| No tenant scoping | `use IsTenantModel` | §3.2 |

### 1.3 Message: the name collision

Both tables are called `chat_messages`. They cannot coexist.

| Here | There |
| --- | --- |
| `conversation_id` → `chat_conversations`, cascade | `chat_id` → `live_chats`, cascade |
| `user_id` → `users`, null on delete | `sender_id` → `users`, null on delete |
| `sender_type` enum `customer\|agent\|**system**` | `sender` string, documented `'agent' \| 'visitor'` |
| `message` text | `content` text |
| `is_read` boolean default false | — |
| — | `team_id` → `teams`, null on delete |
| Indexes `conversation_id`, `(conversation_id, created_at)` | No indexes beyond the foreign keys |

Two differences carry behaviour rather than naming:

- **`system` has no counterpart.** `ChatService` writes two system messages — *"Thank you for contacting
  us. An agent will be with you shortly."* on creation and *"An agent has joined the chat."* on
  assignment. On the CRM side there is no sender value they can be written as.
- **`is_read` has no counterpart**, and the entire read-receipt path rests on it:
  `ChatMessage::scopeUnread()`, `ChatService::markMessagesAsRead()`, and the reader-type branch in
  `ChatController::getMessages()` that marks the *other* party's messages read on fetch.

### 1.4 Analytics and bots: no counterpart on either side

`chat_analytics` (here) stores, per conversation: `response_time_seconds`, `resolution_time_seconds`,
`message_count`, `agent_message_count`, `customer_message_count`, `satisfaction_rating`,
`satisfaction_feedback`. `ChatService` maintains it incrementally.

CRM has no equivalent table. `LiveChatService::getChatAnalytics()` computes `total_chats`,
`completed_chats`, `missed_chats`, `average_duration`, `average_rating` and `contacts_created` on demand
from `live_chats`, and `LiveChat::getDurationAttribute()` derives duration from `started_at`/`ended_at`.

`chatbots` and `chatbot_interactions` (CRM) have no counterpart here at all. `chatbots` holds
`welcome_message`, `fallback_message`, `is_active` and three JSON columns (`trigger_rules`, `flow`,
`integrations`); `chatbot_interactions` holds the transcript, `current_step`, `completed` and
`converted_to_lead`. They are the other half of what the `chat-and-bots` module is named for.

---

## 2. What each side has that the other lacks

### 2.1 Only here

| | Capability or accident |
| --- | --- |
| **Session-bound authorization on the customer endpoints.** `ChatController::authorizeConversationAccess()` — conversations are addressable by sequential `id`, so access needs either an admin role or the conversation's `session_id` present in the browser session's `chat_conversations` list. `ChatIdorTest` is six tests long. | **Capability, security-class.** CRM has no HTTP surface and so has never had to solve this. When it grows one it will reach for `visitor_id`, which is `uniqid()` — guessable. The requirement matters more than the code. |
| **Rate limits on the public writes.** `throttle:15,1` on start/close/rating, `throttle:30,1` on message, in `routes/web.php`. `RateLimitAbuseTest::test_chat_start_is_rate_limited` proves it. | **Capability.** |
| **The whole HTTP surface** — 10 routes and `ChatController`. | **Capability.** CRM has zero chat routes (§5). |
| **The Filament admin surface** — `ChatConversationResource` (status badges, `messages_count`, status and agent filters), `ViewChatConversation` (an infolist rendering the full transcript plus the analytics block), `ListChatConversations`, `ChatAgentDashboard` (queue list, claim-to-me, five stats), `ChatStatsWidget` (five stats, auto-discovered by `discoverWidgets`), and both Blade views. | **Capability, and the largest single thing at risk.** `crm-laravel` has **no Filament resource for `LiveChat`, `Chatbot` or `ChatbotInteraction`**. |
| **The customer-facing widget** — `App\Livewire\ChatWidget` and the 270-line `chat-widget.blade.php`, mounted globally from `layouts/app.blade.php`. | **Capability, with a caveat.** The Livewire class never calls `ChatService`; it only `dispatch()`es browser events (`chat-conversation-started`, `chat-send-message`, `chat-submit-rating`). The real wiring is JS in the Blade view calling the HTTP routes. Port them together or port neither. |
| **`response_time_seconds`** — time to first agent response, captured in `ChatService::assignAgent()`. | **Capability, and unreconstructible.** CRM's `assignChat()` writes no timestamp, and CRM's `started_at` is set at *creation*, so the queue wait cannot be derived from the CRM schema after the fact. |
| **`resolution_time_seconds`** | Capability, but derivable on the CRM side once `started_at` semantics are fixed. |
| **`message_count`, `agent_message_count`, `customer_message_count`** | **Accident.** Denormalisation; recomputable with a `COUNT`. |
| **`queue_position`** | **Accident.** CRM gets the same ordering from `started_at asc` without a column to maintain — and the local column is never renumbered on assign or close, so it only ever grows. |
| **`session_id` unique + UUID** | **Capability** — see the first row. |
| **49 test cases** — `ChatServiceTest` (19), `ChatConversationModelTest` (9), `ChatControllerTest` (14), `ChatIdorTest` (6), plus one case in `RateLimitAbuseTest`. | **Capability.** CRM has nine, all on `LiveChatService`. |

### 2.2 Only there

| | Capability or accident |
| --- | --- |
| **Tenant scoping.** `IsTenantModel` plus `team_id` on all four CRM chat tables. | **Capability — and it exposes a live defect here.** `ChatConversationResource` sets `$isScopedToTenant = false` with an honest comment: `chat_conversations` has no `team_id`, so Filament would throw rather than isolate. But the admin panel *is* tenant-scoped (`->tenant(Team::class)`), so today every team's admin sees every team's conversations and the customer PII on them. Carrying the local stack forward wholesale carries that leak into the module. |
| **Contact resolution.** `contact_id`, populated at start via blind index (`Contact::where('email_hash', Contact::hashEmail($email))`) and at end via `firstOrCreate`. | **Capability, and the reason chat is CRM's at all** — a chat creates or enriches a Contact. Here, a conversation links to a `User` or to nothing. |
| **Visitor context** — ip, user agent, location, page URL, referrer. | **Capability.** Support triage and lead attribution. |
| **Agent transfer**, with an audit trail appended to `metadata.transfers[]`. | **Capability.** No local equivalent. |
| **`missed` status.** | **Capability.** The local three-state enum cannot distinguish a chat nobody picked up from one that was served and closed. |
| **Chatbots** — `chatbots` + `chatbot_interactions`, including `converted_to_lead`. | **Capability.** |
| **Encryption-at-rest already accounts for chat.** CRM's `2026-07-10-pii-encrypt-at-rest-design` names `LiveChatService` as one of two call sites rewritten onto the blind index. | **Capability** — a compliance posture the local stack has never been through. |
| **`declare(strict_types=1)`, a PHPStan baseline covering these files, a `LiveChatFactory`.** | **Accident**, but note the local stack has no factory for any chat model; its tests build rows by hand. |

---

## 3. What is lost by adopting either side wholesale

Stated as losses.

### 3.1 Adopt CRM's stack, delete this one

- **Live chat stops being reachable by a customer.** There is no widget, no route and no controller on
  the CRM side. The storefront loses its chat entry point entirely.
- **The admin loses every chat screen** — conversation list, transcript view, agent queue dashboard,
  stats tiles. `crm-laravel` has no Filament chat surface to replace them with.
- **The session-bound authorization guard is deleted**, along with its six tests, and the handle its
  replacement would key on (`visitor_id`, from `uniqid()`) is weaker than the one it protected.
- **The rate limits on public chat writes are deleted.**
- **Read receipts are deleted** — `is_read`, `scopeUnread()`, `markMessagesAsRead()`.
- **System messages are deleted.** CRM's `sender` admits no `system` value, so the welcome message and
  the "an agent has joined" message have nowhere to be written.
- **Time-to-first-response stops being recorded and cannot be backfilled.**
- **49 test cases are deleted; nine remain.**

### 3.2 Adopt this stack, delete CRM's

- **Tenant isolation is lost.** Not a gap — a live cross-team leak, promoted from host-app debt into a
  published module.
- **The link from a chat to a Contact is lost** — the reason CRM owns chat in the first place.
- **Chatbots are lost outright**, and they are half the `chat-and-bots` module by name.
- **Visitor context is lost** — ip, user agent, location, page, referrer.
- **Agent transfer and its audit trail are lost.**
- **`missed` becomes unrepresentable.**
- **The encrypt-at-rest work is undone for chat.**
- **It is a breaking schema change to a released product.** CRM ships `v2.0.1` with these tables. This
  stack has never been released under this vendor name.

### 3.3 The honest reading

Neither wholesale adoption is defensible, and they are not symmetric.

**CRM's data model is the more complete one and should survive.** It is released, tenant-scoped,
contact-linked, and it carries the bots. **This repository's surfaces are the more complete ones, and
they are the only part genuinely worth carrying**: the Filament admin (resource + two pages + dashboard
page + widget + two Blade views), the HTTP and Livewire customer path, and the two security properties
those surfaces forced into existence — session-bound authorization and throttling. Everything else local
is either denormalisation CRM can recompute (`message_count`, `queue_position`) or a column CRM already
has under a different name.

The one local **data-model** item that is neither replaceable nor an accident is
**`response_time_seconds`**. CRM's `assignChat()` records no timestamp, so time-to-first-response is not
derivable there at all. It needs a column.

---

## 4. Checklist for performing the merge

To be run when a module package exists. Preconditions first, then in order.

1. **Freeze both copies.** From the day the merge starts, changes to `app/**/Chat*` here and
   `app/**/{LiveChat,Chatbot}*` there go through the merge branch. Every day both drift, this document
   decays and the merge gets more expensive.
2. **Re-verify this document against both HEADs.** It was measured against the two commits named at the
   top. Diff both chat stacks since those points before trusting a single row of it.
3. **Adopt CRM's schema as the base** — `live_chats`, CRM's `chat_messages`, `chatbots`,
   `chatbot_interactions`, `team_id` throughout. The local `chat_messages` migration is dropped, not
   merged; the two tables share only a name.
4. **Add the four things CRM lacks**, in one migration in the module:
   `live_chats.session_token` (unique, UUID — the unguessable handle step 5 needs; `visitor_id` stays as
   the analytics identity), `live_chats.assigned_at` (so time-to-first-response is derivable),
   `chat_messages.is_read` (boolean, default false), and `chat_messages.sender` widened to admit
   `system`.
5. **Port the authorization rule before any surface.** `authorizeConversationAccess` keyed on
   `session_token`, never on the sequential id, plus the `session()->push()` on start. Port `ChatIdorTest`
   first and watch it fail.
6. **Port the throttles with the routes** — 15/min on start, close and rating, 30/min on message — and
   the rate-limit test case with them.
7. **Port the HTTP surface onto `LiveChatService`**, mapping `customer_name→visitor_name`,
   `customer_email→visitor_email`, `message→content`, `sender_type→sender`, `agent_id→user_id`, and
   **`user_id→contact_id`, never `user_id→user_id`** — the local `user_id` is the customer, CRM's is the
   agent.
8. **Port the Filament surface retargeted at `LiveChat`** — resource, both pages, agent dashboard, stats
   widget, both Blade views. **Drop `$isScopedToTenant = false`**: `LiveChat` has `team_id` and
   `IsTenantModel`, so scoping now works, and the opt-out becomes a leak rather than a workaround.
9. **Rewrite the stats against the CRM shape.** `ChatStatsWidget` and `ChatAgentDashboard::loadData()`
   read `ChatAnalytics::averageResponseTime()` and `::averageSatisfactionRating()`; those become
   derivations from `assigned_at − created_at` and `live_chats.rating`. Statuses become
   `waiting`/`active`/`ended`, plus `missed`, which the dashboard should surface — it is the one status
   an agent queue exists to prevent.
10. **Port the customer widget** — `ChatWidget` and `chat-widget.blade.php` together — and repoint the
    view's JS at the module's routes.
11. **Port every test, mapped to the new names, and require them green before deleting anything.** This
    is the step ADR-0008's rule exists for.
12. **Decide the system-message question explicitly.** Either `sender=system` rows (step 4) or drop the
    welcome and agent-joined messages. Dropping them changes what the customer sees; that is a product
    decision, not a schema one.
13. **Delete the local stack in one commit** — 11 files in `app/`, 3 migrations, 5 test files, 2 views,
    the `chat` route block in `routes/web.php`, the `@livewire('chat-widget')` line in
    `layouts/app.blade.php`, and the four `docs/CHAT_*.md` documents.
14. **Update `docs/index.md`** — its "Held debt" section and this document both stop being true at that
    commit, and this file is deleted with them.

---

## 5. What could not be determined

- **Whether CRM's chat stack is reachable by any user.** Code search across `crm-laravel` for `LiveChat`
  returns the model, the service, a factory, the migration, a test, a PHPStan baseline and two docs — no
  route, no controller, no Filament resource, no view. It may be a service with no caller. If it is,
  then "CRM's implementation is more complete" is true of the schema and false of the product, and the
  merge is closer to *move the ecommerce surfaces onto the CRM schema* than to a merge of two working
  systems. I could not rule out a caller in paths GitHub code search does not index.
- **Whether the `chat-and-bots` catalog row in `liberusoftware/documentation` specifies a schema.** I did
  not read it. If it does, it outranks both implementations and §3.3 should be re-decided against it.
- **Which repository the merged module is extracted from**, and therefore whose migration history it
  inherits. That is a `MIGRATION_PLAN.md` decision, not one this document can make.
- **Whether `live_chats` holds production rows anywhere.** This repository has none — ADR-0008 established
  that every database here is built from the migrations. CRM `v2.0.1` is released, so its tables may not
  be empty. If they are not, step 3 stops being a schema choice and becomes a data migration.
- **Rating validation parity.** Local validates `min:1|max:5` at the controller; CRM's `endChat()` takes a
  bare `?int` and validates nothing. Whether CRM tolerates out-of-range ratings today is untested on both
  sides.
