# Customer Support Chat System

> **Held as named debt.** The live-chat feature belongs to the **CRM** product (`chat-and-bots`), which is documentation-only — no repository exists. This code stays in the host until it does; see [#943](https://github.com/liberusoftware/ecommerce-laravel/issues/943) and [`CONFORMANCE.md` §4.2](./CONFORMANCE.md#42-the-98-unmappable-files-were-an-artifact).

## Overview
A comprehensive live chat system for customer support with real-time messaging, agent management, and analytics tracking.

## Features

### Customer Features
- **Chat Widget**: Accessible from any page via a floating button
- **Real-time Messaging**: Messages update automatically with 3-second polling
- **Guest Support**: Non-logged-in users can still use chat
- **Satisfaction Rating**: Rate support experience after chat ends (1-5 stars)
- **Chat History**: Logged-in users have chats associated with their account

### Agent Features
- **Agent Dashboard**: View queued and active conversations
- **Multi-chat Support**: Handle multiple conversations simultaneously
- **Quick Accept**: Accept queued chats with one click
- **Conversation History**: View full chat transcripts
- **Real-time Updates**: Dashboard auto-refreshes every 10 seconds

### Admin Features
- **Chat Analytics**: 
  - Average response time
  - Average satisfaction rating
  - Daily chat statistics
  - Queue metrics
- **Conversation Management**: View all chat history
- **Status Tracking**: Monitor queued, active, and closed chats

## Database Schema

### chat_conversations
- Stores conversation metadata
- Tracks customer, agent, and status
- Manages queue position
- Records timestamps (started_at, ended_at)

### chat_messages
- Stores individual messages
- Links to conversations
- Tracks sender type (customer/agent/system)
- Records read status

### chat_analytics
- Tracks performance metrics
- Response and resolution times
- Message counts
- Satisfaction ratings and feedback

## File Structure

```
app/
├── Models/
│   ├── ChatConversation.php
│   ├── ChatMessage.php
│   └── ChatAnalytics.php
├── Services/
│   └── ChatService.php
├── Http/Controllers/
│   └── ChatController.php
├── Livewire/
│   └── ChatWidget.php
└── Filament/
    └── Admin/
        ├── Pages/
        │   └── ChatAgentDashboard.php
        ├── Resources/
        │   └── ChatConversations/
        │       ├── ChatConversationResource.php
        │       └── Pages/
        │           ├── ListChatConversations.php
        │           └── ViewChatConversation.php
        └── Widgets/
            └── ChatStatsWidget.php

resources/views/
├── livewire/
│   └── chat-widget.blade.php
└── filament/admin/pages/
    └── chat-agent-dashboard.blade.php
```

## API Endpoints

### Public Endpoints
- `POST /chat/start` - Start a new conversation
- `GET /chat/session/{sessionId}` - Get conversation by session
- `POST /chat/{conversationId}/message` - Send a message
- `GET /chat/{conversationId}/messages` - Get conversation messages
- `POST /chat/{conversationId}/close` - Close a conversation
- `POST /chat/{conversationId}/rating` - Submit satisfaction rating

### Agent Endpoints (requires authentication)
- `GET /chat/agent/conversations` - Get agent's active conversations
- `POST /chat/{conversationId}/assign` - Assign conversation to agent
- `GET /chat/agent/next` - Get next queued conversation

## Usage

### For Customers
1. Click the chat button (blue circle) in the bottom-right corner
2. Type your message and press Enter or click Send
3. Wait for an agent to join the conversation
4. When done, close the chat and optionally provide a rating

### For Agents
1. Access the Admin Panel (Filament)
2. Navigate to "Agent Dashboard" under Customer Support
3. View queued conversations and click "Accept" to start helping
4. Handle multiple chats from the active conversations section
5. View full conversation details by clicking "View"

### For Admins
1. Access "Chat Conversations" to view all conversations
2. Check "Chat Stats Widget" on the dashboard for analytics
3. Filter conversations by status or agent
4. View detailed conversation transcripts and analytics

## Configuration

### Queue Management
- Conversations are automatically assigned queue positions
- First-in-first-out (FIFO) queue system
- Agents can pick from queued conversations manually

### Polling Intervals
- Chat widget: 3 seconds
- Agent dashboard: 10 seconds
- Adjustable in the respective view files

## Future Enhancements

Potential improvements for future versions:
- WebSocket support for true real-time updates (Laravel Echo + Pusher/Redis)
- File/image attachments in chat
- Canned responses for agents
- Chat transfer between agents
- Email notifications for offline messages
- Mobile app support
- Automated chatbot for initial triage
- Advanced analytics dashboard with charts

## Technical Notes

### Dependencies
- Laravel 12
- Livewire 4.0
- Filament 5.0
- Tailwind CSS

### Browser Compatibility
- Modern browsers (Chrome, Firefox, Safari, Edge)
- JavaScript required
- Cookies/Session storage required

### Performance Considerations
- Polling-based updates (no WebSocket overhead)
- Efficient database queries with proper indexes
- Message pagination recommended for long conversations
- Consider adding Redis caching for high-traffic sites

## Security

- CSRF protection on all POST requests
- XSS prevention via HTML escaping
- SQL injection prevention via Eloquent ORM
- Role-based access control for agent features
- Session-based conversation tracking

## Troubleshooting

### Chat widget not appearing
- Check that Livewire scripts are loaded
- Verify chat-widget is included in layout
- Check browser console for JavaScript errors

### Messages not sending
- Verify CSRF token is present
- Check API routes are registered
- Ensure database migrations have run

### Agent dashboard not loading
- Verify user has admin/super_admin role
- Check Filament panel configuration
- Ensure all resources are registered
