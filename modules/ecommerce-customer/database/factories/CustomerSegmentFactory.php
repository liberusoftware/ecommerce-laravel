<?php

namespace Database\Factories;

use App\Models\CustomerSegment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CustomerSegment>
 */
class CustomerSegmentFactory extends Factory
{
    protected $model = CustomerSegment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'conditions' => [
                [
                    'field' => fake()->randomElement(['lifetime_value', 'order_count', 'last_order_date']),
                    'operator' => fake()->randomElement(['>=', '<=', '=', '>']),
                    'value' => fake()->numberBetween(1, 1000),
                ],
            ],
            'match_type' => fake()->randomElement(['all', 'any']),
            'is_active' => true,
            'customer_count' => 0,
            'last_calculated_at' => null,
        ];
    }

    /**
     * Indicate that the segment is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
