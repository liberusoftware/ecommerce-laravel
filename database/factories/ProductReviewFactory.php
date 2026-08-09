<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductReview>
 */
class ProductReviewFactory extends Factory
{
    protected $model = ProductReview::class;

    /**
     * Unapproved by default, which is how a review arrives. A factory that
     * published by default would let a test assert on a moderated listing
     * without moderation ever having happened.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'product_id' => Product::factory(),
            'comments' => $this->faker->sentence,
            'approved' => false,
        ];
    }

    public function approved(): static
    {
        return $this->state(['approved' => true]);
    }
}
