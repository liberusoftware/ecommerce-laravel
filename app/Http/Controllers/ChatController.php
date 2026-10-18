<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Services\ChatService;
use App\Support\StoreAccess;
use App\Support\TeamPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChatController extends Controller
{
    protected ChatService $chatService;

    public function __construct(ChatService $chatService)
    {
        $this->chatService = $chatService;
    }

    /**
     * A conversation is addressable by its sequential id, so the customer-facing
     * endpoints must prove ownership: either the caller is an agent, or the
     * conversation's (unguessable) session_id was bound to this browser session
     * when the chat was started. Otherwise a stranger could enumerate ids and read
     * everyone's chat history + PII.
     */
    private function authorizeConversationAccess(ChatConversation $conversation): void
    {
        $isAgent = Auth::check() && StoreAccess::allows(Auth::user(), TeamPermission::CUSTOMERS_MANAGE, null, 'update_chat::conversation');
        $ownsSession = in_array($conversation->session_id, session('chat_conversations', []), true);

        abort_unless($isAgent || $ownsSession, 403);
    }

    /**
     * Start a new chat conversation.
     */
    public function start(Request $request)
    {
        $validated = $request->validate([
            'customer_name' => 'nullable|string|max:255',
            'customer_email' => 'nullable|email|max:255',
        ]);

        $conversation = $this->chatService->createConversation($validated);

        // Bind this conversation to the browser session so its later message/read
        // calls (addressed by sequential id) can be authorized.
        session()->push('chat_conversations', $conversation->session_id);

        return response()->json([
            'success' => true,
            'conversation' => $conversation,
        ]);
    }

    /**
     * Send a message in a conversation.
     */
    public function sendMessage(Request $request, $conversationId)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:5000',
        ]);

        $conversation = ChatConversation::find($conversationId);
        if (! $conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }
        $this->authorizeConversationAccess($conversation);

        $message = $this->chatService->addMessage($conversationId, [
            'message' => $validated['message'],
            'sender_type' => Auth::check() && StoreAccess::allows(Auth::user(), TeamPermission::CUSTOMERS_MANAGE, null, 'update_chat::conversation') ? 'agent' : 'customer',
        ]);

        return response()->json([
            'success' => true,
            'message' => $message,
        ]);
    }

    /**
     * Get conversation messages.
     */
    public function getMessages($conversationId)
    {
        $conversation = $this->chatService->getConversation($conversationId);

        if (! $conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        $this->authorizeConversationAccess($conversation);

        // Mark messages as read
        $readerType = Auth::check() && StoreAccess::allows(Auth::user(), TeamPermission::CUSTOMERS_MANAGE, null, 'update_chat::conversation') ? 'agent' : 'customer';
        $this->chatService->markMessagesAsRead($conversationId, $readerType);

        return response()->json([
            'success' => true,
            'conversation' => $conversation,
        ]);
    }

    /**
     * Get conversation by session ID.
     */
    public function getBySession($sessionId)
    {
        $conversation = $this->chatService->getConversationBySession($sessionId);

        return response()->json([
            'success' => true,
            'conversation' => $conversation,
        ]);
    }

    /**
     * Close a conversation.
     */
    public function close($conversationId)
    {
        $conversation = ChatConversation::find($conversationId);
        if (! $conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }
        $this->authorizeConversationAccess($conversation);

        $conversation = $this->chatService->closeConversation($conversationId);

        return response()->json([
            'success' => true,
            'conversation' => $conversation,
        ]);
    }

    /**
     * Submit satisfaction rating.
     */
    public function submitRating(Request $request, $conversationId)
    {
        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'feedback' => 'nullable|string|max:1000',
        ]);

        $conversation = ChatConversation::find($conversationId);
        if (! $conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }
        $this->authorizeConversationAccess($conversation);

        $this->chatService->addSatisfactionRating(
            $conversationId,
            $validated['rating'],
            $validated['feedback'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Thank you for your feedback!',
        ]);
    }

    /**
     * Get agent's active conversations (for agent dashboard).
     */
    public function agentConversations()
    {
        if (! Auth::check() || ! StoreAccess::allows(Auth::user(), TeamPermission::CUSTOMERS_MANAGE, null, 'update_chat::conversation')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $conversations = $this->chatService->getAgentConversations(Auth::id());

        return response()->json([
            'success' => true,
            'conversations' => $conversations,
        ]);
    }

    /**
     * Assign agent to a conversation.
     */
    public function assignAgent(Request $request, $conversationId)
    {
        if (! Auth::check() || ! StoreAccess::allows(Auth::user(), TeamPermission::CUSTOMERS_MANAGE, null, 'update_chat::conversation')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $conversation = $this->chatService->assignAgent($conversationId, Auth::id());

        return response()->json([
            'success' => true,
            'conversation' => $conversation,
        ]);
    }

    /**
     * Get next queued conversation.
     */
    public function nextQueued()
    {
        if (! Auth::check() || ! StoreAccess::allows(Auth::user(), TeamPermission::CUSTOMERS_MANAGE, null, 'update_chat::conversation')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $conversation = $this->chatService->getNextQueuedConversation();

        return response()->json([
            'success' => true,
            'conversation' => $conversation,
        ]);
    }
}
