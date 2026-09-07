<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductRating;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RatingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The average a shopper actually sees. `Rating::calculateAverageRating()`
     * went with the retired stack; the product card has always read this one.
     */
    public function test_product_average_rating_is_the_mean_of_its_ratings(): void
    {
        $product = Product::factory()->create();

        ProductRating::factory()->create(['product_id' => $product->id, 'rating' => 5]);
        ProductRating::factory()->create(['product_id' => $product->id, 'rating' => 4]);

        $this->assertEquals(4.5, $product->getAverageRating());
    }

    public function test_product_average_rating_is_zero_with_no_ratings(): void
    {
        // Zero rather than null: the card renders a number, and `?? 0` is where
        // "no ratings yet" is decided.
        $this->assertEquals(0, Product::factory()->create()->getAverageRating());
    }

    // Locks the product_rating schema drift fix: the model writes these columns.
    public function test_product_rating_table_has_detailed_columns(): void
    {
        foreach (['overall_rating', 'quality_rating', 'value_rating', 'price_rating'] as $col) {
            $this->assertTrue(
                Schema::hasColumn('product_rating', $col),
                "product_rating is missing the {$col} column the model writes"
            );
        }
    }

    public function test_product_rating_average_is_mean_of_four_sub_ratings(): void
    {
        $rating = new ProductRating([
            'overall_rating' => 4,
            'quality_rating' => 3,
            'value_rating' => 5,
            'price_rating' => 4,
        ]);

        $this->assertEquals(4.0, $rating->getAverageRating());
    }
}
