<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

class DefaultTeamSeeder extends Seeder
{
    /**
     * The default team, owned by the seeded admin, owning the default store.
     * Runs before any catalogue is seeded, so seeded rows take this team from
     * the store they are stamped with.
     */
    public function run(): void
    {
        $owner = User::where('email', 'admin@example.com')->first();

        if (! $owner) {
            $this->command?->warn('DefaultTeamSeeder: no admin user, so no default team was created.');

            return;
        }

        $team = Team::where('name', 'default')->where('personal_team', false)->first()
            ?? Team::forceCreate(['name' => 'default', 'personal_team' => false, 'user_id' => $owner->id]);

        if (! $owner->belongsToTeam($team)) {
            $owner->teams()->attach($team, ['role' => 'admin']);
        }

        if ($owner->current_team_id === null) {
            $owner->forceFill(['current_team_id' => $team->id])->save();
        }

        Store::where('slug', 'default')->whereNull('team_id')->update(['team_id' => $team->id]);
    }
}
