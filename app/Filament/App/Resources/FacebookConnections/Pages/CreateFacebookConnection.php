<?php

namespace App\Filament\App\Resources\FacebookConnections\Pages;

use App\Filament\App\Resources\FacebookConnections\FacebookConnectionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFacebookConnection extends CreateRecord
{
    protected static string $resource = FacebookConnectionResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
