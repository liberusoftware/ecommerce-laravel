<?php

namespace App\Filament\App\Resources\Invoices;

use App\Filament\App\Resources\Invoices\Pages\ListInvoices;
use App\Filament\App\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Invoice;
use App\Models\OrderItem;
use App\Support\Money;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Invoices are issued when an order is paid and are never written by hand:
 * the list and the view are read-only, and the policy refuses create, update
 * and delete.
 */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?int $navigationSort = 10;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Invoice')
                ->columns(3)
                ->schema([
                    TextEntry::make('id')->label('Invoice')->prefix('#'),
                    TextEntry::make('order_id')->label('Order')->prefix('#'),
                    TextEntry::make('invoice_date')->date(),
                    TextEntry::make('customer_name')
                        ->label('Billed to')
                        ->state(fn (Invoice $record): string => trim($record->customer?->first_name.' '.$record->customer?->last_name)),
                    TextEntry::make('customer.email')->label('Email'),
                    TextEntry::make('payment_status'),
                ]),
            Section::make('Lines')
                ->schema([
                    TextEntry::make('lines')
                        ->hiddenLabel()
                        ->state(fn (Invoice $record) => $record->lines->map(fn (OrderItem $line) => $line->quantity.' × '.$line->displayName()
                            .($line->options ? ' — '.implode('; ', $line->optionLabels($record->currency())) : '')
                            .' @ '.Money::format($line->price, $record->currency()))->all())
                        ->listWithLineBreaks(),
                    TextEntry::make('total_amount')
                        ->label('Total')
                        ->state(fn (Invoice $record) => Money::format($record->total_amount, $record->currency())),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['customer', 'order']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('Invoice')->prefix('#')->sortable(),
                // Customer has first_name and last_name; there is no name column.
                TextColumn::make('customer_name')
                    ->label('Customer')
                    ->state(fn (Invoice $record): string => trim($record->customer?->first_name.' '.$record->customer?->last_name)),
                TextColumn::make('order.id')
                    ->label('Order'),
                TextColumn::make('invoice_date')
                    ->label('Invoice Date')
                    ->date()
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->label('Total Amount')
                    ->state(fn (Invoice $record) => Money::format($record->total_amount, $record->currency())),
                TextColumn::make('payment_status')
                    ->label('Payment Status'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'view' => ViewInvoice::route('/{record}'),
        ];
    }
}
