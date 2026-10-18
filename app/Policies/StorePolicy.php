<?php

namespace App\Policies;

use App\Models\Store;
use App\Models\User;

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
    /** A merchant's own settings: the team's owner or one of its admins, or the platform operator. */
    public function manageSettings(User $user, Store $store): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        $team = $store->team;

        return $team !== null && ($user->ownsTeam($team)
            || $team->users()->whereKey($user->getKey())->wherePivot('role', 'admin')->exists());
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
