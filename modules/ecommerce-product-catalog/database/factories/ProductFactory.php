<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\Illuminate\Database\Eloquent\Model>
     */
    protected $model = Product::class;
    
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            "name" => $this->faker->unique()->words(3, true),
            "short_description" => $this->faker->paragraph(),
            "long_description" => $this->faker->paragraph(),
            "category_id" => ProductCategory::factory(),
            'featured_image' => 'https://placehold.co/'
                . $this->faker->numberBetween(300, 800) . 'x'
                . $this->faker->numberBetween(200, 600) . '/'
                . $this->faker->safeColorName() . '/'
                . $this->faker->safeColorName()
                . '.png?text=' . rawurlencode($this->faker->words(2, true)),
            'price' => $this->faker->randomFloat(2, 10, 1000),
        ];
    }

    /**
     * Configure the factory to auto-generate slug from name after making.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Product $product) {
            if (empty($product->slug)) {
                $product->slug = \Illuminate\Support\Str::slug($product->name);
            }
        });
    }
}
