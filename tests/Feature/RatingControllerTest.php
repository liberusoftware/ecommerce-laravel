<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductRating;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RatingControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(): Product
    {
        $category = ProductCategory::create([
            'name' => 'Rating Cat',
            'slug' => 'rating-cat-'.uniqid(),
        ]);

        return Product::create([
            'name' => 'Rating Product',
            'slug' => 'rating-prod-'.uniqid(),
            'price' => 30.00,
            'category_id' => $category->id,
            'inventory_count' => 5,
        ]);
    }

    public function test_store_creates_rating_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct();

        $response = $this->actingAs($user)->postJson('/ratings', [
            'product_id' => $product->id,
            'overall_rating' => 4,
            'quality_rating' => 4,
            'value_rating' => 3,
            'price_rating' => 5,
        ]);

        $response->assertStatus(201);

        // Keyed to the Customer, backfilled by the write — the account alone is
        // not who leaves a rating.
        $this->assertDatabaseHas('product_rating', [
            'customer_id' => $user->fresh()->customer->id,
            'product_id' => $product->id,
            'overall_rating' => 4,
            'rating' => 4,
        ]);
    }

    public function test_calculate_average_rating_returns_averages(): void
    {
        $product = $this->makeProduct();

        ProductRating::factory()->create([
            'product_id' => $product->id,
            'rating' => 4,
            'overall_rating' => 4,
            'quality_rating' => 3,
            'value_rating' => 5,
            'price_rating' => 4,
        ]);

        $response = $this->getJson("/product/{$product->id}/ratings/average");

        $response->assertStatus(200);
        $response->assertJsonStructure(['averageRatings', 'overallAverage']);
        $this->assertEquals(4, $response->json('averageRatings.overall'));
    }

    public function test_calculate_average_returns_null_averages_for_no_ratings(): void
    {
        $product = $this->makeProduct();

        $response = $this->getJson("/product/{$product->id}/ratings/average");

        $response->assertStatus(200);
        $response->assertJsonPath('averageRatings.overall', null);
        $response->assertJsonPath('overallAverage', null);
    }

    public function test_overall_average_is_mean_of_category_averages(): void
    {
        $product = $this->makeProduct();

        // Category averages: overall=3, quality=3, value=3, price=3 -> overallAverage 3.0
        ProductRating::factory()->create([
            'product_id' => $product->id, 'rating' => 5,
            'overall_rating' => 5, 'quality_rating' => 5, 'value_rating' => 5, 'price_rating' => 5,
        ]);
        ProductRating::factory()->create([
            'product_id' => $product->id, 'rating' => 1,
            'overall_rating' => 1, 'quality_rating' => 1, 'value_rating' => 1, 'price_rating' => 1,
        ]);

        $response = $this->getJson("/product/{$product->id}/ratings/average");

        $response->assertStatus(200);
        $this->assertEquals(3.0, $response->json('overallAverage'));
    }

    public function test_store_rejects_duplicate_rating_from_same_user(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct();
        $payload = [
            'product_id' => $product->id,
            'overall_rating' => 4, 'quality_rating' => 4, 'value_rating' => 3, 'price_rating' => 5,
        ];

        $this->actingAs($user)->postJson('/ratings', $payload)->assertStatus(201);
        $this->actingAs($user)->postJson('/ratings', $payload)->assertStatus(409);

        $this->assertEquals(
            1,
            ProductRating::where('customer_id', $user->fresh()->customer->id)->where('product_id', $product->id)->count(),
        );
    }
}
