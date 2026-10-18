<?php

namespace App\Actions\Jetstream;

use App\Models\Team;
use App\Models\User;
use App\Support\Installation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Contracts\CreatesTeams;
use Laravel\Jetstream\Events\AddingTeam;
use Laravel\Jetstream\Jetstream;

class CreateTeam implements CreatesTeams
{
    /**
     * Validate and create a new team for the given user.
     *
     * @param  array<string, string>  $input
     */
    public function create(User $user, array $input): Team
    {
        Gate::forUser($user)->authorize('create', Jetstream::newTeamModel());

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
        ])->validateWithBag('createTeam');

        AddingTeam::dispatch($user);

        $team = DB::transaction(function () use ($user, $input) {
            $team = $user->ownedTeams()->create([
                'name' => $input['name'],
                'personal_team' => true,
            ]);

            Installation::provisionStoreFor($team);

            return $team;
        });

        $user->switchTeam($team);

        return $team;
    }
}
