<?php

namespace App\Filament\App\Resources\ProductCategories;

use App\Filament\App\Resources\ProductCategories\Pages\CreateProductCategory;
use App\Filament\App\Resources\ProductCategories\Pages\EditProductCategory;
use App\Filament\App\Resources\ProductCategories\Pages\ListProductCategories;
use App\Filament\Schemas\CategoryForm;
use App\Filament\Schemas\CategoryTable;
use App\Models\ProductCategory;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class ProductCategoryResource extends Resource
{
    protected static ?string $model = ProductCategory::class;

    protected static ?string $modelLabel = 'category';

    protected static ?string $slug = 'categories';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return CategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CategoryTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductCategories::route('/'),
            'create' => CreateProductCategory::route('/create'),
            'edit' => EditProductCategory::route('/{record}/edit'),
        ];
    }
}
