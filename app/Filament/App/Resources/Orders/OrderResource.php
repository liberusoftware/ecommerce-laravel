<?php

namespace App\Filament\App\Resources\Orders;

use App\Filament\App\Resources\Orders\Pages\CreateOrder;
use App\Filament\App\Resources\Orders\Pages\EditOrder;
use App\Filament\App\Resources\Orders\Pages\ListOrders;
use App\Filament\Schemas\StoreField;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\StoreContext;
use App\Support\Money;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        // Money and order state are never form fields: the total comes from the
        // line items, and status changes are header actions on EditOrder that
        // go through Order::transitionTo().
        return $schema->components([
            StoreField::make(),

            // The order's customer and products come from the order's own store.
            Select::make('customer_id')
                ->label('Customer')
                ->required()
                ->searchable()
                ->options(fn (Get $get, ?Order $record) => Customer::withoutGlobalScope('store')
                    ->where('store_id', $record?->store_id ?? $get('store_id'))
                    ->get()
                    ->mapWithKeys(fn (Customer $c) => [$c->id => trim("{$c->first_name} {$c->last_name} <{$c->email}>")])
                    ->all())
                ->in(fn (Get $get, ?Order $record) => Customer::withoutGlobalScope('store')
                    ->where('store_id', $record?->store_id ?? $get('store_id'))->pluck('id')->all())
                ->disabledOn('edit'),

            DatePicker::make('order_date')
                ->label('Order Date')
                ->required()
                ->default(now())
                ->disabledOn('edit'),

            Select::make('shipping_status')
                ->label('Shipping Status')
                ->required()
                ->default('pending')
                ->options([
                    'pending' => 'Pending',
                    'shipped' => 'Shipped',
                    'delivered' => 'Delivered',
                    'returned' => 'Returned',
                ]),

            Repeater::make('items')
                ->label('Line Items')
                ->visibleOn('create')
                ->required()
                ->minItems(1)
                ->columns(2)
                ->schema([
                    Select::make('product_id')
                        ->label('Product')
                        ->required()
                        ->searchable()
                        ->options(fn (Get $get) => self::sellableProducts($get('../../store_id')))
                        ->in(fn (Get $get) => array_keys(self::sellableProducts($get('../../store_id')))),
                    TextInput::make('quantity')
                        ->required()
                        ->integer()
                        ->minValue(1)
                        ->default(1),
                ]),

            // What was sold, as frozen on the order: name, choices and agreed price.
            TextEntry::make('lines')
                ->label('Line Items')
                ->state(fn (?Order $record) => $record?->items->map(fn (OrderItem $item) => $item->quantity.' × '.$item->displayName()
                    .($item->options ? ' — '.implode('; ', $item->optionLabels($record->currency_code)) : '')
                    .' @ '.Money::format($item->price, $record->currency_code))->all())
                ->listWithLineBreaks()
                ->visibleOn('edit')
                ->columnSpanFull(),

            TextInput::make('status')
                ->disabled()
                ->dehydrated(false)
                ->visibleOn('edit'),

            TextInput::make('payment_status')
                ->label('Payment Status')
                ->disabled()
                ->dehydrated(false)
                ->visibleOn('edit'),

            TextInput::make('total_amount')
                ->label('Total Amount')
                ->disabled()
                ->dehydrated(false)
                ->visibleOn('edit'),
        ]);
    }

    /** @return array<int, string> active products of one store */
    private static function sellableProducts(mixed $storeId): array
    {
        return Product::withoutGlobalScope('store')
            ->where('store_id', $storeId)
            ->active()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('#')->sortable(),
            TextColumn::make('store.name')
                ->label('Store')
                ->visible(fn () => StoreContext::isPlatform()),
            TextColumn::make('customer_email')
                ->label('Customer')
                ->searchable()
                ->description(fn (Order $record) => $record->customer ? trim($record->customer->first_name.' '.$record->customer->last_name) : null),
            TextColumn::make('created_at')
                ->label('Placed')
                ->dateTime()
                ->sortable(),
            TextColumn::make('total_amount')
                ->label('Total')
                ->money(fn (Order $record) => $record->currency_code ?: Money::currency())
                ->sortable(),
            TextColumn::make('status')
                ->badge()
                ->sortable(),
            TextColumn::make('payment_status')
                ->label('Payment')
                ->badge(),
            TextColumn::make('shipping_status')
                ->label('Shipping')
                ->badge(),
        ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->options(array_combine(array_keys(Order::TRANSITIONS), array_map('ucfirst', str_replace('_', ' ', array_keys(Order::TRANSITIONS))))),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'create' => CreateOrder::route('/create'),
            'edit' => EditOrder::route('/{record}/edit'),
        ];
    }
}
