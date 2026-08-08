<?php

namespace App\Models;

use App\Traits\IsTenantModel;
use Biostate\FilamentMenuBuilder\Models\MenuItem as BaseMenuItem;

class MenuItem extends BaseMenuItem
{
    use IsTenantModel;

    /**
     * A menu item's owner is its menu's owner. There is no second answer, and
     * no surface offers one.
     *
     * The column exists anyway because Filament scopes a tenant-scoped resource
     * with `whereBelongsTo($tenant, 'team')` — a BelongsTo on the model itself,
     * which no relation through the parent satisfies. So it is derived on write
     * rather than asked for, and the menu builder page, which creates items
     * outside the resource, is covered by the same hook.
     */
    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (self $item) {
            $item->team_id ??= Menu::query()->whereKey($item->menu_id)->value('team_id');
        });
    }
}
