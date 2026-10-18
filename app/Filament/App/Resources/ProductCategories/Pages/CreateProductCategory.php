<?php

namespace App\Filament\App\Resources\ProductCategories\Pages;

use App\Filament\App\Resources\ProductCategories\ProductCategoryResource;
use App\Filament\Schemas\StoreField;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProductCategory extends CreateRecord
{
    protected static string $resource = ProductCategoryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return StoreField::createIn($data, fn (array $data) => parent::handleRecordCreation($data));
    }
}
