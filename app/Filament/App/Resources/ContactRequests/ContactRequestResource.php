<?php

namespace App\Filament\App\Resources\ContactRequests;

use App\Filament\App\Resources\ContactRequests\Pages\ListContactRequests;
use App\Filament\App\Resources\ContactRequests\Pages\ViewContactRequest;
use App\Models\ContactRequest;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ContactRequestResource extends Resource
{
    protected static ?string $model = ContactRequest::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $modelLabel = 'Message';

    protected static ?string $navigationLabel = 'Messages';

    protected static ?int $navigationSort = 9;

    public static function getNavigationBadge(): ?string
    {
        $unread = ContactRequest::unread()->count();

        return $unread > 0 ? (string) $unread : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name'),
                TextEntry::make('email')->copyable(),
                TextEntry::make('subject')->placeholder('No subject'),
                TextEntry::make('created_at')->label('Received')->dateTime(),
                TextEntry::make('message')->columnSpanFull()->prose(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->weight(fn (ContactRequest $record) => $record->read_at ? null : 'bold'),
                TextColumn::make('email')->searchable()->copyable(),
                TextColumn::make('subject')->placeholder('No subject')->limit(40),
                TextColumn::make('message')->limit(60),
                TextColumn::make('created_at')->label('Received')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('read_at')->label('Read')->nullable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContactRequests::route('/'),
            'view' => ViewContactRequest::route('/{record}'),
        ];
    }
}
