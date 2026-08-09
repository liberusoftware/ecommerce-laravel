<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductRating;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductRating>
 */
class ProductRatingFactory extends Factory
{
    protected $model = ProductRating::class;

    /**
     * `rating` and `overall_rating` agree by default, because they are the same
     * judgement: one is the headline the product card reads, the other is the
     * same number inside the breakdown. A fixture where they disagree is a
     * fixture no controller could have produced.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $score = $this->faker->numberBetween(1, 5);

        return [
            'customer_id' => Customer::factory(),
            'product_id' => Product::factory(),
            'rating' => $score,
            'overall_rating' => $score,
        ];
    }
}
