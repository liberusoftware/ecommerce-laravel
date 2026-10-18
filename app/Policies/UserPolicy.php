<?php

namespace App\Policies;

use App\Models\User;

/** Accounts and roles decide who can do anything else: the platform's super_admin only. */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function view(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function update(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function delete(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasRole('super_admin');
    }
}
