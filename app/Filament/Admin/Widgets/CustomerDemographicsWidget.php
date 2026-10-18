<?php

namespace App\Filament\Admin\Widgets;

use App\Services\AnalyticsService;
use App\Support\StoreAccess;
use Filament\Widgets\ChartWidget;

class CustomerDemographicsWidget extends ChartWidget
{
    public static function canView(): bool
    {
        return StoreAccess::platform(auth()->user(), 'view_any_customer');
    }

    protected ?string $heading = 'Customer Segments';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 1;

    protected function getData(): array
    {
        $analyticsService = app(AnalyticsService::class);
        $demographics = $analyticsService->getCustomerDemographics();

        $labels = [];
        $data = [];

        foreach ($demographics['segments'] as $segment) {
            $labels[] = $segment->segment;
            $data[] = $segment->customer_count;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Customers',
                    'data' => $data,
                    'backgroundColor' => [
                        'rgb(59, 130, 246)',
                        'rgb(16, 185, 129)',
                        'rgb(251, 191, 36)',
                        'rgb(239, 68, 68)',
                    ],
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
