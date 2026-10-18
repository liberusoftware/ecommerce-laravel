<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Widgets\CustomerDemographicsWidget;
use App\Filament\Admin\Widgets\CustomerGrowthWidget;
use App\Filament\Admin\Widgets\InventoryStatsWidget;
use App\Filament\Admin\Widgets\LowStockInventoryWidget;
use App\Filament\Admin\Widgets\RecentOrdersWidget;
use App\Filament\Admin\Widgets\SalesOverviewWidget;
use App\Filament\Admin\Widgets\SalesTrendsChart;
use App\Filament\Admin\Widgets\TopProductsWidget;
use App\Support\StoreAccess;
use Filament\Pages\Page;

class Reports extends Page
{
    public static function canAccess(): bool
    {
        return StoreAccess::platform(auth()->user(), 'view_any_order');
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-pie';

    protected string $view = 'filament.admin.pages.reports';

    protected static ?int $navigationSort = 8;

    protected function getHeaderWidgets(): array
    {
        return [
            SalesOverviewWidget::class,
            InventoryStatsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 2;
    }

    protected function getFooterWidgets(): array
    {
        return [
            SalesTrendsChart::class,
            CustomerDemographicsWidget::class,
            CustomerGrowthWidget::class,
            TopProductsWidget::class,
            LowStockInventoryWidget::class,
            RecentOrdersWidget::class,
        ];
    }
}
