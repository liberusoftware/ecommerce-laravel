# Analytics and reporting

The admin analytics dashboard: what it shows, how to read it, how to extend it.

Merged from the former `ANALYTICS.md` and `ANALYTICS_GUIDE.md`, which documented the same feature twice.

---

## What exists

### `app/Services/AnalyticsService.php`

The single aggregation point. Everything on the dashboard reads through it.

| Method | Returns |
| --- | --- |
| `getSalesTrends($period, $from, $to)` | Sales by period — `hourly`, `daily`, `weekly`, `monthly` |
| `getSalesMetrics($from, $to)` | Revenue, order count, average order value, growth rate |
| `getTopProducts($limit)` | Top sellers by revenue, with units sold |
| `getRecentOrders()` | Latest orders with customer information |
| `getCustomerDemographics()` | Geographic distribution by city and state, segmentation by order count, top customers by lifetime value |
| `getInventoryInsights()` | Low stock, out-of-stock count, total inventory value, stock status distribution |

### Widgets

| Area | Widgets |
| --- | --- |
| Sales | `SalesOverviewWidget` (metrics with growth indicators), `SalesTrendsChart` (line), `TopProductsWidget` (table) |
| Customers | `CustomerDemographicsWidget` (doughnut), `CustomerGrowthWidget` (line) |
| Inventory | `InventoryStatsWidget`, `LowStockInventoryWidget` (table) |
| Orders | `RecentOrdersWidget` (table, with status badges) |

They appear on the main dashboard and, in a fuller layout, on the Reports page (`app/Filament/Admin/Pages/Reports.php`) — header widgets for the key metrics, charts for trends, tables for detail.

---

## For administrators

Log in at `/admin`; the key metrics are on the dashboard, and **Reports** in the sidebar has the full set.

### What the numbers mean

**Sales Overview** — *Total Revenue* is the sum of **paid** orders in the last 30 days; *Total Orders* counts paid orders only; *Average Order Value* is revenue ÷ order count; *Growth %* compares against the preceding 30 days.

**Inventory Stats** — *Inventory Value* is quantity × price across products in stock; *Low Stock* is products below their own threshold; *Out of Stock* is zero inventory.

**Customer Segments** — by lifetime order count:

| Segment | Orders |
| --- | --- |
| No Orders | registered, never purchased |
| One-time Buyer | exactly 1 |
| Regular Customer | 2–5 |
| Loyal Customer | more than 5 |

**Unpaid orders are invisible everywhere.** Revenue, order counts and growth all filter on `payment_status = 'paid'`, so a dashboard that looks empty on a busy day usually means orders are not reaching paid status — not that nothing sold.

---

## For developers

### Calling the service

```php
use App\Services\AnalyticsService;

$analytics = app(AnalyticsService::class);

$metrics      = $analytics->getSalesMetrics(now()->startOfMonth(), now());
$trends       = $analytics->getSalesTrends('daily', now()->subDays(7), now());
$topProducts  = $analytics->getTopProducts(20);
$demographics = $analytics->getCustomerDemographics();
$inventory    = $analytics->getInventoryInsights();
```

The default window is 30 days; pass explicit dates to change it.

`$period` is validated by a `match` over `hourly` / `daily` / `weekly` / `monthly`, so it cannot be used to alter the SQL structure.

### Adding a widget

Stats:

```php
namespace App\Filament\Admin\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MyStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        return [
            Stat::make('Metric Name', '123')
                ->description('7.5% increase')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),
        ];
    }
}
```

Chart:

```php
use Filament\Widgets\ChartWidget;

class MyChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Chart Title';

    protected function getData(): array
    {
        return [
            'datasets' => [['label' => 'Data Series', 'data' => [10, 20, 30, 40]]],
            'labels' => ['Jan', 'Feb', 'Mar', 'Apr'],
        ];
    }

    protected function getType(): string
    {
        return 'line'; // or 'bar', 'pie', 'doughnut'
    }
}
```

Table:

```php
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class MyTableWidget extends BaseWidget
{
    public function table(Table $table): Table
    {
        return $table
            ->heading('Table Title')
            ->query(YourModel::query())
            ->columns([
                Tables\Columns\TextColumn::make('column_name')->label('Label')->sortable(),
            ]);
    }
}
```

Register it in `AdminPanelProvider`:

```php
->widgets([
    \App\Filament\Admin\Widgets\MyCustomWidget::class,
])
```

Or attach it to one page:

```php
protected function getHeaderWidgets(): array
{
    return [\App\Filament\Admin\Widgets\MyWidget::class];
}
```

Appearance is controlled by `$columnSpan` (1–12 or `'full'`), `$sort`, and `$maxHeight`.

### Read the data through the service

`SalesOverviewWidget` calls `AnalyticsService`; `TopProductsWidget` writes its own SQL. **The first is the pattern.** A widget with its own query is a second definition of a metric that has to be kept in step with the first, and there is nothing that will tell you when they diverge.

---

## Database

### Recommended indexes

```sql
CREATE INDEX idx_orders_date_status        ON orders(order_date, payment_status);
CREATE INDEX idx_order_items_order_product ON order_items(order_id, product_id);
CREATE INDEX idx_customers_created         ON customers(created_at);
CREATE INDEX idx_products_inventory        ON products(inventory_count, low_stock_threshold);
```

These are recommendations, not current state. Aggregate queries over `orders` and `order_items` are the ones that degrade first as data grows.

### Required data

- Orders with `payment_status = 'paid'` — for any revenue figure
- Customers with `city` and `state` — for demographics
- Products with `inventory_count` and `low_stock_threshold` — for stock alerts

### Caching

Nothing is cached today. The aggregate queries are the ones worth wrapping:

```php
use Illuminate\Support\Facades\Cache;

$metrics = Cache::remember('sales_metrics_30d', 300, fn () =>
    app(AnalyticsService::class)->getSalesMetrics()
);
```

---

## Access and data exposure

**The Reports page and every analytics widget expose customer names, email addresses, order values and full order detail to any user who can reach the admin panel.**

Authentication is enforced on the panel, and `TeamsPermission` middleware runs — but that is panel access, not per-resource authorization. There are no `can*` overrides or `Gate::` calls anywhere in the 105 Filament classes ([`CONFORMANCE.md` §3.1](./CONFORMANCE.md#31-critical)), so **panel access is effectively full access to this data**.

An earlier review of this feature concluded the access controls were adequate. That conclusion was drawn against the widgets alone and does not survive the wider audit; it is recorded here rather than left standing in a separate document.

Output escaping is sound: Filament's `TextColumn` escapes automatically, chart data is JSON-encoded, and no widget emits raw HTML. Query construction is sound too — the `DB::raw()` sites carry only static aggregate expressions, and all user-supplied filters go through parameter binding.

---

## Troubleshooting

**No data anywhere** — check for orders with `payment_status = 'paid'`; check the date range (default 30 days); check that customer and product relationships are populated.

**A widget does not appear** — confirm it is registered in `AdminPanelProvider`, then `php artisan cache:clear` and `php artisan filament:cache-clear`.

**Charts render empty but tables have rows** — usually a date-range mismatch rather than missing data. Check the browser console for JavaScript errors.

**Slow** — add the indexes above, add caching, narrow the date range, and `->limit()` the query. In that order.

---

## Not implemented

Recorded so nobody looks for them: date-range filters on the Reports page, CSV/PDF export, live updates, comparison periods, a custom report builder, scheduled email reports, geographic maps, category analytics, payment- and shipping-method breakdowns, abandoned-cart analytics, cohort analysis.

There is also no analytics API. Exposing one would need the same tenant scoping as every other read path — see [`CONFORMANCE.md` §6](./CONFORMANCE.md#6-security-findings).
