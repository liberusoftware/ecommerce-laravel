<?php

namespace App\Filament\App\Resources\ProductCategories\Pages;

use App\Filament\App\Resources\ProductCategories\ProductCategoryResource;
use App\Filament\Schemas\CategoryTable;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProductCategory extends EditRecord
{
    protected static string $resource = ProductCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CategoryTable::deleteAction(DeleteAction::make())];
    }
}
