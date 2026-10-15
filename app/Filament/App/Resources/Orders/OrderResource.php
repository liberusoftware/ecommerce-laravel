<?php

namespace App\Filament\App\Resources\Orders;

use App\Filament\App\Resources\Orders\Pages\CreateOrder;
use App\Filament\App\Resources\Orders\Pages\EditOrder;
use App\Filament\App\Resources\Orders\Pages\ListOrders;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
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
            Select::make('customer_id')
                ->label('Customer')
                ->required()
                ->options(fn () => Customer::pluck('first_name', 'id')->toArray())
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
                        ->options(fn () => Product::orderBy('name')->pluck('name', 'id')->toArray()),
                    TextInput::make('quantity')
                        ->required()
                        ->integer()
                        ->minValue(1)
                        ->default(1),
                ]),

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

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('customer.first_name')
                ->label('Customer'),
            TextColumn::make('order_date')
                ->label('Order Date'),
            TextColumn::make('total_amount')
                ->label('Total Amount'),
            TextColumn::make('status')
                ->label('Status'),
            TextColumn::make('payment_status')
                ->label('Payment Status'),
            TextColumn::make('shipping_status')
                ->label('Shipping Status'),
        ])
            ->filters([])
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
