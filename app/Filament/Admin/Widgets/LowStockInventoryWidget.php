<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Product;
use App\Services\AnalyticsService;
use App\Support\StoreAccess;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LowStockInventoryWidget extends BaseWidget
{
    public static function canView(): bool
    {
        return StoreAccess::platform(auth()->user(), 'view_any_product');
    }

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $analyticsService = app(AnalyticsService::class);
        $insights = $analyticsService->getInventoryInsights();
        $lowStockProducts = $insights['low_stock_products'];

        if (empty($lowStockProducts)) {
            return $table
                ->heading('Low Stock Products')
                ->query(fn () => Product::query()->whereRaw('1 = 0'))
                ->columns([
                    Tables\Columns\TextColumn::make('name')
                        ->label('No low stock products'),
                ])
                ->paginated(false);
        }

        return $table
            ->heading('Low Stock Products')
            ->query(
                fn () => Product::query()->whereIn('id', array_column($lowStockProducts, 'id'))
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Product Name')
                    ->searchable()
                    ->sortable(),
                // Tables\Columns\TextColumn::make('inventory_count')
                //     ->label('Current Stock')
                //     ->numeric()
                //     ->sortable()
                //     ->color('danger'),
                Tables\Columns\TextColumn::make('low_stock_threshold')
                    ->label('Threshold')
                    ->numeric()
                    ->sortable(),
            ])
            ->paginated([10, 25, 50]);
    }
}
