<?php

namespace App\Filament\App\Resources\ShippingMethods;

use App\Filament\App\Resources\ShippingMethods\Pages\ManageShippingMethods;
use App\Filament\Schemas\StoreField;
use App\Models\ShippingMethod;
use App\Support\Money;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Flat-rate and weight-based methods offered at checkout. Narrowed to the team's stores by the store scope. */
class ShippingMethodResource extends Resource
{
    protected static ?string $model = ShippingMethod::class;

    protected static bool $isScopedToTenant = false;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            StoreField::make(),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('estimated_delivery_time')->label('Delivery estimate')->placeholder('3–5 working days')->required()->maxLength(255),
            TextInput::make('base_rate')->label('Base rate')->numeric()->minValue(0)->required()->default(0)
                ->prefix(fn () => Money::currency()),
            TextInput::make('weight_rate')->label('Rate per kg')->numeric()->minValue(0)->default(0)
                ->prefix(fn () => Money::currency()),
            TextInput::make('max_weight')->label('Maximum weight')->numeric()->minValue(0)->suffix('kg')
                ->helperText('Leave empty for no limit.'),
            Toggle::make('is_active')->label('Offered at checkout')->default(true),
            Textarea::make('description')->maxLength(1000)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('store.name')->label('Store')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('base_rate')->money(fn ($record) => $record->store?->currency_code ?? Money::currency())->sortable(),
                TextColumn::make('weight_rate')->label('Per kg')->money(fn ($record) => $record->store?->currency_code ?? Money::currency()),
                TextColumn::make('estimated_delivery_time')->label('Delivery'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageShippingMethods::route('/'),
        ];
    }
}
