<?php

namespace App\Filament\Admin\Resources\Discounts;

use App\Filament\Admin\Resources\Discounts\Pages\CreateDiscount;
use App\Filament\Admin\Resources\Discounts\Pages\EditDiscount;
use App\Filament\Admin\Resources\Discounts\Pages\ListDiscounts;
use App\Models\Discount;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DiscountResource extends Resource
{
    protected static ?string $model = Discount::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // The table had no columns at all, which is how a resource listing
            // every merchant's discounts managed to look like it was listing
            // nothing. A row has to be visible before anyone can notice it is
            // the wrong row.
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('code')->searchable(),
                TextColumn::make('type'),
                TextColumn::make('value'),
                IconColumn::make('is_active')->boolean(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
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
            'index' => ListDiscounts::route('/'),
            'create' => CreateDiscount::route('/create'),
            'edit' => EditDiscount::route('/{record}/edit'),
        ];
    }
}
