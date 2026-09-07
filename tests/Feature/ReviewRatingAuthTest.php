<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReviewRatingAuthTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate('super_admin', 'web');

        return User::factory()->create()->assignRole('super_admin');
    }

    public function test_guest_cannot_store_review(): void
    {
        $product = Product::factory()->create();

        $this->postJson('/reviews', ['product_id' => $product->id, 'rating' => 5, 'review' => 'x'])
            ->assertStatus(401);
    }

    public function test_guest_cannot_store_rating(): void
    {
        $product = Product::factory()->create();

        $this->postJson('/ratings', [
            'product_id' => $product->id, 'overall_rating' => 4,
            'quality_rating' => 4, 'value_rating' => 3, 'price_rating' => 5,
        ])->assertStatus(401);
    }

    public function test_guest_cannot_vote(): void
    {
        $review = ProductReview::factory()->create();

        $this->postJson("/reviews/{$review->id}/vote", ['vote' => 'helpful'])->assertStatus(401);
    }

    public function test_non_admin_cannot_approve_review(): void
    {
        $review = ProductReview::factory()->create(['approved' => false]);

        $this->actingAs(User::factory()->create())
            ->postJson("/reviews/approve/{$review->id}")->assertStatus(403);

        $this->assertFalse((bool) $review->fresh()->approved);
    }

    public function test_admin_can_approve_review(): void
    {
        $review = ProductReview::factory()->create(['approved' => false]);

        $this->actingAs($this->admin())
            ->postJson("/reviews/approve/{$review->id}")->assertStatus(200);

        $this->assertTrue((bool) $review->fresh()->approved);
    }

    public function test_review_and_rating_reads_stay_public(): void
    {
        $product = Product::factory()->create();

        $this->getJson("/product/{$product->id}/reviews")->assertStatus(200);
        $this->getJson("/product/{$product->id}/ratings/average")->assertStatus(200);
    }
}
