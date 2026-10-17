<?php

namespace App\Policies;

use App\Models\Channel;
use App\Models\User;

/** Channels are managed by the platform administrator, not by merchants. */
class ChannelPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function view(User $user, Channel $channel): bool
    {
        return $user->hasRole('super_admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function update(User $user, Channel $channel): bool
    {
        return $user->hasRole('super_admin');
    }

    /** Deleting the main site's channel would take the main site offline. */
    public function delete(User $user, Channel $channel): bool
    {
        return $user->hasRole('super_admin') && ! $channel->servesMainSite();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
