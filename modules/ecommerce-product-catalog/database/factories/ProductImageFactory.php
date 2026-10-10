<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductImage>
 */
class ProductImageFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\Illuminate\Database\Eloquent\Model>
     */
    protected $model = ProductImage::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'image' => 'https://placehold.co/'
                . $this->faker->numberBetween(300, 800) . 'x'
                . $this->faker->numberBetween(200, 600) . '/'
                . $this->faker->safeColorName() . '/'
                . $this->faker->safeColorName()
                . '.png?text=' . rawurlencode($this->faker->word()),
            'order' => $this->faker->numberBetween(1, 10),
        ];
    }
}
