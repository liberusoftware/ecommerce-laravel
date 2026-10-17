<?php

namespace App\Filament\Admin\Resources\Channels\RelationManagers;

use App\Models\ChannelDomain;
use App\Models\Store;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DomainsRelationManager extends RelationManager
{
    protected static string $relationship = 'domains';

    /** A host name, optionally with a port; no scheme, path or spaces. */
    private const HOST = '/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('host')
                ->label('Host name')
                ->helperText('For example shop.example.com. No https:// or path.')
                ->required()
                ->maxLength(253)
                ->dehydrateStateUsing(fn (?string $state): string => ChannelDomain::normalise((string) $state))
                ->rule(fn (?ChannelDomain $record): Closure => function (string $attribute, $value, Closure $fail) use ($record): void {
                    $host = ChannelDomain::normalise((string) $value);

                    if (str_contains((string) $value, '/') || ! preg_match(self::HOST, $host)) {
                        $fail('Enter a host name such as shop.example.com.');

                        return;
                    }

                    if (ChannelDomain::where('host', $host)->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))->exists()) {
                        $fail('This host name is already mapped to a channel.');
                    }
                }),
            Toggle::make('is_primary')->label('Primary'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('host')
            ->columns([
                TextColumn::make('host'),
                IconColumn::make('is_primary')->label('Primary')->boolean(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription(fn (ChannelDomain $record): string => $record->host === Store::mainSiteHost()
                        ? 'This is the main site\'s address. Removing it takes the main site offline until the host is mapped to a channel again.'
                        : 'The host will stop serving this store.'),
            ]);
    }
}
