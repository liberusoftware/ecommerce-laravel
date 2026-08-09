<?php

namespace Database\Factories;

use App\Models\ChatAnalytics;
use App\Models\ChatConversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatAnalytics>
 */
class ChatAnalyticsFactory extends Factory
{
    protected $model = ChatAnalytics::class;

    public function definition(): array
    {
        return [
            'conversation_id' => ChatConversation::factory(),
            'message_count' => 0,
            'agent_message_count' => 0,
            'customer_message_count' => 0,
        ];
    }
}
