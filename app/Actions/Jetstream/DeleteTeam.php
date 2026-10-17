<?php

namespace App\Actions\Jetstream;

use App\Models\Team;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Contracts\DeletesTeams;

class DeleteTeam implements DeletesTeams
{
    /**
     * Delete the given team. A team's orders and invoices cascade with it, so a
     * team that has any is refused rather than purged.
     */
    public function delete(Team $team): void
    {
        if ($team->hasFinancialRecords()) {
            throw ValidationException::withMessages([
                'team' => __('This team has orders or invoices, so it cannot be deleted.'),
            ]);
        }

        $team->purge();
    }
}
