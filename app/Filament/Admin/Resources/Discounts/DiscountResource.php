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
use Filament\Tables\Table;

class DiscountResource extends Resource
{
    protected static ?string $model = Discount::class;

    /*
     * Not tenant-scoped, because there is nothing to scope by: this panel is
     * Team-tenanted on the `team` relationship, and discounts has no team_id
     * column. Filament would emit whereBelongsTo($tenant, 'team') and the query
     * would name a column that is not there.
     *
     * The consequence is that every tenant sees the same discounts. That is not
     * a design intent, it is the current data model stated out loud — #958.
     * The column, and the scoping that becomes possible with it, land in wave
     * 1.5, where existing rows are attributed deliberately rather than defaulted
     * to team 1.
     */
    protected static bool $isScopedToTenant = false;

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
            ->columns([
                //
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
