<?php

namespace App\Filament\Admin\Resources\Teams\Pages;

use App\Filament\Admin\Resources\Teams\TeamResource;
use App\Models\Team;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTeam extends EditRecord
{
    protected static string $resource = TeamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // purge(), as Jetstream's DeleteTeam does, so memberships go with it.
            // TeamPolicy refuses a team with orders or invoices.
            DeleteAction::make()->using(fn (Team $record) => $record->purge()),
        ];
    }
}
