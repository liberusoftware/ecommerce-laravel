<?php

namespace App\Filament\Admin\Resources\Menus;

use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Admin\Resources\Menus\Pages\ListMenus;
use App\Filament\Admin\Resources\Menus\Pages\CreateMenu;
use App\Filament\Admin\Resources\Menus\Pages\EditMenu;
use App\Filament\Admin\Resources\MenuResource\Pages;
use App\Filament\Admin\Resources\MenuResource\RelationManagers;
use App\Models\Menu;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class MenuResource extends Resource
{
    protected static ?string $model = Menu::class;

    /*
     * Not tenant-scoped, because there is nothing to scope by: this panel is
     * Team-tenanted on the `team` relationship, and menus has no team_id
     * column. Filament would emit whereBelongsTo($tenant, 'team') and the query
     * would name a column that is not there.
     *
     * The consequence is that every tenant sees the same menus and their items. That is not
     * a design intent, it is the current data model stated out loud — #958.
     * The column, and the scoping that becomes possible with it, land in wave
     * 1.5, where existing rows are attributed deliberately rather than defaulted
     * to team 1.
     */
    protected static bool $isScopedToTenant = false;


    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-bars-4';

    protected static ?int $navigationSort = 9;

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
                TextColumn::make('name'),
                TextColumn::make('url'),
                TextColumn::make('parent.name'),
                TextColumn::make('order'),
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
            'index' => ListMenus::route('/'),
            'create' => CreateMenu::route('/create'),
            'edit' => EditMenu::route('/{record}/edit'),
        ];
    }
}
