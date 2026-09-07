<?php

namespace App\Filament\Admin\Resources\ChatConversations\Pages;

use App\Filament\Admin\Pages\ChatAgentDashboard;
use App\Filament\Admin\Resources\ChatConversations\ChatConversationResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListChatConversations extends ListRecords
{
    protected static string $resource = ChatConversationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('agent_dashboard')
                ->label('Agent Dashboard')
                // ChatAgentDashboard::getUrl() rather than route(): the admin
                // panel is tenant-scoped, so that route is admin/{tenant}/... and
                // the bare route() call had to rely on a URL default set by
                // Filament's tenancy middleware. getUrl() passes the current
                // tenant itself. Deferred too — the action is built before the
                // URL is needed.
                ->url(fn () => ChatAgentDashboard::getUrl())
                ->color('primary'),
        ];
    }
}
