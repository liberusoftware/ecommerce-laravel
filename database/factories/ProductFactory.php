<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Model>
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
            'name' => $this->faker->unique()->words(3, true),
            'short_description' => $this->faker->paragraph(),
            'long_description' => $this->faker->paragraph(),
            'category_id' => ProductCategory::factory(),
            // No image: the storefront shows its local placeholder rather than
            // fetching a random external one.
            'featured_image' => null,
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
                $product->slug = Str::slug($product->name);
            }
        });
    }
}
