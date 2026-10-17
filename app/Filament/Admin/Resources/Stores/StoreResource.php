<?php

namespace App\Filament\Admin\Resources\Stores;

use App\Filament\Admin\Resources\Stores\Pages\CreateStore;
use App\Filament\Admin\Resources\Stores\Pages\EditStore;
use App\Filament\Admin\Resources\Stores\Pages\ListStores;
use App\Models\Store;
use App\Models\Team;
use App\Support\Installation;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Stores own commerce data. Platform-wide: not scoped to the panel's tenant.
 */
class StoreResource extends Resource
{
    protected static ?string $model = Store::class;

    protected static bool $isScopedToTenant = false;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('slug')
                ->required()
                ->alphaDash()
                ->maxLength(255)
                ->unique(ignoreRecord: true),
            Select::make('team_id')
                ->label('Team')
                ->required()
                ->searchable()
                ->options(fn () => Team::orderBy('name')->pluck('name', 'id')->toArray())
                // A store's rows carry its team, so the store does not move between teams.
                ->disabledOn('edit')
                ->dehydrated(fn (string $operation): bool => $operation === 'create')
                ->rule(fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                    $team = Team::find($value);

                    if ($team && ! Installation::canCreateStoreFor($team)) {
                        $fail('This installation does not allow another store for this team.');
                    }
                }, fn (string $operation): bool => $operation === 'create'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug'),
                TextColumn::make('team.name')->label('Team'),
                IconColumn::make('main_site')
                    ->label('Main site')
                    ->boolean()
                    ->state(fn (Store $record): bool => $record->servesMainSite()),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ChannelsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStores::route('/'),
            'create' => CreateStore::route('/create'),
            'edit' => EditStore::route('/{record}/edit'),
        ];
    }
}
