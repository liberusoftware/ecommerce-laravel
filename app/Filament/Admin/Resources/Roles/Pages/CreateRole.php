<?php

namespace App\Filament\Admin\Resources\Roles\Pages;

use App\Filament\Admin\Resources\RoleResource;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\CreateRole as BaseCreateRole;

/**
 * Shield's page, pointed at the resource this application registers.
 *
 * A page names its resource in a static property, and Shield's names Shield's —
 * which is not the class in the panel once ours is published. Everything else,
 * including the permission handling, is inherited.
 */
class CreateRole extends BaseCreateRole
{
    protected static string $resource = RoleResource::class;
}
