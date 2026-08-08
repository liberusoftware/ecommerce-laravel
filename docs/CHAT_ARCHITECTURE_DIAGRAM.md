# Chat System Architecture Diagram

> **Held as named debt.** The live-chat feature belongs to the **CRM** product (`chat-and-bots`), which is documentation-only — no repository exists. This code stays in the host until it does; see [#943](https://github.com/liberusoftware/ecommerce-laravel/issues/943) and [`CONFORMANCE.md` §4.2](./CONFORMANCE.md#42-the-98-unmappable-files-were-an-artifact).

## System Components Flow

```
┌─────────────────────────────────────────────────────────────────────────┐
│                           CUSTOMER INTERFACE                             │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                           │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │  Chat Widget (Livewire Component)                               │   │
│  │  - Floating button on all pages                                 │   │
│  │  - Expandable chat window                                       │   │
│  │  - Message input/display                                        │   │
│  │  - Satisfaction rating form                                     │   │
│  └──────────────────┬──────────────────────────────────────────────┘   │
│                     │                                                    │
│                     │ HTTP Requests (Fetch API)                         │
│                     ↓                                                    │
└─────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────┐
│                          BACKEND API LAYER                               │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                           │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │  ChatController                                                  │   │
│  │  ┌──────────────────────────────────────────────────────────┐  │   │
│  │  │ Public Endpoints:                                         │  │   │
│  │  │  POST   /chat/start                                       │  │   │
│  │  │  GET    /chat/session/{sessionId}                         │  │   │
│  │  │  POST   /chat/{id}/message                                │  │   │
│  │  │  GET    /chat/{id}/messages                               │  │   │
│  │  │  POST   /chat/{id}/close                                  │  │   │
│  │  │  POST   /chat/{id}/rating                                 │  │   │
│  │  └──────────────────────────────────────────────────────────┘  │   │
│  │  ┌──────────────────────────────────────────────────────────┐  │   │
│  │  │ Agent Endpoints (Auth Required):                          │  │   │
│  │  │  GET    /chat/agent/conversations                         │  │   │
│  │  │  POST   /chat/{id}/assign                                 │  │   │
│  │  │  GET    /chat/agent/next                                  │  │   │
│  │  └──────────────────────────────────────────────────────────┘  │   │
│  └──────────────────┬───────────────────────────────────────────────┘   │
│                     │                                                    │
│                     │ Delegates to                                       │
│                     ↓                                                    │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │  ChatService (Business Logic)                                   │   │
│  │  - createConversation()                                         │   │
│  │  - addMessage()                                                 │   │
│  │  - assignAgent()                                                │   │
│  │  - closeConversation()                                          │   │
│  │  - getNextQueuedConversation()                                  │   │
│  │  - markMessagesAsRead()                                         │   │
│  │  - addSatisfactionRating()                                      │   │
│  └──────────────────┬───────────────────────────────────────────────┘   │
│                     │                                                    │
│                     │ Uses                                               │
│                     ↓                                                    │
└─────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────┐
│                         DATA ACCESS LAYER                                │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                           │
│  ┌────────────────────┐  ┌──────────────────┐  ┌──────────────────┐   │
│  │ ChatConversation   │  │  ChatMessage     │  │  ChatAnalytics   │   │
│  │ Model              │  │  Model           │  │  Model           │   │
│  ├────────────────────┤  ├──────────────────┤  ├──────────────────┤   │
│  │ - id               │  │ - id             │  │ - id             │   │
│  │ - session_id       │  │ - conversation_id│  │ - conversation_id│   │
│  │ - user_id          │  │ - user_id        │  │ - response_time  │   │
│  │ - agent_id         │  │ - sender_type    │  │ - resolution_time│   │
│  │ - status           │  │ - message        │  │ - message_count  │   │
│  │ - queue_position   │  │ - is_read        │  │ - satisfaction   │   │
│  │ - started_at       │  │ - created_at     │  │ - feedback       │   │
│  │ - ended_at         │  └──────────────────┘  └──────────────────┘   │
│  └────────────────────┘                                                 │
│           │                      │                      │                │
│           └──────────────────────┴──────────────────────┘                │
│                                  │                                       │
│                                  ↓                                       │
└─────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────┐
│                            DATABASE                                      │
├─────────────────────────────────────────────────────────────────────────┤
│  MySQL/PostgreSQL                                                        │
│  - chat_conversations table                                              │
│  - chat_messages table                                                   │
│  - chat_analytics table                                                  │
└─────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────┐
│                        ADMIN INTERFACE (FILAMENT)                        │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                           │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │  Agent Dashboard (Custom Page)                                  │   │
│  │  ┌──────────────────────┐  ┌───────────────────────────────┐  │   │
│  │  │  Queued Chats        │  │  Active Chats                 │  │   │
│  │  │  - View queue        │  │  - My ongoing conversations   │  │   │
│  │  │  - Accept button     │  │  - Quick navigation           │  │   │
│  │  │  - Auto-refresh 10s  │  │  - Message count              │  │   │
│  │  └──────────────────────┘  └───────────────────────────────┘  │   │
│  │  ┌───────────────────────────────────────────────────────────┐│   │
│  │  │  Statistics                                                ││   │
│  │  │  - Total queued | My active | Today closed               ││   │
│  │  │  - Avg response time | Avg satisfaction                  ││   │
│  │  └───────────────────────────────────────────────────────────┘│   │
│  └─────────────────────────────────────────────────────────────────┘   │
│                                                                           │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │  ChatConversation Resource (CRUD)                              │   │
│  │  - List all conversations (with filters)                       │   │
│  │  - View conversation details                                   │   │
│  │  - Full message history                                        │   │
│  │  - Analytics display                                           │   │
│  └─────────────────────────────────────────────────────────────────┘   │
│                                                                           │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │  Chat Stats Widget                                              │   │
│  │  - Active chats count                                           │   │
│  │  - Queued chats count                                           │   │
│  │  - Today's chats total                                          │   │
│  │  - Avg response time                                            │   │
│  │  - Avg satisfaction rating                                      │   │
│  └─────────────────────────────────────────────────────────────────┘   │
│                                                                           │
└─────────────────────────────────────────────────────────────────────────┘

## Data Flow Examples

### Customer Starting a Chat:
1. Customer clicks chat button → ChatWidget opens
2. JavaScript calls POST /chat/start → ChatController
3. ChatController → ChatService.createConversation()
4. ChatService creates ChatConversation + ChatAnalytics
5. System message added automatically
6. Response sent back to customer with conversation ID
7. Polling starts (every 3 seconds) to fetch new messages

### Agent Accepting a Chat:
1. Agent views Agent Dashboard (auto-refreshes every 10s)
2. Agent clicks "Accept" on queued conversation
3. POST /chat/{id}/assign → ChatController.assignAgent()
4. ChatService.assignAgent() updates conversation:
   - Sets agent_id
   - Changes status to 'active'
   - Records started_at timestamp
   - Calculates response_time_seconds
5. System message added to conversation
6. Dashboard refreshes to show in "Active" section

### Message Exchange:
1. Customer types message and hits send
2. POST /chat/{id}/message → ChatController.sendMessage()
3. ChatService.addMessage() creates ChatMessage record
4. ChatService.updateAnalytics() increments counters
5. Agent's dashboard polling picks up new message
6. Agent responds similarly
7. Customer's polling picks up agent response

### Closing Chat with Rating:
1. Customer clicks close button
2. Rating form appears
3. Customer selects stars and optionally adds feedback
4. POST /chat/{id}/rating → ChatController.submitRating()
5. ChatService.addSatisfactionRating() updates analytics
6. POST /chat/{id}/close → ChatController.close()
7. ChatService.closeConversation() updates:
   - status to 'closed'
   - ended_at timestamp
   - resolution_time_seconds calculation
8. Chat widget resets to button state

## Scalability Considerations

### Current Implementation (Polling):
- Widget polls every 3 seconds
- Agent dashboard polls every 10 seconds
- Works well for up to ~100 concurrent conversations
- Simple infrastructure, no external dependencies

### Future Upgrade Path (WebSockets):
```
Customer ←→ Laravel Echo Client ←→ Laravel Echo Server ←→ Redis/Pusher
                                          ↕
Agent    ←→ Laravel Echo Client ←→ Laravel Echo Server

Benefits:
- True real-time updates (no delay)
- Reduced server load (no constant polling)
- Scalable to thousands of concurrent chats
- Better user experience
```

Implementation would require:
1. Install Laravel Echo and Pusher/Redis
2. Update ChatService to broadcast events
3. Update frontend to listen for events
4. Minimal code changes (architecture supports it)

## Security Layers

```
┌─────────────────────────────────────────┐
│  CSRF Protection (Laravel)              │
│  - All POST requests require token      │
└─────────────────────────────────────────┘
              ↓
┌─────────────────────────────────────────┐
│  Role-Based Access Control              │
│  - Agent endpoints check hasRole()      │
└─────────────────────────────────────────┘
              ↓
┌─────────────────────────────────────────┐
│  Input Validation                       │
│  - All inputs validated in controller   │
└─────────────────────────────────────────┘
              ↓
┌─────────────────────────────────────────┐
│  XSS Prevention                         │
│  - HTML escaped in views                │
└─────────────────────────────────────────┘
              ↓
┌─────────────────────────────────────────┐
│  SQL Injection Prevention               │
│  - Eloquent ORM parameterized queries   │
└─────────────────────────────────────────┘
```
