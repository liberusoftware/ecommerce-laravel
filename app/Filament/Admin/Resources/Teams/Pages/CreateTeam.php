<?php

namespace App\Filament\Admin\Resources\Teams\Pages;

use App\Filament\Admin\Resources\Teams\TeamResource;
use App\Models\Team;
use App\Support\Installation;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateTeam extends CreateRecord
{
    protected static string $resource = TeamResource::class;

    /** user_id is not mass assignable on Team, so the owner is written explicitly. */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $team = Team::forceCreate([
                'name' => $data['name'],
                'user_id' => $data['user_id'],
                'personal_team' => false,
            ]);

            Installation::provisionStoreFor($team);

            return $team;
        });
    }
}
