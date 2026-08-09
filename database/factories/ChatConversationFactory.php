<?php

namespace Database\Factories;

use App\Models\ChatConversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatConversation>
 */
class ChatConversationFactory extends Factory
{
    protected $model = ChatConversation::class;

    public function definition(): array
    {
        return [
            'session_id' => $this->faker->uuid(),
            'status' => 'queued',
            'customer_name' => $this->faker->name(),
            'customer_email' => $this->faker->safeEmail(),
        ];
    }

    /**
     * `store_id` and `team_id` are absent from the definition on purpose.
     *
     * The traits' `creating` hooks derive both from the resolved store, and a
     * factory that supplied them would test the factory rather than the hook.
     * A test that needs a specific store passes it explicitly — factories
     * bypass mass assignment, which is what makes that possible without
     * putting either key in `$fillable`.
     */
    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active', 'started_at' => now()]);
    }
}
