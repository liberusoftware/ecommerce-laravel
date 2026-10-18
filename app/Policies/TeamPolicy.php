<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;
use App\Support\Installation;
use App\Support\StoreAccess;
use App\Support\TeamPermission;

/**
 * Teams are merchants. The platform operator manages all of them; a team's own
 * owner and administrators manage its name and members, and nothing else's.
 */
class TeamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function view(User $user, Team $team): bool
    {
        return $user->hasRole('super_admin') || $user->belongsToTeam($team);
    }

    /**
     * Self-service sign-up as a merchant is what saas and marketplace installations
     * are for. A single-merchant installation has no second merchant to sign up.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('super_admin')
            || $user->can('create_store')
            || Installation::mode() !== Installation::SINGLE;
    }

    public function update(User $user, Team $team): bool
    {
        return StoreAccess::allows($user, TeamPermission::TEAM_MANAGE, $team);
    }

    public function addTeamMember(User $user, Team $team): bool
    {
        return StoreAccess::allows($user, TeamPermission::TEAM_MANAGE, $team);
    }

    public function updateTeamMember(User $user, Team $team): bool
    {
        return StoreAccess::allows($user, TeamPermission::TEAM_MANAGE, $team);
    }

    public function removeTeamMember(User $user, Team $team): bool
    {
        return StoreAccess::allows($user, TeamPermission::TEAM_MANAGE, $team);
    }

    /** A team with orders or invoices is a merchant's financial history. */
    public function delete(User $user, Team $team): bool
    {
        return ($user->hasRole('super_admin') || $user->ownsTeam($team)) && ! $team->hasFinancialRecords();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Team $team): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Team $team): bool
    {
        return $user->hasRole('super_admin');
    }

    public function restoreAny(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function replicate(User $user, Team $team): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
