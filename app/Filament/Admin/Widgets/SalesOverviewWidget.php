<?php

namespace App\Filament\Admin\Widgets;

use App\Services\AnalyticsService;
use App\Support\StoreAccess;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SalesOverviewWidget extends BaseWidget
{
    public static function canView(): bool
    {
        return StoreAccess::platform(auth()->user(), 'view_any_order');
    }

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $analyticsService = app(AnalyticsService::class);
        $metrics = $analyticsService->getSalesMetrics();

        return [
            Stat::make('Total Revenue', '$'.number_format($metrics['total_revenue'], 2))
                ->description($metrics['revenue_growth'] >= 0
                    ? $metrics['revenue_growth'].'% increase'
                    : abs($metrics['revenue_growth']).'% decrease')
                ->descriptionIcon($metrics['revenue_growth'] >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($metrics['revenue_growth'] >= 0 ? 'success' : 'danger')
                ->chart([7, 3, 4, 5, 6, 3, 5, 3]),

            Stat::make('Total Orders', number_format($metrics['order_count']))
                ->description($metrics['order_growth'] >= 0
                    ? $metrics['order_growth'].'% increase'
                    : abs($metrics['order_growth']).'% decrease')
                ->descriptionIcon($metrics['order_growth'] >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($metrics['order_growth'] >= 0 ? 'success' : 'danger')
                ->chart([7, 3, 4, 5, 6, 3, 5, 3]),

            Stat::make('Average Order Value', '$'.number_format($metrics['avg_order_value'], 2))
                ->description('Last 30 days')
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->color('primary')
                ->chart([7, 3, 4, 5, 6, 3, 5, 3]),
        ];
    }
}
