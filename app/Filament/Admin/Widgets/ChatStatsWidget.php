<?php

namespace App\Filament\Admin\Widgets;

use App\Models\ChatAnalytics;
use App\Models\ChatConversation;
use App\Support\StoreAccess;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ChatStatsWidget extends BaseWidget
{
    public static function canView(): bool
    {
        return StoreAccess::platform(auth()->user(), 'view_any_chat::conversation');
    }

    protected function getStats(): array
    {
        $avgResponseTime = ChatAnalytics::averageResponseTime();
        $avgSatisfaction = ChatAnalytics::averageSatisfactionRating();

        $todayChats = ChatConversation::whereDate('created_at', today())->count();
        $activeChats = ChatConversation::where('status', 'active')->count();
        $queuedChats = ChatConversation::where('status', 'queued')->count();

        return [
            Stat::make('Active Chats', $activeChats)
                ->description('Currently ongoing')
                ->color('success')
                ->icon('heroicon-o-chat-bubble-left-right'),

            Stat::make('Queued Chats', $queuedChats)
                ->description('Waiting for agent')
                ->color('warning')
                ->icon('heroicon-o-clock'),

            Stat::make('Today\'s Chats', $todayChats)
                ->description('Total conversations today')
                ->color('primary')
                ->icon('heroicon-o-calendar'),

            Stat::make('Avg Response Time', $avgResponseTime ? round($avgResponseTime).'s' : 'N/A')
                ->description('Time to first response')
                ->color($avgResponseTime && $avgResponseTime < 60 ? 'success' : 'warning')
                ->icon('heroicon-o-arrow-trending-down'),

            Stat::make('Avg Satisfaction', $avgSatisfaction ? number_format($avgSatisfaction, 1).'/5' : 'N/A')
                ->description('Customer satisfaction rating')
                ->color($avgSatisfaction && $avgSatisfaction >= 4 ? 'success' : 'warning')
                ->icon('heroicon-o-star'),
        ];
    }

    protected function getColumns(): int
    {
        return 5;
    }
}
