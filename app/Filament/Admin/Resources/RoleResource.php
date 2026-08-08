<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\Roles\Pages\CreateRole;
use App\Filament\Admin\Resources\Roles\Pages\EditRole;
use App\Filament\Admin\Resources\Roles\Pages\ListRoles;
use App\Filament\Admin\Resources\Roles\Pages\ViewRole;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource as BaseRoleResource;

/**
 * Shield's role resource, owned here so its tenant opt-out can be a property.
 *
 * A role is global in this application — `config/permission.php` sets
 * `'teams' => false` — and `App\Models\Role` has no `team` relationship, so
 * Filament throws a `LogicException` the moment anything queries it inside a
 * Team-tenanted panel. That is every page in /admin, because the navigation
 * resolves resources on each render.
 *
 * The previous fix called `RoleResource::scopeToTenant(false)` from the panel
 * provider, and that is where this class comes from. `$isScopedToTenant` is
 * declared by the `BelongsToTenant` trait on `Filament\Resources\Resource`, so
 * every resource that does not redeclare it **shares one storage slot**:
 * writing it through any subclass turned tenant scoping off for every resource
 * in both panels. The panels responded, and listed every merchant's rows to
 * every merchant. Declaring the property here gives this class its own slot and
 * leaves everybody else's alone.
 *
 * Shield registers its own resource only when the panel has none whose class
 * name ends in `RoleResource` (`Utils::isResourcePublished`), so publishing this
 * one replaces it. The pages come with it: a page names its resource in a static
 * property, and Shield's name Shield's.
 */
class RoleResource extends BaseRoleResource
{
    protected static bool $isScopedToTenant = false;

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'view' => ViewRole::route('/{record}'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
