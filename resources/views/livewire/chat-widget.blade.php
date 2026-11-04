<div class="fixed bottom-4 right-4 z-50">
    @if($isOpen)
        <!-- Chat Window -->
        <div class="bg-white rounded-lg shadow-2xl w-96 h-[500px] flex flex-col">
            <!-- Chat Header -->
            <div class="bg-primary-700 text-white p-4 rounded-t-lg flex justify-between items-center">
                <div class="flex items-center space-x-2">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                    </svg>
                    <span class="font-semibold">Customer Support</span>
                </div>
                <button wire:click="closeChat" class="text-white hover:text-gray-200">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            @if($showRating)
                <!-- Rating Form -->
                <div class="flex-1 flex flex-col items-center justify-center p-6 space-y-4">
                    <h3 class="text-lg font-semibold text-gray-800">How was your experience?</h3>
                    
                    <div class="flex space-x-2">
                        @for($i = 1; $i <= 5; $i++)
                            <button 
                                wire:click="$set('rating', {{ $i }})"
                                class="text-3xl {{ $rating >= $i ? 'text-warning-600' : 'text-gray-300' }} hover:text-warning-600 transition">
                                ⭐
                            </button>
                        @endfor
                    </div>

                    <textarea 
                        wire:model="feedback"
                        placeholder="Any additional feedback? (optional)"
                        class="w-full p-2 border rounded-lg focus:ring-2 focus:ring-primary-600 focus:border-transparent"
                        rows="3"></textarea>

                    <button 
                        wire:click="submitRating"
                        class="bg-primary-700 text-white px-6 py-2 rounded-lg hover:bg-primary-600 transition">
                        Submit Feedback
                    </button>
                </div>
            @else
                <!-- Chat Messages -->
                <div class="flex-1 overflow-y-auto p-4 space-y-3 bg-gray-50" id="chat-messages-{{ $sessionId }}">
                    @if($isLoading)
                        <div class="flex justify-center items-center h-full">
                            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-primary-700"></div>
                        </div>
                    @else
                        <div id="messages-container-{{ $sessionId }}">
                            <!-- Messages will be loaded here via JavaScript -->
                        </div>
                    @endif
                </div>

                <!-- Message Input -->
                <div class="p-4 border-t bg-white rounded-b-lg">
                    <form wire:submit.prevent="sendMessage" class="flex space-x-2">
                        <input 
                            type="text" 
                            wire:model="newMessage"
                            placeholder="Type your message..."
                            class="flex-1 px-4 py-2 border rounded-lg focus:ring-2 focus:ring-primary-600 focus:border-transparent"
                            autocomplete="off">
                        <button 
                            type="submit"
                            class="bg-primary-700 text-white px-4 py-2 rounded-lg hover:bg-primary-600 transition flex items-center">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                            </svg>
                        </button>
                    </form>
                </div>
            @endif
        </div>
    @else
        <!-- Chat Button -->
        <button 
            wire:click="toggleChat"
            class="bg-primary-700 hover:bg-primary-600 text-white rounded-full p-4 shadow-lg transition transform hover:scale-110">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
            </svg>
        </button>
    @endif
</div>

@push('scripts')
<script>
(function() {
    const sessionId = @js($sessionId);
    let conversationId = null;
    let messages = [];
    let pollingInterval = null;

    async function loadConversation() {
        try {
            const response = await fetch(`/chat/session/${sessionId}`);
            const data = await response.json();
            
            if (data.conversation) {
                conversationId = data.conversation.id;
                messages = data.conversation.messages || [];
                renderMessages();
            }
        } catch (error) {
            console.error('Error loading conversation:', error);
        }
    }

    async function startChat() {
        try {
            const response = await fetch('/chat/start', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({})
            });
            
            const data = await response.json();
            
            if (data.success) {
                conversationId = data.conversation.id;
                messages = data.conversation.messages || [];
                renderMessages();
            }
        } catch (error) {
            console.error('Error starting chat:', error);
        }
    }

    async function sendMessage(message) {
        if (!conversationId) {
            await startChat();
        }

        if (!message.trim()) return;

        try {
            const response = await fetch(`/chat/${conversationId}/message`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({ message })
            });
            
            const data = await response.json();
            
            if (data.success) {
                await loadMessages();
            }
        } catch (error) {
            console.error('Error sending message:', error);
        }
    }

    async function loadMessages() {
        if (!conversationId) return;

        try {
            const response = await fetch(`/chat/${conversationId}/messages`);
            const data = await response.json();
            
            if (data.success && data.conversation) {
                messages = data.conversation.messages || [];
                renderMessages();
            }
        } catch (error) {
            console.error('Error loading messages:', error);
        }
    }

    function renderMessages() {
        const container = document.getElementById(`messages-container-${sessionId}`);
        if (!container) return;

        container.innerHTML = messages.map(msg => {
            const isAgent = msg.sender_type === 'agent' || msg.sender_type === 'system';
            const bgColor = isAgent ? 'bg-primary-100' : 'bg-surface';
            const alignment = isAgent ? 'items-start' : 'items-end';
            
            return `
                <div class="flex ${alignment}">
                    <div class="${bgColor} rounded-lg p-3 max-w-[80%]">
                        <p class="text-sm text-gray-800">${escapeHtml(msg.message)}</p>
                        <span class="text-xs text-gray-500">${new Date(msg.created_at).toLocaleTimeString()}</span>
                    </div>
                </div>
            `;
        }).join('');

        // Scroll to bottom
        setTimeout(() => {
            const messagesDiv = document.getElementById(`chat-messages-${sessionId}`);
            if (messagesDiv) {
                messagesDiv.scrollTop = messagesDiv.scrollHeight;
            }
        }, 100);
    }

    function startPolling() {
        if (pollingInterval) {
            clearInterval(pollingInterval);
        }
        
        pollingInterval = setInterval(() => {
            if (conversationId && {{ $isOpen ? 'true' : 'false' }}) {
                loadMessages();
            }
        }, 3000); // Poll every 3 seconds
    }

    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

    // Initialize
    loadConversation();
    startPolling();
    
    // Listen for Livewire events
    document.addEventListener('livewire:init', () => {
        Livewire.on('chat-send-message', (data) => {
            sendMessage(data[0].message);
        });
        
        Livewire.on('chat-submit-rating', async (data) => {
            try {
                await fetch(`/chat/${data[0].conversationId}/rating`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({
                        rating: data[0].rating,
                        feedback: data[0].feedback
                    })
                });

                await fetch(`/chat/${data[0].conversationId}/close`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                });
            } catch (error) {
                console.error('Error submitting rating:', error);
            }
        });
    });
})();
</script>
@endpush
