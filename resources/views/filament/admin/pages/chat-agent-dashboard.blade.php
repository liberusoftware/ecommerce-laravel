<x-filament-panels::page>
    <div class="space-y-6">
        <!-- Stats Section -->
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-sm text-gray-500">Queued Chats</div>
                <div class="text-2xl font-bold text-primary-600">{{ $stats['total_queued'] }}</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-sm text-gray-500">My Active Chats</div>
                <div class="text-2xl font-bold text-success-600">{{ $stats['my_active'] }}</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-sm text-gray-500">Today Closed</div>
                <div class="text-2xl font-bold text-gray-600">{{ $stats['today_closed'] }}</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-sm text-gray-500">Avg Response Time</div>
                <div class="text-2xl font-bold text-warning-600">{{ $stats['avg_response_time'] }}s</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-sm text-gray-500">Avg Satisfaction</div>
                <div class="text-2xl font-bold text-warning-600">{{ $stats['avg_satisfaction'] }}/5</div>
            </div>
        </div>

        <!-- Refresh Button -->
        <div class="flex justify-end">
            <button wire:click="refresh" class="bg-primary-600 text-white px-4 py-2 rounded-lg hover:bg-primary-700">
                Refresh
            </button>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Queued Conversations -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-4 border-b">
                    <h2 class="text-lg font-semibold">Queued Conversations</h2>
                </div>
                <div class="p-4 space-y-3 max-h-[600px] overflow-y-auto">
                    @forelse($queuedConversations as $conversation)
                        <div class="border rounded-lg p-3 hover:bg-gray-50">
                            <div class="flex justify-between items-start mb-2">
                                <div>
                                    <div class="font-medium">
                                        {{ $conversation['customer']['name'] ?? $conversation['customer_name'] ?? 'Guest' }}
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        {{ $conversation['customer_email'] ?? 'No email' }}
                                    </div>
                                    <div class="text-xs text-gray-400">
                                        Waiting for {{ \Carbon\Carbon::parse($conversation['created_at'])->diffForHumans() }}
                                    </div>
                                </div>
                                <button 
                                    wire:click="assignToMe({{ $conversation['id'] }})"
                                    class="bg-success-600 text-white px-3 py-1 rounded text-sm hover:bg-success-700">
                                    Accept
                                </button>
                            </div>
                            <div class="text-xs text-gray-500">
                                Queue Position: {{ $conversation['queue_position'] }}
                            </div>
                        </div>
                    @empty
                        <div class="text-center text-gray-500 py-8">
                            No conversations in queue
                        </div>
                    @endforelse
                </div>
            </div>

            <!-- Active Conversations -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-4 border-b">
                    <h2 class="text-lg font-semibold">My Active Conversations</h2>
                </div>
                <div class="p-4 space-y-3 max-h-[600px] overflow-y-auto">
                    @forelse($activeConversations as $conversation)
                        <div class="border rounded-lg p-3 hover:bg-gray-50">
                            <div class="flex justify-between items-start mb-2">
                                <div>
                                    <div class="font-medium">
                                        {{ $conversation['customer']['name'] ?? $conversation['customer_name'] ?? 'Guest' }}
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        {{ $conversation['customer_email'] ?? 'No email' }}
                                    </div>
                                    <div class="text-xs text-gray-400">
                                        Started {{ \Carbon\Carbon::parse($conversation['started_at'])->diffForHumans() }}
                                    </div>
                                </div>
                                <a 
                                    href="{{ route('filament.admin.resources.chat-conversations.view', $conversation['id']) }}"
                                    class="bg-primary-600 text-white px-3 py-1 rounded text-sm hover:bg-primary-700">
                                    View
                                </a>
                            </div>
                            <div class="text-xs text-gray-500">
                                Messages: {{ count($conversation['messages'] ?? []) }}
                            </div>
                        </div>
                    @empty
                        <div class="text-center text-gray-500 py-8">
                            No active conversations
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        // Auto-refresh every 10 seconds
        setInterval(() => {
            Livewire.dispatch('refresh');
        }, 10000);
    </script>
    @endpush
</x-filament-panels::page>
