# Chat System Quick Reference Guide

> **Held as named debt.** The live-chat feature belongs to the **CRM** product (`chat-and-bots`), which is documentation-only — no repository exists. This code stays in the host until it does; see [#943](https://github.com/liberusoftware/ecommerce-laravel/issues/943) and [`CONFORMANCE.md` §4.2](./CONFORMANCE.md#42-the-98-unmappable-files-were-an-artifact).

## For Developers

### Quick Start

#### 1. Run Migrations
```bash
php artisan migrate
```

#### 2. Clear Caches
```bash
php artisan config:clear
php artisan cache:clear
php artisan view:clear
```

#### 3. Test the Chat Widget
Visit any page on the site and look for the blue chat button in the bottom-right corner.

### Key Files to Know

#### Models
- `app/Models/ChatConversation.php` - Main conversation model
- `app/Models/ChatMessage.php` - Individual messages
- `app/Models/ChatAnalytics.php` - Performance metrics

#### Controllers & Services
- `app/Services/ChatService.php` - Business logic (use this for custom features)
- `app/Http/Controllers/ChatController.php` - API endpoints

#### Frontend
- `app/Livewire/ChatWidget.php` - Livewire component
- `resources/views/livewire/chat-widget.blade.php` - Widget view

#### Admin (Filament)
- `app/Filament/Admin/Pages/ChatAgentDashboard.php` - Agent workspace
- `app/Filament/Admin/Resources/ChatConversations/` - CRUD resource

### Common Tasks

#### Adding a New Message Type
```php
// In ChatService
public function addMessage(int $conversationId, array $data): ChatMessage
{
    $message = ChatMessage::create([
        'conversation_id' => $conversationId,
        'sender_type' => $data['sender_type'], // 'customer', 'agent', or 'system'
        'message' => $data['message'],
    ]);
    
    return $message;
}
```

#### Retrieving Chat History
```php
use App\Models\ChatConversation;

// Get all messages for a conversation
$conversation = ChatConversation::with('messages')->find($id);

// Get conversations for a specific user
$userConversations = ChatConversation::where('user_id', $userId)
    ->with('messages')
    ->get();
```

#### Getting Analytics
```php
use App\Models\ChatAnalytics;

// Average metrics
$avgResponseTime = ChatAnalytics::averageResponseTime();
$avgSatisfaction = ChatAnalytics::averageSatisfactionRating();

// For a specific conversation
$analytics = ChatAnalytics::where('conversation_id', $id)->first();
```

#### Checking Conversation Status
```php
use App\Models\ChatConversation;

// Get active conversations
$activeChats = ChatConversation::where('status', 'active')->get();

// Get queued conversations
$queuedChats = ChatConversation::queued()->get();

// Get agent's conversations
$agentChats = ChatConversation::forAgent($agentId)
    ->where('status', 'active')
    ->get();
```

### API Endpoints Reference

#### Customer Endpoints
```http
# Start new conversation
POST /chat/start
Body: { "customer_name": "John", "customer_email": "john@example.com" }

# Get conversation by session
GET /chat/session/{sessionId}

# Send message
POST /chat/{conversationId}/message
Body: { "message": "Hello, I need help" }

# Get messages
GET /chat/{conversationId}/messages

# Close conversation
POST /chat/{conversationId}/close

# Submit rating
POST /chat/{conversationId}/rating
Body: { "rating": 5, "feedback": "Great service!" }
```

#### Agent Endpoints (Auth Required)
```http
# Get agent's conversations
GET /chat/agent/conversations

# Accept a conversation
POST /chat/{conversationId}/assign

# Get next in queue
GET /chat/agent/next
```

### Database Queries

#### Find Conversations Waiting Too Long
```php
use App\Models\ChatConversation;

$waitingTooLong = ChatConversation::where('status', 'queued')
    ->where('created_at', '<', now()->subMinutes(5))
    ->get();
```

#### Get Today's Chat Statistics
```php
use App\Models\ChatConversation;
use App\Models\ChatAnalytics;

$todayStats = [
    'total' => ChatConversation::whereDate('created_at', today())->count(),
    'closed' => ChatConversation::whereDate('ended_at', today())->count(),
    'avg_satisfaction' => ChatAnalytics::whereHas('conversation', function($q) {
        $q->whereDate('ended_at', today());
    })->avg('satisfaction_rating'),
];
```

#### Find Unassigned Conversations
```php
$unassigned = ChatConversation::where('status', 'queued')
    ->whereNull('agent_id')
    ->orderBy('queue_position')
    ->get();
```

### Customization Examples

#### Change Polling Interval
In `resources/views/livewire/chat-widget.blade.php`:
```javascript
// Change from 3000 (3 seconds) to 5000 (5 seconds)
pollingInterval = setInterval(() => {
    // ...
}, 5000); // Changed from 3000
```

In `resources/views/filament/admin/pages/chat-agent-dashboard.blade.php`:
```javascript
// Change agent dashboard refresh from 10s to 15s
setInterval(() => {
    Livewire.dispatch('refresh');
}, 15000); // Changed from 10000
```

#### Add Custom System Messages
```php
use App\Services\ChatService;

$chatService = app(ChatService::class);

$chatService->addMessage($conversationId, [
    'message' => 'Your support request has been escalated to a senior agent.',
    'sender_type' => 'system',
]);
```

#### Extend ChatService with Custom Methods
```php
// In app/Services/ChatService.php

public function transferToAgent(int $conversationId, int $newAgentId): ChatConversation
{
    $conversation = ChatConversation::findOrFail($conversationId);
    $oldAgentId = $conversation->agent_id;
    
    $conversation->update(['agent_id' => $newAgentId]);
    
    $this->addMessage($conversationId, [
        'message' => 'This conversation has been transferred to another agent.',
        'sender_type' => 'system',
    ]);
    
    return $conversation->fresh();
}
```

### Troubleshooting

#### Widget Not Appearing
1. Check if Livewire is loaded: `@livewireStyles` and `@livewireScripts` in layout
2. Verify chat widget is included: `@livewire('chat-widget')` in layout
3. Check browser console for errors
4. Clear view cache: `php artisan view:clear`

#### Messages Not Sending
1. Check CSRF token in page source
2. Verify routes are registered: `php artisan route:list | grep chat`
3. Check database connection
4. Look at Laravel logs: `storage/logs/laravel.log`

#### Agent Dashboard Not Loading
1. Verify user has correct role: `$user->hasRole(['super_admin', 'admin'])`
2. Check Filament configuration
3. Clear config cache: `php artisan config:clear`

#### Polling Not Working
1. Check JavaScript console for errors
2. Verify API endpoints are accessible
3. Test endpoint manually: `curl http://yoursite.com/chat/session/{sessionId}`

### Performance Tips

#### Add Database Indexes (Already included in migrations)
```php
// In migration
$table->index('status');
$table->index('agent_id');
$table->index(['conversation_id', 'created_at']);
```

#### Eager Load Relationships
```php
// Instead of this:
$conversations = ChatConversation::all();
foreach ($conversations as $conv) {
    echo $conv->agent->name; // N+1 query problem
}

// Do this:
$conversations = ChatConversation::with('agent', 'messages')->all();
foreach ($conversations as $conv) {
    echo $conv->agent->name; // Single query
}
```

#### Paginate Long Conversations
```php
// In ChatController
public function getMessages($conversationId)
{
    $messages = ChatMessage::where('conversation_id', $conversationId)
        ->orderBy('created_at', 'desc')
        ->paginate(50); // Limit to 50 messages
        
    return response()->json($messages);
}
```

### Testing Helpers

#### Create Test Conversation
```php
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;

$conversation = ChatConversation::create([
    'session_id' => 'test-session-' . uniqid(),
    'user_id' => User::first()->id,
    'status' => 'active',
]);

ChatMessage::create([
    'conversation_id' => $conversation->id,
    'sender_type' => 'customer',
    'message' => 'Test message',
]);
```

#### Simulate Agent Assignment
```php
use App\Services\ChatService;

$chatService = app(ChatService::class);
$agent = User::role('admin')->first();

$conversation = $chatService->assignAgent($conversationId, $agent->id);
```

### Integration Points

#### Add Chat Link in Navigation
```blade
<!-- In your navigation partial -->
<a href="{{ route('filament.admin.pages.chat-agent-dashboard') }}" 
   class="nav-link">
    Support Chat
    @if($queuedCount > 0)
        <span class="badge">{{ $queuedCount }}</span>
    @endif
</a>
```

#### Email Notification on New Chat
```php
// Create a new Event and Listener
// Event: app/Events/ChatStarted.php
// Listener: app/Listeners/NotifyAgentsOfNewChat.php

// In ChatService::createConversation()
event(new ChatStarted($conversation));
```

#### Add to Dashboard Stats
```php
// In your dashboard controller
use App\Models\ChatConversation;

$dashboardStats = [
    'active_chats' => ChatConversation::where('status', 'active')->count(),
    'queued_chats' => ChatConversation::where('status', 'queued')->count(),
];
```

### Useful Commands

```bash
# View routes
php artisan route:list --name=chat

# Rollback migrations
php artisan migrate:rollback --step=3

# Tinker with models
php artisan tinker
>>> App\Models\ChatConversation::count()
>>> App\Models\ChatMessage::latest()->first()

# Clear all caches
php artisan optimize:clear

# Check for pending migrations
php artisan migrate:status
```

### Next Steps

1. **Test in Browser**: Visit site, click chat button, send messages
2. **Test as Agent**: Login as admin, visit Agent Dashboard, accept a chat
3. **Review Analytics**: Check Chat Stats Widget on admin dashboard
4. **Customize Styling**: Modify Tailwind classes in chat-widget.blade.php
5. **Add Features**: Extend ChatService with custom functionality

### Getting Help

- Check `docs/CHAT_SYSTEM.md` for detailed documentation
- See `docs/CHAT_ARCHITECTURE_DIAGRAM.md` for system overview
- Review `docs/CHAT_IMPLEMENTATION_SUMMARY.md` for technical details
- Look at existing code in `app/Services/ChatService.php` for examples

### Contributing

When adding features:
1. Follow existing code patterns
2. Add appropriate indexes to migrations
3. Update documentation
4. Test thoroughly
5. Consider performance implications
