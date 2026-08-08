# `app/` → Module Inventory

Research output for [issue #929](https://github.com/liberusoftware/ecommerce-laravel/issues/929) (part of #925).

**Sources (primary, read at time of writing):**

- `liberusoftware/documentation` → `projects/ecommerce/ECOMMERCE.md` — 106 commerce modules across 14 families (§3–§16).
- `liberusoftware/documentation` → `projects/boilerplate/BOILERPLATE.md` — foundation module catalog (§3), 28 module rows.
- `projects/ecommerce/core/<module>.md` — 105 per-module specs. Checked; they are a uniform package-contract template (composer name, DDD plan, persistence plan, verification plan) and carry **no** capability detail beyond the catalog table. Placement below therefore rests on the catalog tables in `ECOMMERCE.md` §3–§16 and `BOILERPLATE.md` §3.

**This is inventory, not adjudication.** Where a placement is genuinely contested, both candidates are recorded in the rationale and the confidence is marked `medium` or `low`. Nothing is resolved here — that is the follow-up grilling ticket.

**Bucketing rule.** Every file lands in exactly one of the three sections:

- **Mapped** — one module in one of the two catalogs owns it.
- **Split** — the file's responsibilities straddle two or more modules; it cannot move as a unit.
- **Unmappable** — neither catalog has a row that owns it.

Migrations, views and tests inherit the bucket of the code they cover.

---

## Coverage

### `app/` (the ~400 files the ticket asks about)

| Section | Files | Share |
| ------- | ----- | ----- |
| Mapped | 332 | 83.0% |
| Split | 39 | 9.8% |
| Unmappable | 29 | 7.3% |
| **Total** | **400** | **100%** |

Per-directory (verified against `find app -name '*.php'`):

| Directory | Files | Mapped | Split | Unmappable |
| --------- | ----- | ------ | ----- | ---------- |
| `app/Models/` | 99 | 79 | 14 | 6 |
| `app/Filament/` | 105 | 83 | 8 | 14 |
| `app/Http/` | 61 | 48 | 9 | 4 |
| `app/Services/` | 28 | 21 | 6 | 1 |
| `app/Actions/` | 22 | 22 | 0 | 0 |
| `app/Policies/` | 17 | 16 | 0 | 1 |
| `app/Providers/` | 12 | 12 | 0 | 0 |
| `app/Modules/` | 11 | 11 | 0 | 0 |
| `app/Console/` | 10 | 9 | 1 | 0 |
| `app/Notifications/` | 9 | 9 | 0 | 0 |
| `app/Listeners/` | 5 | 5 | 0 | 0 |
| `app/Jobs/` | 3 | 3 | 0 | 0 |
| `app/Exceptions/` | 3 | 3 | 0 | 0 |
| `app/Interfaces/` | 3 | 3 | 0 | 0 |
| `app/Livewire/` | 3 | 2 | 0 | 1 |
| `app/Mail/` | 2 | 1 | 0 | 1 |
| `app/Factories/` | 2 | 2 | 0 | 0 |
| `app/GraphQL/` | 1 | 0 | 1 | 0 |
| `app/Settings/` | 1 | 1 | 0 | 0 |
| `app/Support/` | 1 | 1 | 0 | 0 |
| `app/Traits/` | 1 | 1 | 0 | 0 |
| `app/database/` | 1 | 0 | 0 | 1 |
| **Total** | **400** | **332** | **39** | **29** |

### Everything else the ticket names

| Area | Files | Mapped | Split | Unmappable |
| ---- | ----- | ------ | ----- | ---------- |
| `routes/` | 5 | 3 | 2 | 0 |
| `database/migrations/` | 122 | 115 | 0 | 7 |
| `database/factories/` + `database/seeders/` | 38 | 38 | 0 | 0 |
| `resources/views/` | 140 | 87 | 0 | 53 |
| `tests/` | 201 | 160 | 32 | 9 |
| **Subtotal** | **506** | **403** | **34** | **69** |

**Grand total across `app/` + the above: 906 files — 735 Mapped, 73 Split, 98 Unmappable.**

Nothing is silently omitted: every count above is a `find`/`ls` count of the real tree, and the three buckets sum to the directory total on every row.

---

## 1. Mapped

### 1.1 Catalog, PIM and product types

| Code group (paths) | Target module | Conf. | Rationale |
| ------------------ | ------------- | ----- | --------- |
| `app/Models/ProductCollection.php`, `ProductTag.php`, `Tag.php` | Catalog | high | Collections and tags are named responsibilities of the Catalog row. |
| `app/Models/ProductCategory.php`, `TaxonomyCategory.php`, `Menu.php`, `MenuItem.php` | Categories and Navigation | medium | Hierarchies + menus + merchandising positions. `ProductCategory` also carries SEO fields and `Menu`/`MenuItem` overlap with CMS-owned navigation — contested with CMS (out of catalog). |
| `app/Models/ProductAttribute.php`, `ProductAttributeValue.php`, `TaxonomyAttribute.php`, `ProductTaxonomyValue.php` | Product Information Management | medium | Attributes / attribute sets. `Taxonomy*` is contested with Categories and Navigation. |
| `app/Models/ProductImage.php` | Digital Assets | high | Commerce media, ordering, transformations. |
| `app/Models/ProductOption.php` | Options and Customization | high | Buyer-selectable options with allowed values. |
| `app/Models/ProductBundle.php`, `ProductBundleItem.php` | Bundles and Kits | high | Component rules and bundle pricing. |
| `app/Models/SimpleProduct.php` | Product Types | medium | A concrete "simple" type contract; contested with Catalog. |
| `app/Models/Preorder.php` | Availability | medium | Preorder policy; contested with Back-in-Stock and Price Alerts (it also notifies on release). |
| `app/Http/Controllers/Api/ProductController.php`, `Api/CollectionController.php`, `Frontend/ProductCollectionController.php`, `Frontend/ProductTagController.php` | Catalog | high | CRUD/browse over products, collections, tags. |
| `app/Http/Controllers/Frontend/ProductCategoryController.php` | Categories and Navigation | high | Category index/show/products. |
| `app/Http/Controllers/HomeController.php` | Catalog | medium | Featured/latest feeds; contested with Merchandising (placement/badges). |
| `app/Filament/Admin/Resources/Categories/` (4), `Menus/` (4), `MenuResource.php`, `MenuItemResource.php` | Categories and Navigation | medium | Admin surfaces for category/menu hierarchies. Note the Menu resource is duplicated (see Unmappable notes on duplication). |
| `app/Filament/App/Resources/Collections/` (4) | Catalog | high | `$model = ProductCollection::class`. |
| `app/Filament/Admin/Resources/Products/Pages/{Create,Edit,List}Product.php`, `app/Filament/App/Resources/Products/Pages/*` (6) | Catalog | high | Thin page classes; the `ProductResource` parents are Split. |
| `app/Policies/{Product,ProductCollection,ProductTag}Policy.php` | Catalog | high | Per-model authorization. |
| `app/Policies/ProductCategoryPolicy.php` | Categories and Navigation | high | Per-model authorization. |
| `app/Policies/SimpleProductPolicy.php` | Product Types | medium | Follows `SimpleProduct`. |

### 1.2 Pricing, promotions and merchandising

| Code group (paths) | Target module | Conf. | Rationale |
| ------------------ | ------------- | ----- | --------- |
| `app/Models/ProductCurrencyPrice.php`, `WholesalePriceTier.php` | Pricing | high | Currency price overrides and quantity tiers are both listed Pricing responsibilities. |
| `app/Models/Coupon.php`, `app/Services/CouponService.php`, `app/Policies/CouponPolicy.php`, `app/Filament/Admin/Resources/Coupons/` (4) | Promotions | high | Codes, limits, eligibility, redemption validation. |
| `app/Models/ProductRecommendation.php`, `RecommendationRule.php`, `BrowsingHistory.php`; `app/Services/RecommendationService.php`; `app/Console/Commands/GenerateProductRecommendations.php` | Recommendations | high | Rule/model recommendations, recently-viewed, related products. |
| `app/Models/CustomerSegment.php`, `ABTest.php`, `ABTestAssignment.php`; `app/Services/{ABTestingService,CustomerSegmentationService}.php`; `app/Console/Commands/CalculateCustomerSegments.php`; `app/Filament/Resources/CustomerSegmentResource/` (4) | Personalization | medium | Audience context, eligibility, holdouts, decision history. Contested: segments also fit Commerce Customers (customer groups/tags); A/B experiments also fit Merchandising Intelligence (experiment analysis). |
| `app/Models/ProductInteraction.php` | Attribution and Analytics | medium | Session-level engagement events. Contested with Recommendations (it is the recommender's input signal). |

### 1.3 Inventory

| Code group (paths) | Target module | Conf. | Rationale |
| ------------------ | ------------- | ----- | --------- |
| `app/Models/InventoryAdjustment.php`, `InventoryLog.php` | Inventory Ledger | high | Adjustments, reason codes, audit trail. |
| `app/Models/InventoryLocation.php` | Multi-Source Inventory | high | Warehouses/fulfillment centres with capabilities. |
| `app/Models/StockNotification.php`; `app/Notifications/ProductBackInStockNotification.php` | Back-in-Stock and Price Alerts | high | Subscription + dedup + notification status. |
| `app/Http/Controllers/InventoryController.php` | Inventory Ledger | high | Locked quantity adjustments. |
| `app/Notifications/LowStockNotification.php`; `app/Console/Commands/CheckLowStockItems.php` | Availability | medium | Safety-stock / threshold messaging; contested with Transfers and Replenishment (min/max/reorder alerts). |
| `app/Filament/Admin/Widgets/{InventoryStatsWidget,LowStockInventoryWidget}.php` | Reporting | medium | "inventory … metrics" is a listed Reporting responsibility; contested with Availability. |

### 1.4 Cart, saved lists, checkout

| Code group (paths) | Target module | Conf. | Rationale |
| ------------------ | ------------- | ----- | --------- |
| `app/Models/CartItem.php`; `app/Services/CartService.php`; `app/Http/Controllers/CartController.php`, `Api/CartController.php`; `app/Livewire/{CartCount,ShoppingCart}.php`; `app/Listeners/MergeGuestCartOnLogin.php`; `app/Policies/CartItemPolicy.php` | Cart | high | Guest/customer carts, lines, persistence, merge — verbatim Cart responsibilities. |
| `app/Models/AbandonedCart.php`, `CartRecoveryCampaign.php`, `CartRecoveryAttempt.php` | Abandoned Checkout | high | Cart state, consent-aware reminders, recovery links, attribution. |
| `app/Models/Wishlist.php`, `GiftRegistry.php`, `GiftRegistryItem.php`, `GiftRegistryPurchase.php`; `app/Http/Controllers/WishlistController.php` | Saved Lists | high | Wishlists + gift registries + sharing are one module row. |
| `app/Exceptions/CheckoutException.php` | Checkout | high | Checkout-scoped client-safe error. |

### 1.5 Orders, payments, invoices

| Code group (paths) | Target module | Conf. | Rationale |
| ------------------ | ------------- | ----- | --------- |
| `app/Models/OrderEvent.php`, `OrderNote.php`, `OrderStatusHistory.php`; `app/Exceptions/InvalidOrderTransitionException.php`; `app/Policies/{Order,OrderItem}Policy.php`; `app/Filament/App/Resources/Orders/` (4) | Orders | high | State machine, history, notes, audit. |
| `app/Http/Controllers/Api/OrderController.php` | Orders | high | Order listing/detail scoped to the customer. |
| `app/Http/Controllers/Api/OrderStatusController.php` | Orders | medium | State transitions; contested with Order Orchestration (status aggregation). |
| `app/Http/Controllers/OrderHistoryController.php` | Customer Accounts | medium | "order history" is a listed Customer Accounts responsibility; contested with Orders. |
| `app/Models/PaymentMethod.php`; `app/Http/Controllers/PaymentMethodController.php` | Payments | medium | Tender/method references; contested with Customer Accounts ("payment-method references"). |
| `app/Services/PaymentGatewayService.php`, `PaymentGateways/{PayPal,Stripe}Gateway.php`; `app/Factories/PaymentGatewayFactory.php`; `app/Interfaces/PaymentGatewayInterface.php` | Payments | high | Gateway-neutral authorize/capture/refund plus provider adapters. |
| `app/Notifications/{PaypalTransactionNotification,TransactionSuccessNotification}.php` | Payments | high | Payment outcome messaging. |
| `app/Models/Invoice.php` handled in Split; `app/Http/Controllers/InvoiceController.php`, `app/Http/Livewire/InvoicePdf.php`, `app/Mail/InvoiceMail.php`, `app/Policies/InvoicePolicy.php`, `app/Filament/App/Resources/Invoices/` (4) | Invoices and Documents | high | Document projections, PDFs, delivery. |
| `app/Models/{Refund,RefundItem}.php` (RefundItem only), `app/Notifications/OrderRefundedNotification.php` | Refunds | high | Refund lines and customer notice. |
| `app/Models/ReturnRequest.php`, `ReturnRequestItem.php` | Returns | high | RMA, approval, receipt, condition capture. |
| `app/Interfaces/Orderable.php` | Orders | low | A two-method `getPrice`/`getName` contract; equally arguable as Cart or Catalog. |

### 1.6 Tax, shipping, fulfillment, dropshipping

| Code group (paths) | Target module | Conf. | Rationale |
| ------------------ | ------------- | ----- | --------- |
| `app/Models/TaxClass.php`, `TaxRate.php`; `app/Support/EuVat.php`; `app/Services/TaxService.php`; `app/Filament/Admin/Resources/TaxClasses/` (4) | Tax | high | Classes, destination rules, rounding, rates. |
| `app/Services/{OssReportService,EcSalesListService}.php`; `app/Console/Commands/{GenerateOssVatReport,GenerateEcSalesList}.php` | Tax | medium | "reports" is a listed Tax responsibility; contested with Cross-Border (intra-EU/OSS filing) and Reporting. |
| `app/Services/ViesService.php` | Tax | medium | VAT-number validation as exemption evidence; contested with Cross-Border (adapters). |
| `app/Models/ShippingMethod.php`; `app/Http/Controllers/ShippingController.php`; `app/Console/Commands/PruneShippingQuotes.php` | Shipping | high | Zones, methods, rates, table rates. |
| `app/Models/ShippingQuote.php` | Shipping | medium | Cached carrier rate with expiry; contested with Carrier Operations (rates/reconciliation). |
| `app/Services/Shipping/{CarrierRate,EasyPostCarrier}.php`; `app/Factories/CarrierRateFactory.php`; `app/Interfaces/CarrierRateInterface.php` | Carrier Operations | high | Live carrier rates via EasyPost — a provider adapter. |
| `app/Services/DropshippingService.php`; `app/Jobs/DispatchDropshippingOrder.php`; `app/Http/Controllers/Api/DropshippingController.php`; `app/Notifications/SupplierFailureNotification.php` | Dropshipping | high | Supplier routing, order transmission, acknowledgements, SLA/exceptions. |
| `app/Http/Controllers/DownloadController.php` | Digital Fulfillment | high | Secure downloads with limits and expiry. |

### 1.7 Customers, reviews, loyalty, gift cards

| Code group (paths) | Target module | Conf. | Rationale |
| ------------------ | ------------- | ----- | --------- |
| `app/Models/Customer.php`; `app/Http/Controllers/Api/CustomerController.php`; `app/Policies/CustomerPolicy.php`; `app/Filament/App/Resources/Customers/` (4) | Commerce Customers | high | Commerce profile, addresses, preferences, CRM identity reference. |
| `app/Models/Group.php`; `app/Policies/GroupPolicy.php`; `app/Filament/App/Resources/Groups/` (4) | Commerce Customers | low | A generic "group with discount + team"; contested with Boilerplate Organizations and Teams and with Pricing (customer-group pricing). |
| `app/Models/CustomerMetric.php`; `app/Console/Commands/UpdateCustomerMetrics.php` (Split — see §3) | Reporting | medium | CLV / order frequency / churn = "customer metrics"; contested with Attribution and Analytics. |
| `app/Http/Controllers/{AccountDataExportController,AccountErasureController}.php`; `app/Services/{GdprExportService,GdprErasureService}.php` | Customer Accounts | high | "privacy requests" is an explicit Customer Accounts responsibility. |
| `app/Models/{Review,ProductReview,Rating,ProductRating}.php`; `app/Http/Controllers/{Review,Rating}Controller.php`; `app/Http/Requests/{Review,Rating}Request.php`; `app/Policies/{ProductReview,ProductRating}Policy.php`; `app/Filament/App/Resources/{ProductReviews,ProductRatings}/` (8) | Reviews and Ratings | high | Verified purchase, ratings, moderation, helpfulness votes. **Note:** `Review`/`ProductReview` and `Rating`/`ProductRating` are two parallel duplicate implementations of the same module. |
| `app/Models/Loyalty*.php` (6); `app/Notifications/LoyaltyPointsEarnedNotification.php` | Loyalty | high | Programs, tiers, points ledger, rewards, redemption. |
| `app/Models/GiftCard.php`, `GiftCardTransaction.php` | Gift Cards and Store Credit | high | Balance, expiry, immutable transaction ledger. |

### 1.8 Recurring, B2B, analytics/reporting

| Code group (paths) | Target module | Conf. | Rationale |
| ------------------ | ------------- | ----- | --------- |
| `app/Models/PaypalSubscription.php`; `app/Services/SubscriptionService.php`; `app/Notifications/SubscriptionUpdatedNotification.php` | Subscription Commerce | medium | Storefront enrolment + provider handoff. `ECOMMERCE.md` §2 says Billing owns the recurring ledger when installed — contested with Billing (a different product's catalog). |
| `app/Models/QuoteRequest.php` | Negotiated Quotes | high | Buyer request, responses, validity, versions. |
| `app/Models/AnalyticsEvent.php` | Attribution and Analytics | high | Canonical events with UTM/session/campaign mapping. |
| `app/Models/ProductPerformance.php` | Reporting | medium | Daily views/adds/purchases/revenue; contested with Merchandising Intelligence (conversion, trends). |
| `app/Services/AnalyticsService.php`; `app/Filament/Admin/Pages/Reports.php`; `app/Filament/App/Pages/Reports.php`; `app/Filament/Admin/Widgets/{SalesOverviewWidget,SalesTrendsChart,TopProductsWidget,RecentOrdersWidget,CustomerGrowthWidget,CustomerDemographicsWidget}.php` | Reporting | high | The Reporting row explicitly spans sales, inventory, customer and channel metrics, so the multi-domain dashboards are not Split. |

### 1.9 Boilerplate foundation

| Code group (paths) | Target module | Conf. | Rationale |
| ------------------ | ------------- | ----- | --------- |
| `app/Actions/Fortify/` (6); `app/Http/Responses/{Login,Logout,Register}Response.php`; `app/Providers/FortifyServiceProvider.php`; `app/Http/Middleware/{Authenticate,RedirectIfAuthenticated}.php` | Identity | high | Registration policy, login/logout, password reset. `UpdateUserProfileInformation.php` also touches Profiles. |
| `app/Actions/Socialstream/` (8); `app/Models/ConnectedAccount.php`; `app/Policies/ConnectedAccountPolicy.php`; `app/Providers/SocialstreamServiceProvider.php` | Identity | high | "identity linking" is an explicit Identity responsibility. |
| `app/Actions/Jetstream/` (8); `app/Providers/JetstreamServiceProvider.php` | Jetstream Bridge | high | The Jetstream Bridge row exists precisely for these contract-compatible actions. |
| `app/Models/Team.php` (Split — see §3), `TeamInvitation.php`, `Membership.php`; `app/Policies/TeamPolicy.php`; `app/Providers/TeamServiceProvider.php`; `app/Http/Middleware/AssignDefaultTeam.php`; `app/Http/Livewire/CreateTeam.php`; `app/Listeners/{CreatePersonalTeam,SwitchTeam}.php`; `app/Traits/IsTenantModel.php`; `app/Filament/App/Pages/{CreateTeam,EditTeam}.php` | Organizations and Teams | high | Teams, memberships, invitations, switching, current-context resolution. **Note:** `Membership.php` maps `team_user` — it is *not* the Membership Commerce module. |
| `app/Models/{Role,Permission}.php`; `app/Http/Middleware/{TeamsPermission,RoleBasedRedirect}.php`; `app/Providers/AuthServiceProvider.php` | Roles and Permissions | high | Role/permission definitions with contextual (team) scope. |
| `app/Filament/App/Pages/EditProfile.php` | Profiles | high | Controlled profile updates. |
| `app/Filament/Admin/Resources/Users/` (7) | Identity | medium | User admin CRUD; contested with Profiles and Roles and Permissions. |
| `app/Models/Currency.php` | Currency Context | high | ISO metadata, exchange rate, display formatting. |
| `app/Models/SiteSetting.php`; `app/Settings/GeneralSettings.php`; `app/Services/SiteSettingsService.php`; `app/Http/Controllers/SiteSettingController.php`; `app/Filament/Admin/Pages/{ManageGeneralSettings,Settings}.php`; `app/Filament/App/Pages/Settings.php`; `app/Filament/App/Widgets/SocialLinksWidget.php` | Settings | high | Typed app/org settings with precedence and cache invalidation. `SocialLinksWidget` is low confidence — it only renders settings values. |
| `app/Models/{WebhookEndpoint,WebhookDelivery}.php`; `app/Jobs/{DispatchOutboundWebhook,SendWebhookDelivery}.php`; `app/Http/Controllers/Api/WebhookEndpointController.php`; `app/Console/Commands/RetryFailedWebhooks.php` | Webhooks | high | Registrations, signing, delivery attempts, backoff, replay, logs. |
| `app/Modules/` (11); `app/Models/Module.php`; `app/Console/Commands/ModuleCommand.php` | Module Manager | high | Manifest discovery, dependency resolution, enable/disable lifecycle, registry — verbatim `MODULES.md` scope. |
| `app/Providers/HorizonServiceProvider.php`; `app/Console/Kernel.php` | Scheduler and Queues | high | Queue dashboard + schedule definition. |
| `app/Exceptions/Handler.php`; `app/Http/Middleware/SecurityHeaders.php`; `app/Listeners/LogAuthenticationFailure.php` | Observability | medium | Exception reporting and structured security logging; `LogAuthenticationFailure` is contested with Audit. |
| `app/Http/Middleware/{EncryptCookies,PreventRequestsDuringMaintenance,TrimStrings,TrustHosts,TrustProxies,ValidateSignature,VerifyCsrfToken}.php`; `app/Http/Kernel.php`; `app/Http/Controllers/Controller.php`; `app/Providers/{AppServiceProvider,BroadcastServiceProvider,EventServiceProvider,RouteServiceProvider}.php` | Application Core | high | Maintenance mode, base contracts, host composition. `RouteServiceProvider` also configures API rate limits (contested with API Access). |
| `app/Providers/Filament/{AdminPanelProvider,AppPanelProvider}.php` | Application Core | low | Panel composition roots that register resources from every domain — a host concern, not any one module's. Contested with Commerce Core. |
| `app/Http/Controllers/GraphQLController.php` | API Access | medium | Endpoint with complexity limits and Sanctum scoping; contested with Sales Channels (headless delivery). |
| `app/Listeners/EmailTracker.php` | Notifications | medium | Delivery-status tracking; contested with Attribution and Analytics. |

### 1.10 Non-`app/` groups

| Code group (paths) | Target module | Conf. | Rationale |
| ------------------ | ------------- | ----- | --------- |
| `routes/socialstream.php` | Identity | high | OAuth redirect/callback routes only. |
| `routes/console.php`, `routes/channels.php` | Application Core | high | Stock Laravel scaffolding; `channels.php` authorizes only the `App.Models.User.{id}` channel. |
| `database/migrations/` — 115 of 122 | *the module owning the table* | high | 86 files create a table; the other 36 alter/index/backfill and inherit their parent table's module. Cluster→module: `products*`/`collections`/`tags` → Catalog; `product_attributes*`/`taxonom*` → PIM; `product_bundles*` → Bundles and Kits; `product_options` → Options and Customization; `orders*`/`order_*` → Orders; `invoices*` → Invoices and Documents; `inventory_*` → Inventory Ledger + Multi-Source Inventory; `stock_notifications` → Back-in-Stock; `abandoned_carts`/`cart_recovery_*` → Abandoned Checkout; `wishlists`/`gift_registr*` → Saved Lists; `coupons`/`discounts` → Promotions + Pricing Rules; `tax_*` → Tax; `shipping_*` → Shipping; `return_*`/`refund*` → Returns + Refunds; `reviews`/`ratings` → Reviews and Ratings; `loyalty_*` → Loyalty; `gift_cards*` → Gift Cards and Store Credit; `wholesale_*` → Shared Catalogs/Pricing; `quote_requests` → Negotiated Quotes; `preorders` → Availability; `ab_test*` → Personalization; `analytics_events`/`conversion_*`/`browsing_histories` → Attribution and Analytics; `customers`/`customer_group*`/`customer_segment*`/`customer_metrics` → Commerce Customers + Personalization + Reporting; `payment_methods`/`subscriptions*`/`paypal_subscriptions` → Payments + Subscription Commerce; `webhook_*` → Webhooks; `teams`/`team_*`/`roles`/`permissions`/`model_has_*` → Organizations and Teams + Roles and Permissions; `settings`/`site_settings`/`modules` → Settings + Module Manager; `sessions`/`failed_jobs`/`password_reset_tokens` → Application Core + Scheduler and Queues. |
| `database/factories/` (20) + `database/seeders/` (18) | Developer Experience | medium | `BOILERPLATE.md` §3 lists "fixtures, factories" under Developer Experience. **Contested:** every per-module spec says "the module owns migrations … factories, seeders", which would instead scatter these 38 files across ~20 domain modules. Both candidates recorded. |
| `resources/views/{auth,profile,teams,components/socialstream-icons}/` (30) | Identity / Profiles / Organizations and Teams | high | Foundation UI surfaces named in `BOILERPLATE.md` §14. |
| `resources/views/{products,collections,tags,categories,cart,checkout,orders,invoices,wishlist,shipping,payment_methods,subscriptions,site_settings,api,filament,livewire,menus}/` + 4 root blades + `components/{product-card,products_section}` (57) | *the module owning the page* | medium | Storefront/admin templates follow their controller's module. Templates are a theme-package concern under `THEMES.md`, so ownership is arguably shared. |
| `tests/` — 160 of 201 | *the module under test* | high | Each test inherits its subject's module (e.g. `TaxRateModelTest` → Tax, `LoyaltyPointsTest` → Loyalty, `WebhookDeliveryLogTest` → Webhooks). `tests/TestCase.php`, `tests/CreatesApplication.php`, both `ExampleTest.php` and `DummyDataSeederTest.php` → Developer Experience. |

---

## 2. Unmappable

29 files in `app/`, 69 elsewhere. Neither `ECOMMERCE.md` nor `BOILERPLATE.md` has a row that owns these.

| Code (paths) | Files | Why no home |
| ------------ | ----- | ----------- |
| **Live support chat.** `app/Models/{ChatConversation,ChatMessage,ChatAnalytics}.php`; `app/Services/ChatService.php`; `app/Http/Controllers/ChatController.php`; `app/Livewire/ChatWidget.php`; `app/Filament/Admin/Resources/ChatConversations/` (3); `app/Filament/Admin/Pages/ChatAgentDashboard.php`; `app/Filament/Admin/Widgets/ChatStatsWidget.php` | 11 | Agent queues, assignment, satisfaction ratings and response-time analytics. The nearest row, **Customer Service Workspace**, is scoped to "orders, payments, shipments, returns, customer timeline … and CRM/**Support handoff**" — it explicitly *hands off* to Support rather than owning a chat channel. `ECOMMERCE.md` §2 assigns customer engagement to CRM. No Support catalog was supplied. Contested candidate recorded: Customer Service Workspace (low). |
| **CMS content.** `app/Models/{Article,Page}.php`; `app/Policies/ArticlePolicy.php`; `app/Filament/Admin/Resources/Pages/` (4); `app/Filament/App/Resources/Articles/` (4); `app/Filament/Admin/Pages/FAQ.php` | 11 | `ECOMMERCE.md` §2: "CMS owns editorial content". Articles, pages and FAQs belong to the CMS product's catalog, which is neither of the two supplied. |
| **SEO.** `app/Models/SeoSetting.php`; `app/Http/Controllers/SitemapController.php` | 2 | Neither catalog has an SEO/sitemap/structured-data row. `Catalog` covers "channel publication", not meta tags, canonicals or `sitemap.xml`. |
| **Contact form.** `app/Http/Controllers/ContactController.php`; `app/Mail/ContactMessage.php` | 2 | A public enquiry form with honeypot + mail delivery. Not commerce; CRM/CMS territory. |
| **Stray tenant-screening middleware.** `app/Http/Middleware/ScreeningDataEncryptor.php` | 1 | Encrypts `background_check_status`, `credit_report_status`, `rental_history_status` in JSON responses. Leftover from a property-rental boilerplate; no such fields exist anywhere in this schema. Dead code with no module anywhere. |
| **Broken misplaced seeder.** `app/database/seeders/MenuSeeder.php` | 1 | No `<?php` tag, no namespace, no class declaration, body is `// ... existing code ...` placeholders. 16 lines of non-executable text under `app/`, duplicating `database/seeders/MenuSeeder.php`. Not code, so not placeable. |
| **`app/` subtotal** | **29** | |
| `database/migrations/` — `pages`, `articles`, `seo_settings`, `chat_conversations`, `chat_messages`, `chat_analytics`, `reminder_settings` | 7 | Tables for the CMS/SEO/chat groups above, plus `reminder_settings` which no code in the repo reads. |
| `resources/views/components/` (38 flat primitives: buttons, inputs, modals, dropdowns, layouts, section chrome, `application-mark`, `welcome`, `banner`, …); `resources/views/layouts/` (1) | 39 | Jetstream/Tailwind design-system primitives. `BOILERPLATE.md` §14 discusses "Foundation UI surfaces" in prose but the §3 catalog has **no** UI/design-system module row; `THEMES.md` puts these in theme packages, not modules. |
| `resources/views/vendor/` | 9 | Published third-party package views (Jetstream/Filament overrides). Owned by the vendor package, not a module. |
| `resources/views/{about,contact}.blade.php`, `sitemap/`, `mail/contact-message.blade.php`, `livewire/chat-widget.blade.php` | 5 | Templates for the CMS / contact / SEO / chat groups above. |
| `tests/` — 4 chat, `ContactFormTest`, `SitemapControllerTest`, `SeoSettingModelTest`, `PageModelTest`, `PaletteContrastTest` | 9 | Tests inherit the Unmappable bucket of their subjects. `PaletteContrastTest` asserts theme colour contrast — a design-system concern with no module row. |
| **Non-`app/` subtotal** | **69** | |

### Also worth recording (mapped, but duplicated)

Not a separate bucket, but the inventory surfaced parallel implementations that will collide when modules are extracted:

- `Review` + `ProductReview`, and `Rating` + `ProductRating` — two full stacks (model, migration, policy, Filament resource) for **Reviews and Ratings**.
- `Filament/Admin/Resources/MenuResource.php` (a `BaseMenuResource` wrapper) and `Filament/Admin/Resources/Menus/MenuResource.php` (a full resource) — two admin surfaces for the same `Menu` model.
- `Filament/Admin/Resources/Products/` and `Filament/App/Resources/Products/` — the same model exposed twice across panels; same for `Reports.php` and `Settings.php`.
- `app/Services/RecommendationService.php` and `app/Services/ProductRecommendationService.php` — two recommenders.
- `app/Services/TaxService.php` and `app/Services/TaxCalculator.php` — two tax engines.
- `app/Http/Controllers/CartController.php` (session cart) and `Api/CartController.php` (persistent cart) — two cart storage models.
- `database/seeders/PermissionsSeeder.php` and `PermissionsTableSeeder.php`.

---

## 3. Split

Files whose responsibilities straddle two or more modules. 39 in `app/`, plus 2 route files and 32 tests that mirror them.

### 3.1 `app/Models/` (14)

| File | Modules it straddles | Evidence |
| ---- | -------------------- | -------- |
| `Product.php` | Catalog · Pricing · Inventory Ledger · Digital Assets · Product Types | One model carries the catalog record, price fields, stock quantity, images and type flags. |
| `ProductVariant.php` | Catalog · Pricing · Inventory Ledger · Options and Customization | SKU + price + inventory + option values on one row. |
| `DownloadableProduct.php` | Product Types · Digital Fulfillment | The downloadable *type contract* and the entitlement/download-limit *enforcement* in one model. |
| `Discount.php` | Pricing Rules · Promotions · Shipping | A rule engine spanning percentage/fixed conditions (Pricing Rules), coded offers (Promotions) and free-shipping actions (Shipping). |
| `CustomerGroup.php` | Commerce Customers · Pricing | Group identity plus the tiered discount attached to it. |
| `WholesaleGroup.php` | Shared Catalogs · Pricing · Companies | B2B assortment membership plus tiered pricing plus an account-level discount. |
| `InventoryItem.php` | Inventory Ledger · Multi-Source Inventory · Cross-Border | Stock master with cost references *and* harmonized (HS) codes, which belong to Cross-Border. |
| `InventoryLevel.php` | Multi-Source Inventory · Reservations | Per-location on-hand/available alongside `reserved`/`committed` quantities. |
| `Order.php` | Orders · Payments · Fulfillment · Refunds | Order snapshot + state machine, plus payment status, shipping/tracking fields and refund totals. |
| `OrderItem.php` | Orders · Digital Fulfillment | Line snapshot plus a per-line download link. |
| `Invoice.php` | Invoices and Documents · Payments | Document projection plus a payment-status column. |
| `Refund.php` | Refunds · Payments · Inventory Ledger | Refund amount + gateway transaction reference + restock behaviour. |
| `User.php` | Identity · Commerce Customers · Organizations and Teams · Saved Lists | Auth identity plus orders, wishlist, product interactions and team membership. |
| `Team.php` | Organizations and Teams · Commerce Core | The tenancy container; `StoreResource` binds `$model = Team::class` and labels it "Store", so this row is simultaneously the boilerplate team and the Commerce Core store/channel. |

### 3.2 `app/Services/` (6)

| File | Modules it straddles | Evidence |
| ---- | -------------------- | -------- |
| `CheckoutService.php` | Checkout · Promotions · Reservations · Payments · Digital Fulfillment · Dropshipping | Public API is `resolveCouponDiscount`, `assertCouponAvailable`, `grantDownloads`, `queueDropship`, `reserveStock`, `releaseStock`, `capturePayment` — six modules in one class. |
| `HeadlessCheckoutService.php` | Checkout · Shipping · Tax · Payments | `place()` internally does `resolveShipping`, `calculateTax`, `charge`. |
| `TaxCalculator.php` | Tax · Pricing | `calculateCartTax`/`calculateProductTax` (Tax) sit beside `getPriceWithTax`, `displayPrice`, `shouldDisplayPricesWithTax` (inclusive/exclusive display pricing = Pricing). |
| `ShippingService.php` | Shipping · Carrier Operations · Dropshipping | `getAvailableShippingMethods`/`calculateShippingCost` (Shipping), `quoteLiveRates`/`getLiveRates`/`verifyAddress` (Carrier Operations), `calculateDropShippingCost` (Dropshipping). |
| `ProductRecommendationService.php` | Recommendations · Attribution and Analytics | Generates recommendations *and* owns the event capture (`trackView`, `trackAddToCart`, `trackPurchase`). |
| `DropxlService.php` | Dropshipping · Catalog Import and Export | A supplier API adapter that also runs `importProduct`/`importByCategory`/`importAll` product ingestion with category resolution. |

### 3.3 `app/Http/` and `app/GraphQL/` (10)

| File | Modules it straddles |
| ---- | -------------------- |
| `Controllers/CheckoutController.php` | Checkout · Shipping · Tax · Promotions · Payments · Reservations · Cross-Border (it injects `ShippingService`, `TaxCalculator`, `CheckoutService` and `ViesService`) |
| `Controllers/SubscriptionController.php` | Subscription Commerce · Payments (both Stripe and PayPal lifecycles in one controller) |
| `Controllers/StripePaymentController.php` | Payments · Subscription Commerce |
| `Controllers/PaypalPaymentController.php` | Payments · Subscription Commerce |
| `Controllers/StripeWebhookController.php` | Payment Operations · Refunds |
| `Controllers/PaypalWebhookController.php` | Payment Operations · Subscription Commerce |
| `Controllers/Api/OrderRefundController.php` | Refunds · Payments · Inventory Ledger (restock) |
| `Controllers/Api/ReturnRequestController.php` | Returns · Refunds (approval spawns the refund) |
| `Controllers/Frontend/ProductController.php` | Catalog · Search and Discovery · Product Comparison · Back-in-Stock and Price Alerts (`search`, `addToCompare`, `compare`, `notifyMe` on one controller) |
| `GraphQL/StorefrontSchema.php` | Catalog · Cart · Checkout · Orders · API Access (a single code-first schema exposing all of them) |

### 3.4 `app/Filament/` (8) and `app/Console/` (1)

| File | Modules it straddles |
| ---- | -------------------- |
| `Filament/Admin/Resources/Discounts/` (4 files) | Pricing Rules · Promotions (admin surface over the Split `Discount` model) |
| `Filament/Admin/Resources/Products/ProductResource.php` | Catalog · Pricing · Inventory Ledger |
| `Filament/App/Resources/Products/ProductResource.php` | Catalog · Pricing · Inventory Ledger |
| `Filament/Admin/Resources/Stores/StoreResource.php` | Commerce Core · Organizations and Teams (`$model = Team::class`, `$modelLabel = 'Store'`) |
| `Filament/Admin/Pages/DropxlImport.php` | Dropshipping · Catalog Import and Export |
| `Console/Commands/UpdateCustomerMetrics.php` | Reporting · Personalization (recalculates lifetime value *and* segment membership) |

### 3.5 `routes/` (2) and `tests/` (32)

| File | Modules it straddles |
| ---- | -------------------- |
| `routes/web.php` | ~18 modules in one 102-route file: Catalog, Categories and Navigation, Cart, Saved Lists, Checkout, Shipping, Tax, Orders, Customer Accounts, Payments, Payment Operations, Subscription Commerce, Reviews and Ratings, Product Comparison, Digital Fulfillment, Invoices and Documents, Inventory Ledger, Settings — plus unmappable contact/sitemap/chat routes. |
| `routes/api.php` | ~10 modules: API Access, Cart, Catalog, Commerce Customers, Orders, Refunds, Returns, Dropshipping, Webhooks, plus the GraphQL endpoint. |
| `tests/` — 32 files | Tests mirroring the Split production files: 6 `Checkout*`, 3 `GraphQL*`, 5 payment/webhook/Cashier, 3 `Order*`, 2 `Tax*` (Calculator/InclusiveDisplay), 2 `Shipping*Service*`, 2 `Refund*`, `ReturnApprovalRefundTest`, `UserCustomerIdentityTest`, `ProductModelTest`, `ProductVariantModelTest`, `DiscountModelTest`, `CustomerGroupModelTest`, `WholesaleGroupModelTest`, `ProductRecommendationServiceTest`, `DropxlServiceTest`. |

---

## Notes for the follow-up grilling ticket

The contested placements recorded above, in rough order of how much rides on them:

1. **`Team` = store or tenant?** Decides whether Commerce Core gets its own store/channel table or keeps borrowing the boilerplate team. Touches 12+ files.
2. **Who owns factories/seeders/tests** — the Developer Experience row, or each domain module per its own spec. 38 + 201 files hang on this.
3. **Chat** — a Support module that neither catalog defines, or a stretched Customer Service Workspace.
4. **Segments and A/B tests** — Personalization vs Commerce Customers vs Merchandising Intelligence.
5. **EU VAT reporting** (OSS, EC Sales List, VIES) — Tax vs Cross-Border vs Reporting.
6. **Taxonomy models** — Product Information Management vs Categories and Navigation.
7. **The generic UI layer** (39 blade primitives) — no module row exists; needs either a `THEMES.md` package or a new catalog row.
