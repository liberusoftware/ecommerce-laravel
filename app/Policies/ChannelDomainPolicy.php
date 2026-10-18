<?php

namespace App\Policies;

use App\Models\ChannelDomain;
use App\Models\User;

/** Domains are trusted and resolved as soon as they are saved, so only the platform administrator maps them. */
class ChannelDomainPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function view(User $user, ChannelDomain $domain): bool
    {
        return $user->hasRole('super_admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function update(User $user, ChannelDomain $domain): bool
    {
        return $user->hasRole('super_admin');
    }

    public function delete(User $user, ChannelDomain $domain): bool
    {
        return $user->hasRole('super_admin');
    }

    /** Per-record deletes only: model events clear the trusted-hosts cache. */
    public function deleteAny(User $user): bool
    {
        return false;
    }
}
