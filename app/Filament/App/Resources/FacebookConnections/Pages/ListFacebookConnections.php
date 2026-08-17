<?php

namespace App\Filament\App\Resources\FacebookConnections\Pages;

use App\Filament\App\Resources\FacebookConnections\FacebookConnectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFacebookConnections extends ListRecords
{
    protected static string $resource = FacebookConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
