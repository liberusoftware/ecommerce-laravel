<?php

namespace App\Filament\Admin\Pages;

use App\Models\ChatAnalytics;
use App\Models\ChatConversation;
use App\Support\StoreAccess;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class ChatAgentDashboard extends Page
{
    public static function canAccess(): bool
    {
        return StoreAccess::platform(auth()->user(), 'view_any_chat::conversation');
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected string $view = 'filament.admin.pages.chat-agent-dashboard';

    protected static string|\UnitEnum|null $navigationGroup = 'Customer Support';

    protected static ?string $navigationLabel = 'Agent Dashboard';

    protected static ?string $title = 'Chat Agent Dashboard';

    public $queuedConversations = [];

    public $activeConversations = [];

    public $stats = [];

    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        // Get queued conversations
        $this->queuedConversations = ChatConversation::where('status', 'queued')
            ->with(['customer'])
            ->orderBy('queue_position')
            ->get()
            ->toArray();

        // Get active conversations for current agent
        $this->activeConversations = ChatConversation::where('agent_id', Auth::id())
            ->where('status', 'active')
            ->with(['customer', 'messages'])
            ->orderBy('started_at', 'desc')
            ->get()
            ->toArray();

        // Calculate stats
        $this->stats = [
            'total_queued' => ChatConversation::where('status', 'queued')->count(),
            'my_active' => count($this->activeConversations),
            'avg_response_time' => round(ChatAnalytics::averageResponseTime() ?? 0),
            'avg_satisfaction' => round(ChatAnalytics::averageSatisfactionRating() ?? 0, 1),
            'today_closed' => ChatConversation::where('status', 'closed')
                ->whereDate('ended_at', today())
                ->count(),
        ];
    }

    public function assignToMe($conversationId): void
    {
        $conversation = ChatConversation::find($conversationId);

        if ($conversation && $conversation->status === 'queued') {
            $conversation->update([
                'agent_id' => Auth::id(),
                'status' => 'active',
                'started_at' => now(),
            ]);

            $this->loadData();
            $this->dispatch('conversation-assigned');
        }
    }

    public function refresh(): void
    {
        $this->loadData();
    }
}
