<?php

namespace Database\Factories;

use App\Models\GiftRegistry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GiftRegistry>
 */
class GiftRegistryFactory extends Factory
{
    protected $model = GiftRegistry::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->words(3, true);

        return [
            'user_id' => User::factory(),
            'name' => $name,
            'slug' => Str::slug($name) . '-' . fake()->unique()->numberBetween(1000, 9999),
            'type' => fake()->randomElement(['wedding', 'baby', 'birthday', 'anniversary', 'holiday', 'other']),
            'event_date' => fake()->optional()->dateTimeBetween('+1 month', '+2 years'),
            'message' => fake()->optional()->sentence(),
            'location' => fake()->optional()->city(),
            'privacy' => fake()->randomElement(['public', 'private', 'link_only']),
            'access_code' => null,
            'is_active' => true,
            'shipping_name' => fake()->name(),
            'shipping_address' => fake()->streetAddress(),
            'shipping_city' => fake()->city(),
            'shipping_state' => fake()->stateAbbr(),
            'shipping_postal_code' => fake()->postcode(),
            'shipping_country' => fake()->countryCode(),
        ];
    }

    /**
     * Indicate that the registry is private.
     */
    public function private(): static
    {
        return $this->state(fn (array $attributes) => [
            'privacy' => 'private',
            'access_code' => Str::random(8),
        ]);
    }
}
