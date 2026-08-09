<?php

namespace App\Models;

use App\Traits\IsStoreScoped;
use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A conversation is a customer talking to one merchant, on one storefront.
 *
 * It had neither key until now, which is why `ChatConversationResource` opted
 * out of Filament's tenancy — honestly, and with a comment saying the column
 * was not there. The opt-out was accurate and the consequence was still a leak:
 * staff on any team read every team's conversations, including the customer
 * name, email and every message body.
 *
 * Both keys, for the reason `Product` carries both. `store_id` is the grain the
 * storefront reads on and it is what the global scope filters; `team_id` is
 * what Filament's tenancy joins on in the panel. Neither is in `$fillable` —
 * the traits' `creating` hooks are the only writers, so no request can post
 * its way into another merchant's tenancy.
 */
class ChatConversation extends Model
{
    use HasFactory;
    use IsStoreScoped;
    use IsTenantModel;

    protected $fillable = [
        'session_id',
        'user_id',
        'agent_id',
        'status',
        'started_at',
        'ended_at',
        'queue_position',
        'customer_name',
        'customer_email',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    /**
     * Get the customer associated with the conversation.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the agent assigned to the conversation.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * Get the messages for the conversation.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }

    /**
     * Get the analytics for the conversation.
     */
    public function analytics(): HasOne
    {
        return $this->hasOne(ChatAnalytics::class, 'conversation_id');
    }

    /**
     * Scope to get active conversations.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to get queued conversations.
     */
    public function scopeQueued($query)
    {
        return $query->where('status', 'queued')->orderBy('queue_position');
    }

    /**
     * Scope to get conversations for a specific agent.
     */
    public function scopeForAgent($query, $agentId)
    {
        return $query->where('agent_id', $agentId);
    }
}
