<?php

namespace App\Filament\App\Resources\FacebookConnections\Pages;

use App\Filament\App\Resources\FacebookConnections\FacebookConnectionResource;
use App\Services\Facebook\FacebookCatalog;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditFacebookConnection extends EditRecord
{
    protected static string $resource = FacebookConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testConnection')
                ->label('Test connection')
                ->icon('heroicon-o-signal')
                // Tests what is on screen, not what is saved, so a merchant can
                // check a pasted token before committing it.
                ->action(function (): void {
                    $data = $this->form->getState();

                    $result = (new FacebookCatalog(
                        (string) ($data['access_token'] ?? ''),
                        (string) ($data['catalog_id'] ?? ''),
                        (string) ($data['business_id'] ?? ''),
                        (string) ($data['graph_version'] ?? 'v21.0'),
                    ))->testConnection();

                    Notification::make()
                        ->title($result['message'])
                        ->{$result['success'] ? 'success' : 'danger'}()
                        ->send();
                }),
            DeleteAction::make()
                ->label('Disconnect')
                ->modalDescription('Products stay listed in Meta until you unlist them; this only removes the credentials.'),
        ];
    }
}
