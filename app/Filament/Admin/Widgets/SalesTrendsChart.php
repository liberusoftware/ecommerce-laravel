<?php

namespace App\Filament\Admin\Widgets;

use App\Services\AnalyticsService;
use App\Support\StoreAccess;
use Filament\Widgets\ChartWidget;

class SalesTrendsChart extends ChartWidget
{
    public static function canView(): bool
    {
        return StoreAccess::platform(auth()->user(), 'view_any_order');
    }

    protected ?string $heading = 'Sales Trends';

    protected static ?int $sort = 2;

    protected function getData(): array
    {
        $analyticsService = app(AnalyticsService::class);
        $trends = $analyticsService->getSalesTrends('daily');

        $labels = [];
        $revenueData = [];
        $orderData = [];

        foreach ($trends as $trend) {
            $labels[] = $trend['period'];
            $revenueData[] = $trend['total_revenue'];
            $orderData[] = $trend['order_count'];
        }

        return [
            'datasets' => [
                [
                    'label' => 'Revenue ($)',
                    'data' => $revenueData,
                    'borderColor' => 'rgb(59, 130, 246)',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                ],
                [
                    'label' => 'Orders',
                    'data' => $orderData,
                    'borderColor' => 'rgb(16, 185, 129)',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
