<?php

namespace App\Filament\Schemas;

use App\Models\Product;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** The product list both panels use. */
final class ProductTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('featured_image')->label('')->disk('public')->square(),
                TextColumn::make('name')->searchable()->sortable()
                    ->description(fn (Product $record) => $record->sku ? 'SKU '.$record->sku : null),
                TextColumn::make('sku')->label('SKU')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->badge()->sortable()
                    ->formatStateUsing(fn (string $state) => Product::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Product::STATUS_ACTIVE => 'success',
                        Product::STATUS_DRAFT => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('price')->sortable()
                    ->money(fn (Product $record) => $record->store?->currency_code ?? Money::currency())
                    ->description(fn (Product $record) => $record->isOnSale()
                        ? 'On sale: '.Money::format($record->sale_price, $record->store?->currency_code)
                        : null),
                TextColumn::make('inventory_count')->label('Stock')->sortable()->numeric()
                    ->color(fn (Product $record) => $record->inventory_count <= 0 ? 'danger' : ($record->isLowStock() ? 'warning' : null)),
                TextColumn::make('category.name')->label('Category')->sortable()->toggleable(),
                IconColumn::make('is_featured')->label('Featured')->boolean()->toggleable(),
                TextColumn::make('visibility')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(Product::STATUSES),
                SelectFilter::make('category')->relationship('category', 'name'),
                TernaryFilter::make('is_featured')->label('Featured'),
                TernaryFilter::make('in_stock')
                    ->label('Stock')
                    ->trueLabel('In stock')
                    ->falseLabel('Out of stock')
                    ->queries(
                        true: fn (Builder $query) => $query->where('inventory_count', '>', 0),
                        false: fn (Builder $query) => $query->where('inventory_count', '<=', 0),
                    ),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('adjustInventory')
                    ->label('Adjust stock')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->authorize(fn (Product $record) => auth()->user()?->can('update', $record))
                    ->schema([
                        TextInput::make('adjustment')
                            ->label('Change')
                            ->required()
                            ->integer()
                            ->notIn([0])
                            ->helperText('Positive to add stock, negative to remove it.'),
                        TextInput::make('reason')->required()->maxLength(255)->default('stock count'),
                    ])
                    ->action(function (Product $record, array $data): void {
                        // Guarded atomic adjustment, logged; refuses to go below zero.
                        if (! $record->adjustInventory((int) $data['adjustment'], $data['reason'])) {
                            Notification::make()->title('Stock cannot go below zero.')->danger()->send();

                            return;
                        }

                        Notification::make()->title('Stock adjusted.')->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::statusAction('publish', 'Make active', Product::STATUS_ACTIVE, 'heroicon-o-eye'),
                    self::statusAction('draft', 'Move to draft', Product::STATUS_DRAFT, 'heroicon-o-pencil'),
                    self::statusAction('archive', 'Archive', Product::STATUS_ARCHIVED, 'heroicon-o-archive-box'),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function statusAction(string $name, string $label, string $status, string $icon): BulkAction
    {
        return BulkAction::make($name)
            ->label($label)
            ->icon($icon)
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) use ($status): void {
                $records->filter(fn (Product $product) => auth()->user()?->can('update', $product))
                    ->each(fn (Product $product) => $product->update(['status' => $status]));
            });
    }
}
