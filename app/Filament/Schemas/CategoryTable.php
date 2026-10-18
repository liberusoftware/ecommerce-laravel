<?php

namespace App\Filament\Schemas;

use App\Models\ProductCategory;
use App\Services\StoreContext;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** The category list both panels use. */
final class CategoryTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('parent')->withCount(['products', 'children']))
            ->defaultSort('name')
            ->columns([
                ImageColumn::make('image')->label('')->disk('public')->square(),
                TextColumn::make('name')->searchable()->sortable()
                    ->description(fn (ProductCategory $record) => $record->parent ? 'in '.$record->parent->name : null),
                TextColumn::make('store.name')->label('Store')->sortable()->visible(fn () => StoreContext::isPlatform()),
                TextColumn::make('slug')->searchable()->toggleable(),
                TextColumn::make('products_count')->label('Products')->sortable()->numeric(),
                TextColumn::make('children_count')->label('Subcategories')->sortable()->numeric(),
            ])
            ->filters([
                TernaryFilter::make('top_level')
                    ->label('Level')
                    ->trueLabel('Top-level')
                    ->falseLabel('Subcategories')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNull('parent_category_id'),
                        false: fn (Builder $query) => $query->whereNotNull('parent_category_id'),
                    ),
            ])
            ->recordActions([
                EditAction::make(),
                self::deleteAction(DeleteAction::make()),
            ]);
    }

    /** Deletion is refused with the reason, rather than cascading or failing on a foreign key. */
    public static function deleteAction(DeleteAction $action): DeleteAction
    {
        return $action
            ->modalDescription(fn (ProductCategory $record) => $record->deletionBlocker() ?? 'The category is removed from the storefront.')
            ->before(function (ProductCategory $record, DeleteAction $action): void {
                if ($reason = $record->deletionBlocker()) {
                    Notification::make()->danger()->title('This category cannot be deleted')->body($reason)->send();
                    $action->cancel();
                }
            });
    }
}
