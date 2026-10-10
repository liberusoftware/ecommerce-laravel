<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductTag;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductTag>
 */
class ProductTagFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'tag_id' => Tag::factory(),
        ];
    }

    public function withName(string $name)
    {
        return $this->state(fn (array $attributes) => [
            'tag_id' => Tag::factory()->create(['name' => $name])->id,
        ]);
    }

    public function withNames(array $names)
    {
        return $this->state(function (array $attributes) use ($names) {
            $tags = collect($names)->map(fn ($name) => Tag::factory()->create(['name' => $name])->id);

            return ProductTag::factory()->count(count($names))->sequence(
                ...$tags->map(fn ($tagId) => ['tag_id' => $tagId])->all()
            );
        });
    }
}
