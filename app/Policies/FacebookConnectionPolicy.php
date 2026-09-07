<?php

namespace App\Policies;

use App\Models\FacebookConnection;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * The App panel runs strictAuthorization, so this has to exist for the resource
 * to be reachable at all. Permission names follow the seeded Shield convention:
 * FacebookConnection => facebook::connection.
 */
class FacebookConnectionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_facebook::connection');
    }

    public function view(User $user, FacebookConnection $connection): bool
    {
        return $user->can('view_facebook::connection');
    }

    public function create(User $user): bool
    {
        return $user->can('create_facebook::connection');
    }

    public function update(User $user, FacebookConnection $connection): bool
    {
        return $user->can('update_facebook::connection');
    }

    public function delete(User $user, FacebookConnection $connection): bool
    {
        return $user->can('delete_facebook::connection');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_facebook::connection');
    }
}
