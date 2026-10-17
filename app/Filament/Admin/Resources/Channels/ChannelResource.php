<?php

namespace App\Filament\Admin\Resources\Channels;

use App\Filament\Admin\Resources\Channels\Pages\EditChannel;
use App\Filament\Admin\Resources\Channels\Pages\ListChannels;
use App\Models\Channel;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * A way customers reach a store; its domains are managed here. Reached from the
 * store it belongs to. Platform-wide: not scoped to the panel's tenant.
 */
class ChannelResource extends Resource
{
    protected static ?string $model = Channel::class;

    protected static bool $isScopedToTenant = false;

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('theme')->required()->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('store.name')->label('Store'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\DomainsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChannels::route('/'),
            'edit' => EditChannel::route('/{record}/edit'),
        ];
    }
}
