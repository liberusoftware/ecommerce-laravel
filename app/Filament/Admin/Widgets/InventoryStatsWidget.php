<?php

namespace App\Filament\Admin\Widgets;

use App\Services\AnalyticsService;
use App\Support\StoreAccess;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class InventoryStatsWidget extends BaseWidget
{
    public static function canView(): bool
    {
        return StoreAccess::platform(auth()->user(), 'view_any_product');
    }

    protected static ?int $sort = 7;

    protected function getStats(): array
    {
        $analyticsService = app(AnalyticsService::class);
        $insights = $analyticsService->getInventoryInsights();

        return [
            Stat::make('Inventory Value', '$'.number_format($insights['inventory_value'], 2))
                ->description('Total value of current inventory')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color('success'),

            Stat::make('Low Stock Items', number_format($insights['low_stock_count']))
                ->description('Products below threshold')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('warning'),

            Stat::make('Out of Stock', number_format($insights['out_of_stock_count']))
                ->description('Products unavailable')
                ->descriptionIcon('heroicon-m-x-circle')
                ->color('danger'),
        ];
    }
}
