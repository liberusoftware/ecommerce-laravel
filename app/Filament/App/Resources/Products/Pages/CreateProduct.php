<?php

namespace App\Filament\App\Resources\Products\Pages;

use App\Filament\App\Resources\Products\ProductResource;
use App\Filament\Schemas\StoreField;
use App\Filament\Schemas\SyncsProductVariants;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateProduct extends CreateRecord
{
    use SyncsProductVariants;

    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->takeVariants($data);
    }

    protected function handleRecordCreation(array $data): Model
    {
        // One transaction: a refused variant leaves no half-made product behind.
        return DB::transaction(function () use ($data) {
            $product = StoreField::createIn($data, fn (array $data) => parent::handleRecordCreation($data));
            $this->syncVariants($product);

            return $product;
        });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
