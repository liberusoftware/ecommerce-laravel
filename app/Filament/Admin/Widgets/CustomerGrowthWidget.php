<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Customer;
use App\Support\StoreAccess;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class CustomerGrowthWidget extends ChartWidget
{
    public static function canView(): bool
    {
        return StoreAccess::platform(auth()->user(), 'view_any_customer');
    }

    protected ?string $heading = 'Customer Growth';

    protected static ?int $sort = 8;

    protected function getData(): array
    {
        $data = Customer::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('COUNT(*) as count')
        )
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $labels = [];
        $values = [];

        foreach ($data as $item) {
            $labels[] = $item->date;
            $values[] = $item->count;
        }

        return [
            'datasets' => [
                [
                    'label' => 'New Customers',
                    'data' => $values,
                    'borderColor' => 'rgb(147, 51, 234)',
                    'backgroundColor' => 'rgba(147, 51, 234, 0.1)',
                    'fill' => true,
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
