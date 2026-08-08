# Stable Release Implementation Tasks

> **Partly superseded, and kept deliberately.** This is a *product-readiness* backlog: 18 feature workstreams and a cross-cutting checklist. [`MIGRATION_PLAN.md`](./MIGRATION_PLAN.md) is *structural conformance* and does not replace it — the two cover different work.
>
> Three workstreams **are** superseded and should be read against the newer documents rather than acted on directly:
>
> - **14) Tenancy, Stores, and Locations** → [`MIGRATION_PLAN.md` wave 1.5](./MIGRATION_PLAN.md#wave-15--stores-channels-and-the-tenant-scope) and [`CONFORMANCE.md` §6](./CONFORMANCE.md#6-security-findings). Its acceptance criterion *"Tenant A cannot access or mutate Tenant B data"* is currently **false on four surfaces**, which is more specific than this document knew.
> - **15) Modules** → the `app/Modules/` scaffolding it refers to registers zero modules and is deleted in [wave 0](./MIGRATION_PLAN.md#wave-0--make-a-module-loadable-and-make-the-rules-enforceable). Module loading is [`MODULE_DEVELOPMENT.md`](./MODULE_DEVELOPMENT.md).
> - **18) Installer and Docker** → [`INSTALLATION.md`](./INSTALLATION.md), plus the missing `docker.yml` workflow recorded in [`CONFORMANCE.md` §3.2](./CONFORMANCE.md#32-high).
>
> The other 15 workstreams stand.

## Purpose
This document defines the minimum implementation tasks required for a stable customer release of the ecommerce platform.

## Release Readiness Principles
- Core customer journeys must work end-to-end (browse, cart, checkout, order confirmation).
- Admin users must have reliable operational control (catalog, inventory, orders, settings).
- Platform setup must be reproducible in local and server environments.
- Security, tenancy boundaries, and authentication flows must be production-safe.

## Priority Levels
- `P0`: Must be completed before release.
- `P1`: Should be completed for launch quality.
- `P2`: Can ship in immediate post-release patch if needed.

## Workstreams

### 1) Product and Category Slug Linking (`P0`)
#### Scope
- Ensure product and category records generate and persist unique slugs.
- Ensure storefront URLs resolve correctly for slug-based routes.
- Handle slug updates without breaking existing links.

#### Required Tasks
- Add/create slug generation and uniqueness validation on create/update.
- Enforce unique indexes at DB level for category and product slugs.
- Add redirect strategy or slug history for changed slugs.
- Verify canonical URL behavior and avoid duplicate content URLs.

#### Acceptance Criteria
- Product and category pages open correctly via slugs.
- Duplicate slugs are rejected or auto-resolved safely.
- Updated slugs do not produce dead links for existing indexed URLs.

---

### 2) Product Form and Product Page (`P0`)
#### Scope
- Complete admin product form for all mandatory merchandising fields.
- Ensure storefront product page displays consistent and valid data.

#### Required Tasks
- Validate required fields, pricing constraints, and status fields.
- Ensure product media, variants, attributes, and inventory tie into the form.
- Add publish/draft workflow and visibility controls.
- Implement error states for unavailable or unpublished products on frontend.

#### Acceptance Criteria
- Admin can create, edit, publish, and unpublish products without errors.
- Storefront product page matches saved product data.
- Invalid product submissions are blocked with clear validation messages.

---

### 3) Inventory Management and Logging (`P0`)
#### Scope
- Stabilize inventory operations for product and variant stock changes.
- Ensure inventory changes are logged for traceability.

#### Required Tasks
- Validate stock decrement path during checkout and order creation.
- Prevent overselling with atomic updates/transactions.
- Add inventory log entries for adjustments, sales, returns, and cancellations.
- Add low-stock checks and alerting baseline.

#### Acceptance Criteria
- Stock cannot go negative through normal checkout flows.
- Every inventory mutation has an associated log entry.
- Inventory values remain consistent after concurrent purchases.

---

### 4) Menus (`P1`)
#### Scope
- Ensure storefront navigation menus are configurable and stable.

#### Required Tasks
- Define menu structure for header, footer, and key category links.
- Add admin controls for menu item order, labels, and destinations.
- Validate internal links and fallback behavior for removed targets.

#### Acceptance Criteria
- Menu changes are reflected on storefront without template breakage.
- Broken links are prevented or clearly surfaced in admin validation.

---

### 5) Checkout Design and Add-to-Cart (`P0`)
#### Scope
- Deliver a reliable cart and checkout experience with complete UI flow.

#### Required Tasks
- Validate add-to-cart for product/variant selections and quantities.
- Ensure cart update/remove/coupon logic is stable.
- Complete checkout UI states: shipping, payment, confirmation, and errors.
- Validate totals (subtotal, discount, shipping, tax, final total).
- Add recovery UX for declined payment and out-of-stock events.

#### Acceptance Criteria
- Customer can add items, update cart, and place order successfully.
- Totals are accurate across all supported scenarios.
- Checkout failures return useful messages and preserve customer input.

---

### 6) Images, Variants, and Attributes (`P0`)
#### Scope
- Stabilize product content model for media and variant-driven selling.

#### Required Tasks
- Ensure product images upload, ordering, and rendering are reliable.
- Support variant selection by attributes (size, color, etc.).
- Validate variant-specific price/SKU/stock behavior.
- Prevent invalid variant combinations from reaching checkout.

#### Acceptance Criteria
- Product pages display correct images and variant options.
- Selected variant controls the price, SKU, and stock correctly.
- Invalid or unavailable variant states are blocked in UI.

---

### 7) Orders View and Management (`P0`)
#### Scope
- Provide stable admin workflows for order tracking and updates.

#### Required Tasks
- Implement order listing with filters (status, date, customer, payment).
- Implement order detail view with line items, totals, and event history.
- Support status transitions (pending, paid, fulfilled, canceled, refunded).
- Ensure order actions correctly update inventory and customer notifications.

#### Acceptance Criteria
- Admin can find and manage orders efficiently.
- Order state transitions are controlled and auditable.
- Order updates do not cause inconsistent financial/inventory data.

---

### 8) Currencies (`P1`)
#### Scope
- Ensure currency configuration and display are predictable.

#### Required Tasks
- Define base currency and supported display currencies.
- Normalize storage strategy for monetary values.
- Apply currency formatting consistently on storefront/admin.
- Validate conversion rules if multi-currency checkout is supported.

#### Acceptance Criteria
- Prices display with correct symbol, decimals, and locale formatting.
- Currency settings do not break totals, taxes, or order records.

---

### 9) Email Settings (`P0`)
#### Scope
- Ensure transactional emails are configurable and production-ready.

#### Required Tasks
- Validate mail transport configuration workflow.
- Add/send test email action in settings area.
- Confirm templates for order events and auth flows.
- Verify queue/retry behavior for failed email deliveries.

#### Acceptance Criteria
- Admin can configure and validate mail settings.
- Critical transactional emails are delivered reliably.

---

### 10) Forgot Password (Jetstream) (`P0`)
#### Scope
- Complete and verify password recovery flow.

#### Required Tasks
- Ensure reset request form, token generation, and reset submission work.
- Validate throttling and token expiry behavior.
- Verify email template and redirect/UX after successful reset.

#### Acceptance Criteria
- Users can request and complete password reset end-to-end.
- Invalid/expired tokens are handled safely with clear messaging.

---

### 11) Dashboard (`P1`)
#### Scope
- Ensure dashboard is useful, accurate, and performant.

#### Required Tasks
- Finalize key metrics (orders, revenue, customers, inventory signals).
- Validate widget data sources and aggregation logic.
- Add loading/error states and guard against heavy queries.

#### Acceptance Criteria
- Dashboard shows correct metrics and loads within acceptable time.
- Broken widgets fail gracefully without crashing admin page.

---

### 12) Customer Module (`P1`)
#### Scope
- Stabilize customer records and admin/customer interactions.

#### Required Tasks
- Validate customer profile and account edit flows.
- Ensure customer order history and basic account data are accurate.
- Add customer status and segmentation fields where required.

#### Acceptance Criteria
- Customer data remains consistent across auth, orders, and profile pages.
- Admin can view and manage customer records without data mismatch.

---

### 13) Social Login (`P1`)
#### Scope
- Support secure OAuth login providers for customer auth.

#### Required Tasks
- Implement provider configuration checks and callback handling.
- Handle account linking edge cases (existing email collisions).
- Add graceful fallback when provider is unavailable.

#### Acceptance Criteria
- Users can sign in/up via enabled social providers.
- Social auth does not create duplicate/conflicting accounts.

---

### 14) Tenancy, Stores, and Locations (`P0`)
#### Scope
- Ensure strict tenant isolation and store/location data integrity.

#### Required Tasks
- Validate tenancy boundaries in queries, policies, and routes.
- Ensure store/location context is applied in catalog, inventory, and orders.
- Add protection against cross-tenant data leakage.

#### Acceptance Criteria
- Tenant A cannot access or mutate Tenant B data.
- Store/location-specific operations are correctly scoped.

---

### 15) Modules (`P1`)
#### Scope
- Stabilize modular architecture loading and dependency behavior.

#### Required Tasks
- Validate module discovery, boot order, and configuration loading.
- Ensure disabled modules do not break bootstrapping.
- Verify route, migration, and view loading per module.

#### Acceptance Criteria
- Modules can be enabled/disabled without runtime failures.
- Core app behavior remains stable with expected module combinations.

---

### 16) Collections (`P1`)
#### Scope
- Ensure product collections are manageable and storefront-visible.

#### Required Tasks
- Finalize collection CRUD and product assignment.
- Validate sorting, visibility, and collection page rendering.
- Ensure collection-product relationships remain consistent after product updates.

#### Acceptance Criteria
- Admin can build and publish collections reliably.
- Storefront collection pages render correct product sets.

---

### 17) Services (`P1`)
#### Scope
- Stabilize business services and third-party integrations used by checkout/catalog.

#### Required Tasks
- Audit service classes for clear interfaces and exception handling.
- Add retries/timeouts and fallback paths for external dependencies.
- Verify logging/observability for service failures.

#### Acceptance Criteria
- Service failures are handled gracefully without cascading crashes.
- Critical workflows remain operable when optional services fail.

---

### 18) Installer and Docker (`P0`)
#### Scope
- Ensure reproducible setup for developers, QA, and deployment environments.

#### Required Tasks
- Validate one-command install/bootstrap workflow.
- Verify Docker build and compose services (app, DB, cache, queue).
- Confirm environment variable templates and setup documentation.
- Add health checks and basic readiness checks for services.

#### Acceptance Criteria
- Fresh environment can be started with documented steps and no manual fixes.
- Installer and Docker paths produce a working app with required dependencies.

---

## Cross-Cutting Stabilization Checklist

### Testing (`P0`)
- Add/complete feature tests for cart, checkout, orders, auth reset, and tenancy boundaries.
- Add unit tests for pricing, inventory mutation logic, and slug generation.
- Add regression tests for known production-risk paths.

### Security (`P0`)
- Verify authorization policies on admin and tenant-scoped endpoints.
- Validate CSRF, input validation, and output escaping on critical forms.
- Review password reset and social login attack surfaces.

### Data Integrity (`P0`)
- Validate DB constraints (foreign keys, unique keys, nullable correctness).
- Ensure transactions wrap multi-step writes in checkout/inventory/order flows.
- Validate idempotency for retryable operations.

### Observability (`P1`)
- Add structured logs for checkout, inventory, and external integrations.
- Ensure actionable error reporting for failed jobs and exceptions.
- Add baseline dashboard/alerts for checkout failure rate and low stock.

## Suggested Delivery Phases
1. Phase 1 (Release blockers): Items `P0` + cross-cutting `P0` checklist.
2. Phase 2 (Launch quality): Items `P1` + observability improvements.
3. Phase 3 (Hardening patch): Remaining `P2` items and optimization backlog.

## Exit Criteria for Stable Customer Release
- All `P0` items are implemented, tested, and signed off.
- No open critical/high severity defects in cart, checkout, orders, auth, or tenancy.
- Installation and deployment runbook validated in a fresh environment.
- Product owner and QA approval for core customer and admin workflows.
