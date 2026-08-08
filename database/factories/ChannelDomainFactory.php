<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\ChannelDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelDomain>
 */
class ChannelDomainFactory extends Factory
{
    protected $model = ChannelDomain::class;

    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'host' => $this->faker->unique()->domainName(),
            'is_primary' => false,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn () => ['is_primary' => true]);
    }
}
