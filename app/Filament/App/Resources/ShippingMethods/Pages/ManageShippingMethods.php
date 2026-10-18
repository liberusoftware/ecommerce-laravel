<?php

namespace App\Filament\App\Resources\ShippingMethods\Pages;

use App\Filament\App\Resources\ShippingMethods\ShippingMethodResource;
use App\Filament\Schemas\StoreField;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageShippingMethods extends ManageRecords
{
    protected static string $resource = ShippingMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->using(fn (array $data, string $model) => StoreField::createIn($data, fn (array $data) => $model::create($data))),
        ];
    }
}
