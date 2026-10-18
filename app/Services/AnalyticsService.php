<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
    /**
     * Get sales trends for a given period
     */
    public function getSalesTrends(string $period = 'daily', ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $startDate = $startDate ?? now()->subDays(30);
        $endDate = $endDate ?? now();

        $groupBy = match ($period) {
            'hourly' => "DATE_FORMAT(order_date, '%Y-%m-%d %H:00:00')",
            'daily' => 'DATE(order_date)',
            'weekly' => 'YEARWEEK(order_date, 1)',
            'monthly' => "DATE_FORMAT(order_date, '%Y-%m')",
            default => 'DATE(order_date)'
        };

        $sales = Order::whereBetween('order_date', [$startDate, $endDate])
            ->where('payment_status', 'paid')
            ->select(
                DB::raw("{$groupBy} as period"),
                DB::raw('COUNT(*) as order_count'),
                // Net of refunds: refunded orders keep payment_status='paid' and track
                // the returned money in refund_total, so gross would overstate revenue.
                DB::raw('SUM(total_amount - COALESCE(refund_total, 0)) as total_revenue'),
                DB::raw('AVG(total_amount - COALESCE(refund_total, 0)) as avg_order_value')
            )
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        return $sales->toArray();
    }

    /**
     * Get overall sales metrics
     */
    public function getSalesMetrics(?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $startDate = $startDate ?? now()->subDays(30);
        $endDate = $endDate ?? now();

        $orders = Order::whereBetween('order_date', [$startDate, $endDate])
            ->where('payment_status', 'paid');

        // Net of refunds — see the note in getSalesTrends.
        $totalRevenue = (float) $orders->sum(DB::raw('total_amount - COALESCE(refund_total, 0)'));
        $orderCount = $orders->count();
        $avgOrderValue = $orderCount > 0 ? $totalRevenue / $orderCount : 0;

        // Compare with previous period
        $previousPeriod = $startDate->diffInDays($endDate);
        $previousStartDate = $startDate->copy()->subDays($previousPeriod);
        $previousEndDate = $startDate->copy()->subDay();

        $previousOrders = Order::whereBetween('order_date', [$previousStartDate, $previousEndDate])
            ->where('payment_status', 'paid');

        $previousRevenue = (float) $previousOrders->sum(DB::raw('total_amount - COALESCE(refund_total, 0)'));
        $previousOrderCount = $previousOrders->count();

        $revenueGrowth = $previousRevenue > 0
            ? (($totalRevenue - $previousRevenue) / $previousRevenue) * 100
            : 0;

        $orderGrowth = $previousOrderCount > 0
            ? (($orderCount - $previousOrderCount) / $previousOrderCount) * 100
            : 0;

        return [
            'total_revenue' => round($totalRevenue, 2),
            'order_count' => $orderCount,
            'avg_order_value' => round($avgOrderValue, 2),
            'revenue_growth' => round($revenueGrowth, 2),
            'order_growth' => round($orderGrowth, 2),
        ];
    }

    /**
     * Get top selling products
     */
    public function getTopProducts(int $limit = 10, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $startDate = $startDate ?? now()->subDays(30);
        $endDate = $endDate ?? now();

        $topProducts = Product::query()
            ->join('order_items', 'order_items.product_id', '=', 'products.id')
            ->whereIn('order_items.order_id', Order::query()
                ->select('orders.id')
                ->whereBetween('orders.order_date', [$startDate, $endDate])
                ->where('orders.payment_status', 'paid'))
            ->select(
                'products.id',
                'products.name',
                DB::raw('SUM(order_items.quantity) as total_quantity'),
                DB::raw('SUM(order_items.price * order_items.quantity) as total_revenue')
            )
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_revenue')
            ->limit($limit)
            ->toBase()
            ->get();

        return $topProducts->toArray();
    }

    /**
     * Get customer demographics
     */
    public function getCustomerDemographics(): array
    {
        $totalCustomers = Customer::count();

        // Customers by location (city)
        $byCity = Customer::select('city', DB::raw('COUNT(*) as count'))
            ->whereNotNull('city')
            ->groupBy('city')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->toArray();

        // Customers by state
        $byState = Customer::select('state', DB::raw('COUNT(*) as count'))
            ->whereNotNull('state')
            ->groupBy('state')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->toArray();

        // Customer segmentation by order count
        $customerSegments = DB::query()
            ->fromSub(
                Customer::query()
                    // Count only paid orders — pending/failed/cancelled orders would
                    // otherwise inflate a customer into a higher segment. Keep it in the
                    // JOIN (not a WHERE) so customers with no paid orders still show as 'No Orders'.
                    ->leftJoinSub(
                        Order::query()->select('orders.id', 'orders.customer_id')->where('orders.payment_status', 'paid'),
                        'orders',
                        'customers.id',
                        '=',
                        'orders.customer_id'
                    )
                    ->select(
                        'customers.id',
                        DB::raw("
                            CASE
                                WHEN COUNT(orders.id) = 0 THEN 'No Orders'
                                WHEN COUNT(orders.id) = 1 THEN 'One-time Buyer'
                                WHEN COUNT(orders.id) BETWEEN 2 AND 5 THEN 'Regular Customer'
                                WHEN COUNT(orders.id) > 5 THEN 'Loyal Customer'
                            END AS segment
                        ")
                    )
                    ->groupBy('customers.id'),
                'segmented_customers'
            )
            ->select('segment', DB::raw('COUNT(*) AS customer_count'))
            ->groupBy('segment')
            ->get()
            ->toArray();

        // Top customers by revenue
        $topCustomers = Customer::select(
            'customers.id',
            'customers.first_name',
            'customers.last_name',
            'customers.email',
            DB::raw('COUNT(orders.id) as order_count'),
            // Net of refunds — refunded orders stay payment_status='paid'.
            DB::raw('SUM(orders.total_amount - COALESCE(orders.refund_total, 0)) as lifetime_value')
        )
            ->joinSub(
                Order::query()->select('orders.id', 'orders.customer_id', 'orders.total_amount', 'orders.refund_total')->where('orders.payment_status', 'paid'),
                'orders',
                'customers.id',
                '=',
                'orders.customer_id'
            )
            ->groupBy('customers.id', 'customers.first_name', 'customers.last_name', 'customers.email')
            ->orderByDesc('lifetime_value')
            ->limit(10)
            ->get()
            ->toArray();

        return [
            'total_customers' => $totalCustomers,
            'by_city' => $byCity,
            'by_state' => $byState,
            'segments' => $customerSegments,
            'top_customers' => $topCustomers,
        ];
    }

    /**
     * Get inventory insights
     */
    public function getInventoryInsights(): array
    {
        // Low stock products — the list is capped for display; low_stock_count is the
        // true total so the dashboard stat doesn't silently plateau at the cap.
        $lowStockQuery = Product::whereNotNull('low_stock_threshold')
            ->whereColumn('inventory_count', '<=', 'low_stock_threshold');
        $lowStockCount = (clone $lowStockQuery)->count();
        $lowStockProducts = $lowStockQuery
            ->select('id', 'name', 'inventory_count', 'low_stock_threshold')
            ->orderBy('inventory_count')
            ->limit(20)
            ->get()
            ->toArray();

        // Out of stock products
        $outOfStockCount = Product::where('inventory_count', '<=', 0)->count();

        // Inventory value
        $inventoryValue = Product::where('inventory_count', '>', 0)
            ->select(DB::raw('SUM(inventory_count * price) as total_value'))
            ->value('total_value') ?? 0;

        // Products by stock status
        $stockStatus = [
            'out_of_stock' => Product::where('inventory_count', '<=', 0)->count(),
            'low_stock' => Product::whereNotNull('low_stock_threshold')
                ->whereColumn('inventory_count', '<=', 'low_stock_threshold')
                ->where('inventory_count', '>', 0)
                ->count(),
            'in_stock' => Product::where('inventory_count', '>', 0)
                ->where(function ($q) {
                    $q->whereNull('low_stock_threshold')
                        ->orWhereColumn('inventory_count', '>', 'low_stock_threshold');
                })
                ->count(),
        ];

        return [
            'low_stock_products' => $lowStockProducts,
            'low_stock_count' => $lowStockCount,
            'out_of_stock_count' => $outOfStockCount,
            'inventory_value' => round($inventoryValue, 2),
            'stock_status' => $stockStatus,
        ];
    }

    /**
     * Get recent orders
     */
    public function getRecentOrders(int $limit = 10): array
    {
        return Order::with('customer:id,first_name,last_name,email')
            ->orderByDesc('order_date')
            ->limit($limit)
            ->get()
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'order_date' => $order->order_date,
                    'customer_name' => $order->customer
                        ? $order->customer->first_name.' '.$order->customer->last_name
                        : 'Guest',
                    'total_amount' => $order->total_amount,
                    'payment_status' => $order->payment_status,
                    'shipping_status' => $order->shipping_status,
                ];
            })
            ->toArray();
    }
}
