<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatAnalytics extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'response_time_seconds',
        'resolution_time_seconds',
        'message_count',
        'agent_message_count',
        'customer_message_count',
        'satisfaction_rating',
        'satisfaction_feedback',
    ];

    /**
     * Get the conversation that owns the analytics.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    /**
     * Calculate average response time for multiple analytics records.
     */
    public static function averageResponseTime()
    {
        return static::inScope()->whereNotNull('response_time_seconds')->avg('response_time_seconds');
    }

    /**
     * Calculate average satisfaction rating.
     */
    public static function averageSatisfactionRating()
    {
        return static::inScope()->whereNotNull('satisfaction_rating')->avg('satisfaction_rating');
    }

    /**
     * Analytics rows carry no tenant key of their own — they hang off a
     * conversation, and the conversation is what is scoped.
     *
     * `whereHas` rather than a join, because the global scope on
     * `ChatConversation` applies to the subquery for free. Both callers of the
     * two averages above are panel surfaces — `ChatStatsWidget` and
     * `ChatAgentDashboard` — and a widget is exactly where Filament's own
     * tenancy does not reach.
     */
    protected static function inScope(): Builder
    {
        return static::whereHas('conversation');
    }
}
