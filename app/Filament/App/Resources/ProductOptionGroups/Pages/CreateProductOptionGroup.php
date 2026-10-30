<?php

namespace App\Filament\App\Resources\ProductOptionGroups\Pages;

use App\Filament\App\Resources\ProductOptionGroups\ProductOptionGroupResource;
use App\Filament\Schemas\StoreField;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProductOptionGroup extends CreateRecord
{
    protected static string $resource = ProductOptionGroupResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return StoreField::createIn($data, fn (array $data) => parent::handleRecordCreation($data));
    }
}
