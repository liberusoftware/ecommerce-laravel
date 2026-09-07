<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReviewControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate('super_admin', 'web');

        return User::factory()->create()->assignRole('super_admin');
    }

    public function test_store()
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('reviews.store'), [
                'product_id' => $product->id,
                'rating' => 5,
                'review' => 'Great product!',
            ]);

        $response->assertStatus(201);

        // The account had no customer record until the review was written. It
        // has one now rather than the review being dropped as unmappable.
        $customer = $user->fresh()->customer;
        $this->assertNotNull($customer, 'No Customer was backfilled for the reviewer.');

        $this->assertDatabaseHas('product_reviews', [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'comments' => 'Great product!',
            'approved' => false,
        ]);
    }

    public function test_store_also_records_the_score_as_a_rating(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        $this->actingAs($user)->postJson('/reviews', [
            'product_id' => $product->id,
            'rating' => 4,
            'review' => 'Good.',
        ])->assertStatus(201);

        // A review carries a score, and after the merge a score is a rating —
        // its own record, so the product card and the averages endpoint see it.
        $this->assertDatabaseHas('product_rating', [
            'customer_id' => $user->fresh()->customer->id,
            'product_id' => $product->id,
            'rating' => 4,
            'overall_rating' => 4,
        ]);
    }

    public function test_approve()
    {
        $review = ProductReview::factory()->create([
            'comments' => 'Great product!',
            'approved' => false,
        ]);

        $this->actingAs($this->admin())->post(route('reviews.approve', ['id' => $review->id]));

        $this->assertDatabaseHas('product_reviews', [
            'id' => $review->id,
            'approved' => true,
        ]);
    }

    public function test_approve_returns_404_for_missing_review(): void
    {
        $response = $this->actingAs($this->admin())->postJson('/reviews/approve/9999');

        $response->assertStatus(404);
    }

    public function test_show_returns_only_approved_reviews(): void
    {
        $product = Product::factory()->create();
        $approved = ProductReview::factory()->approved()->create(['product_id' => $product->id]);
        $pending = ProductReview::factory()->create(['product_id' => $product->id]);

        $response = $this->getJson("/product/{$product->id}/reviews");

        $response->assertStatus(200);
        $ids = array_column($response->json(), 'id');
        $this->assertContains($approved->id, $ids);
        $this->assertNotContains($pending->id, $ids, 'An unmoderated review reached the public listing.');
    }

    public function test_show_publishes_no_reviewer_pii(): void
    {
        $product = Product::factory()->create();
        $customer = Customer::factory()->create([
            'first_name' => 'Sarah',
            'last_name' => 'Tregarthen',
            'email' => 'sarah@example.test',
            'phone_number' => '+44 7700 900123',
            'address' => '14 Bellwether Row',
            'city' => 'Falmouth',
            'postal_code' => 'TR11 3QA',
        ]);
        ProductReview::factory()->approved()->create([
            'product_id' => $product->id,
            'customer_id' => $customer->id,
        ]);

        $body = $this->getJson("/product/{$product->id}/reviews")->assertStatus(200)->content();

        // The route is unauthenticated and the product id is incrementing, so
        // anything reachable here is reachable for every reviewer in the shop.
        foreach ([
            'Tregarthen',
            'sarah@example.test',
            '7700 900123',
            'Bellwether Row',
            'Falmouth',
            'TR11 3QA',
        ] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "Public review listing published: {$leak}");
        }

        // The customer id joins one person's reviews together across products.
        $this->assertStringNotContainsString('customer_id', $body);
        $this->assertStringContainsString('Sarah', $body, 'A review page shows who wrote it.');
    }

    public function test_vote_helpful_increments_helpful_votes(): void
    {
        $review = ProductReview::factory()->create(['helpful_votes' => 0]);

        $response = $this->actingAs(User::factory()->create())->postJson("/reviews/{$review->id}/vote", ['vote' => 'helpful']);

        $response->assertStatus(200);
        $this->assertEquals(1, $review->fresh()->helpful_votes);
    }

    public function test_vote_unhelpful_increments_unhelpful_votes(): void
    {
        $review = ProductReview::factory()->create(['unhelpful_votes' => 0]);

        $response = $this->actingAs(User::factory()->create())->postJson("/reviews/{$review->id}/vote", ['vote' => 'unhelpful']);

        $response->assertStatus(200);
        $this->assertEquals(1, $review->fresh()->unhelpful_votes);
    }

    public function test_vote_returns_400_for_invalid_type(): void
    {
        $review = ProductReview::factory()->create();

        $response = $this->actingAs(User::factory()->create())->postJson("/reviews/{$review->id}/vote", ['vote' => 'bogus']);

        $response->assertStatus(400);
    }

    public function test_vote_returns_404_for_missing_review(): void
    {
        $response = $this->actingAs(User::factory()->create())->postJson('/reviews/9999/vote', ['vote' => 'helpful']);

        $response->assertStatus(404);
    }

    public function test_store_rejects_duplicate_review_from_same_user(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $payload = [
            'product_id' => $product->id,
            'rating' => 5,
            'review' => 'Great product!',
        ];

        $this->actingAs($user)->postJson('/reviews', $payload)->assertStatus(201);
        $this->actingAs($user)->postJson('/reviews', $payload)->assertStatus(409);

        $this->assertEquals(
            1,
            ProductReview::where('customer_id', $user->fresh()->customer->id)->where('product_id', $product->id)->count(),
        );
    }
}
