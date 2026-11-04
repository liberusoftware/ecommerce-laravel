<?php

namespace App\Filament\Schemas;

use App\Jobs\SyncProductToFacebookCatalog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\StoreContext;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
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
use Illuminate\Support\Str;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The product list both panels use. */
final class ProductTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('variants'))
            ->columns([
                ImageColumn::make('featured_image')->label('')->disk('public')->square(),
                TextColumn::make('name')->searchable()->sortable()
                    ->description(fn (Product $record) => $record->sku ? 'SKU '.$record->sku : null),
                TextColumn::make('store.name')->label('Store')->sortable()->visible(fn () => StoreContext::isPlatform()),
                TextColumn::make('sku')->label('SKU')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->badge()->sortable()
                    ->formatStateUsing(fn (string $state) => Product::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Product::STATUS_ACTIVE => 'success',
                        Product::STATUS_DRAFT => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('price')->sortable()
                    ->formatStateUsing(fn (Product $record, $state) => self::priceLabel($record, $state))
                    ->description(fn (Product $record) => $record->isOnSale()
                        ? 'On sale: '.Money::format($record->sale_price, $record->store?->currency_code)
                        : null),
                TextColumn::make('inventory_count')->label('Stock')->sortable()
                    ->formatStateUsing(fn (Product $record, $state) => self::stockLabel($record, $state))
                    ->color(fn (Product $record) => ! $record->isInStock() ? 'danger' : ($record->isLowStock() ? 'warning' : null)),
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
                        true: fn (Builder $query) => $query->where(fn ($q) => $q
                            ->where(fn ($q) => $q->withoutVariants()->where(fn ($q) => $q->where('track_inventory', false)->orWhere('inventory_count', '>', 0)))
                            ->orWhereHas('variants', fn ($q) => $q->available()->where(fn ($q) => $q->where('track_inventory', false)->orWhere('inventory_count', '>', 0)))),
                        false: fn (Builder $query) => $query->where(fn ($q) => $q
                            ->where(fn ($q) => $q->withoutVariants()->where('track_inventory', true)->where('inventory_count', '<=', 0))
                            ->orWhere(fn ($q) => $q->whereHas('variants')->whereDoesntHave('variants', fn ($q) => $q->available()->where(fn ($q) => $q->where('track_inventory', false)->orWhere('inventory_count', '>', 0))))),
                    ),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('adjustInventory')
                    ->label('Adjust stock')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->visible(fn (Product $record) => $record->tracksInventory() || $record->variants->contains->tracksInventory())
                    ->authorize(fn (Product $record) => auth()->user()?->can('update', $record))
                    ->schema(fn (Product $record) => [
                        Select::make('variant_id')
                            ->label('Variant')
                            ->options($record->variants->filter->tracksInventory()
                                ->mapWithKeys(fn (ProductVariant $v) => [$v->id => $v->label().' — '.number_format($v->inventory_count).' in stock'])->all())
                            ->required()
                            ->visible($record->hasVariants()),
                        TextInput::make('adjustment')
                            ->label('Change')
                            ->required()
                            ->integer()
                            ->notIn([0])
                            ->helperText('Positive to add stock, negative to remove it.'),
                        TextInput::make('reason')->required()->maxLength(255)->default('stock count'),
                    ])
                    ->action(function (Product $record, array $data): void {
                        $holder = $record->hasVariants() ? $record->variants->firstWhere('id', (int) ($data['variant_id'] ?? 0)) : $record;

                        // Guarded atomic adjustment, logged; refuses to go below zero.
                        if ($holder === null || ! $holder->tracksInventory() || ! $holder->adjustInventory((int) $data['adjustment'], $data['reason'])) {
                            Notification::make()->title('Stock cannot go below zero.')->danger()->send();

                            return;
                        }

                        Notification::make()->title('Stock adjusted.')->success()->send();
                    }),
                Action::make('repushFacebook')
                    ->label('Re-push to Facebook')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (Product $record) => $record->list_on_facebook && $record->isActive())
                    ->authorize(fn (Product $record) => auth()->user()?->can('update', $record))
                    ->action(function (Product $record): void {
                        SyncProductToFacebookCatalog::dispatch($record->id);

                        Notification::make()->title('Queued. The Catalog status updates shortly.')->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::statusAction('publish', 'Make active', Product::STATUS_ACTIVE, 'heroicon-o-eye'),
                    self::statusAction('draft', 'Move to draft', Product::STATUS_DRAFT, 'heroicon-o-pencil'),
                    self::statusAction('archive', 'Archive', Product::STATUS_ARCHIVED, 'heroicon-o-archive-box'),
                    BulkAction::make('export')
                        ->label('Export CSV')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(fn (Collection $records) => self::export($records)),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** Streamed, never written to a disk the web server can serve. */
    private static function export(Collection $records): StreamedResponse
    {
        return response()->streamDownload(function () use ($records): void {
            $csv = Writer::createFromStream(fopen('php://output', 'w'));
            $csv->insertOne(['Name', 'Variant', 'SKU', 'Status', 'Category', 'Price', 'Sale price', 'Stock', 'Low-stock alert']);

            foreach ($records as $product) {
                // One row per variant: each is a stock line of its own, and the product holds none.
                if ($product->hasVariants()) {
                    foreach ($product->variants as $variant) {
                        $csv->insertOne([
                            $product->name, $variant->label(), $variant->sku, $variant->is_active ? $product->status : 'unavailable', $product->category?->name,
                            $variant->price, null, $variant->tracksInventory() ? $variant->inventory_count : null, $product->low_stock_threshold,
                        ]);
                    }

                    continue;
                }

                $csv->insertOne([
                    $product->name, null, $product->sku, $product->status, $product->category?->name,
                    $product->price, $product->sale_price, $product->tracksInventory() ? $product->inventory_count : null, $product->low_stock_threshold,
                ]);
            }
        }, 'products-'.date('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    private static function priceLabel(Product $product, $price): string
    {
        $currency = $product->store?->currency_code ?? Money::currency();
        $range = $product->hasVariants() ? $product->variantPriceRange() : null;

        if ($range !== null && $range[0] !== $range[1]) {
            return Money::format($range[0], $currency).' – '.Money::format($range[1], $currency);
        }

        return Money::format($range[0] ?? (float) $price, $currency);
    }

    private static function stockLabel(Product $product, $state): string
    {
        if (! $product->hasVariants()) {
            return $product->tracksInventory() ? number_format((int) $state) : 'Not tracked';
        }

        $tracked = $product->variants->filter->tracksInventory();
        $count = $product->variants->count();
        $label = $count.' '.Str::plural('variant', $count);

        return $tracked->isEmpty() ? $label.', not tracked' : number_format($tracked->sum('inventory_count')).' across '.$label;
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
