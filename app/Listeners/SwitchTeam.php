<?php

namespace App\Listeners;

use App\Models\Team;
use App\Models\User;
use Filament\Events\TenantSet;

/**
 * Make the team a panel is showing the user's current team. StoreContext reads
 * `current_team_id` to scope store data, so it must follow the Filament tenant.
 */
class SwitchTeam
{
    public function handle(TenantSet $event): void
    {
        $user = $event->getUser();
        $team = $event->getTenant();

        if ($user instanceof User && $team instanceof Team && (int) $user->current_team_id !== (int) $team->id) {
            // switchTeam() refuses a team the user does not belong to.
            $user->switchTeam($team);
        }
    }
}
