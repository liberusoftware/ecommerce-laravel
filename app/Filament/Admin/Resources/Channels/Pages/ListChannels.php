<?php

namespace App\Filament\Admin\Resources\Channels\Pages;

use App\Filament\Admin\Resources\Channels\ChannelResource;
use Filament\Resources\Pages\ListRecords;

class ListChannels extends ListRecords
{
    protected static string $resource = ChannelResource::class;
}
