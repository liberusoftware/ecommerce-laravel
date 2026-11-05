<?php

namespace App\Filament\App\Resources\ContactRequests\Pages;

use App\Filament\App\Resources\ContactRequests\ContactRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListContactRequests extends ListRecords
{
    protected static string $resource = ContactRequestResource::class;
}
