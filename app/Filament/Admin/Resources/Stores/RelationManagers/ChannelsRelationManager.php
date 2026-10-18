<?php

namespace App\Filament\Admin\Resources\Stores\RelationManagers;

use App\Filament\Admin\Resources\Channels\ChannelResource;
use App\Models\Channel;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChannelsRelationManager extends RelationManager
{
    protected static string $relationship = 'channels';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            // `theme` stays at its default until theme packages select per channel.
            TextInput::make('name')->required()->maxLength(255),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('primary_host')
                    ->label('Primary domain')
                    ->state(fn (Channel $record): ?string => $record->primaryDomain()?->host),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                Action::make('domains')
                    ->label('Domains')
                    ->url(fn (Channel $record): string => ChannelResource::getUrl('edit', ['record' => $record])),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
