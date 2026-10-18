<?php

namespace App\Filament\Admin\Resources\Stores\Pages;

use App\Filament\Admin\Resources\Stores\StoreResource;
use App\Filament\Schemas\StoreSettingsForm;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;

class EditStore extends EditRecord
{
    protected static string $resource = StoreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return StoreSettingsForm::fill($this->getRecord()) + $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return StoreSettingsForm::dehydrate(Arr::except($data, ['team_id']));
    }
}
