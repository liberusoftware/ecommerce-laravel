<?php

namespace App\Policies;

use App\Models\Store;
use App\Models\User;
use App\Support\StoreAccess;
use App\Support\TeamPermission;

/** Stores are managed by the platform administrator, not by merchants. */
class StorePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function view(User $user, Store $store): bool
    {
        return $user->hasRole('super_admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function update(User $user, Store $store): bool
    {
        return $user->hasRole('super_admin');
    }

    /** Commerce rows are not constrained to their store, and the main site must not go offline. */
    /** A merchant's own settings: the team's owner or an administrator, or the platform operator. */
    public function manageSettings(User $user, Store $store): bool
    {
        return StoreAccess::allows($user, TeamPermission::STORE_MANAGE, $store);
    }

    public function delete(User $user, Store $store): bool
    {
        return $user->hasRole('super_admin')
            && ! $store->hasCommerceRecords()
            && ! $store->servesMainSite();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
