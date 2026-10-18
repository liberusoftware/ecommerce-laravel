<?php

namespace App\Filament\Schemas;

use App\Models\Store;
use App\Services\StoreContext;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Validation\Rule;

/**
 * Which store a new record belongs to: one of the team's in /app, any in /admin.
 * Shown only when there are several to choose from; with one, it is that one.
 * Without it, a team with several stores, or the platform, files the row under none.
 */
final class StoreField
{
    public static function make(): Select
    {
        return Select::make('store_id')
            ->label('Store')
            ->options(fn () => self::storeOptions())
            ->default(fn () => array_key_first(self::storeOptions()))
            ->required()
            ->rule(fn () => Rule::in(array_keys(self::storeOptions())))
            ->hidden(fn () => count(self::storeOptions()) < 2)
            ->dehydratedWhenHidden()
            // Other fields' options (categories, parents) follow the chosen store.
            ->live()
            ->visibleOn('create');
    }

    /**
     * Create a record in the store the form chose. `store_id` is not fillable, so
     * it is taken out of the data and applied through StoreContext.
     *
     * @param  callable(array): mixed  $create
     */
    public static function createIn(array $data, callable $create): mixed
    {
        $storeId = $data['store_id'] ?? null;
        unset($data['store_id']);

        if ($storeId === null || ! array_key_exists((int) $storeId, self::storeOptions())) {
            return $create($data);
        }

        return StoreContext::writingTo((int) $storeId, fn () => $create($data));
    }

    /** @return array<int, string> */
    private static function storeOptions(): array
    {
        // The platform panel files a record under any store; a merchant only under their team's.
        return Store::query()
            ->unless(StoreContext::isPlatform(), fn ($query) => $query->where('team_id', Filament::getTenant()?->getKey()))
            ->orderBy('id')
            ->pluck('name', 'id')
            ->all();
    }
}
