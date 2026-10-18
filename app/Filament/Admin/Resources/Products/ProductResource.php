<?php

namespace App\Filament\Admin\Resources\Products;

use App\Filament\Admin\Resources\Products\Pages\CreateProduct;
use App\Filament\Admin\Resources\Products\Pages\EditProduct;
use App\Filament\Admin\Resources\Products\Pages\ListProducts;
use App\Filament\Schemas\ProductForm;
use App\Filament\Schemas\ProductTable;
use App\Models\Product;
use Filament\Actions\BulkAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use League\Csv\Writer;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductTable::configure($table)
            ->pushToolbarActions([
                BulkAction::make('export')
                    ->label('Export selected')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn (Collection $records) => static::export($records)),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }

    protected static function export(Collection $records)
    {
        $csv = Writer::createFromString('');

        $csv->insertOne(['Name', 'SKU', 'Category', 'Price', 'Inventory Count', 'Low Stock Threshold', 'Stock status', 'Product status']);

        foreach ($records as $record) {
            $csv->insertOne([
                $record->name,
                $record->sku,
                $record->category?->name,
                $record->price,
                $record->inventory_count,
                $record->low_stock_threshold,
                $record->inventory_count > $record->low_stock_threshold ? 'In Stock' : ($record->inventory_count > 0 ? 'Low Stock' : 'Out of Stock'),
                $record->status,
            ]);
        }

        $filename = 'inventory_report_'.date('Y-m-d').'.csv';
        $path = storage_path('app/public/'.$filename);
        file_put_contents($path, $csv->getContent());

        return response()->download($path)->deleteFileAfterSend();
    }
}
