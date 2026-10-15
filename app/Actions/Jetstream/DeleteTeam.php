<?php

namespace App\Actions\Jetstream;

use App\Models\Invoice;
use App\Models\Order;
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
        $hasFinancialRecords = Order::withoutGlobalScopes()->where('team_id', $team->id)->exists()
            || Invoice::withoutGlobalScopes()->where('team_id', $team->id)->exists();

        if ($hasFinancialRecords) {
            throw ValidationException::withMessages([
                'team' => __('This team has orders or invoices, so it cannot be deleted.'),
            ]);
        }

        $team->purge();
    }
}
