<?php

namespace App\Filament\App\Resources\FacebookConnections;

use App\Filament\App\Resources\FacebookConnections\Pages\CreateFacebookConnection;
use App\Filament\App\Resources\FacebookConnections\Pages\EditFacebookConnection;
use App\Filament\App\Resources\FacebookConnections\Pages\ListFacebookConnections;
use App\Models\FacebookConnection;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FacebookConnectionResource extends Resource
{
    protected static ?string $model = FacebookConnection::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-share';

    protected static ?string $modelLabel = 'Facebook Catalog';

    protected static ?string $pluralModelLabel = 'Facebook Catalog';

    protected static ?int $navigationSort = 11;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Meta Commerce credentials')
                    ->description('Listed products sync into this Catalog, which is what Page Shop, Instagram Shopping and Marketplace listings read. Marketplace itself has no public API.')
                    ->schema([
                        TextInput::make('access_token')
                            ->label('System User access token')
                            ->password()
                            ->revealable()
                            ->required()
                            ->helperText('Stored encrypted at rest.')
                            ->maxLength(1024),
                        TextInput::make('catalog_id')
                            ->label('Catalog ID')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('business_id')
                            ->label('Business ID')
                            ->maxLength(255),
                        Select::make('graph_version')
                            ->label('Graph API version')
                            ->options([
                                'v21.0' => 'v21.0',
                                'v20.0' => 'v20.0',
                                'v19.0' => 'v19.0',
                            ])
                            ->default('v21.0')
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('catalog_id')->label('Catalog ID'),
                TextColumn::make('business_id')->label('Business ID'),
                TextColumn::make('graph_version')->label('Graph version'),
                TextColumn::make('updated_at')->label('Updated')->dateTime(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    /** One Catalog per Team — the unique index would refuse a second anyway. */
    public static function canCreate(): bool
    {
        return parent::canCreate() && ! static::getEloquentQuery()->exists();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFacebookConnections::route('/'),
            'create' => CreateFacebookConnection::route('/create'),
            'edit' => EditFacebookConnection::route('/{record}/edit'),
        ];
    }
}
