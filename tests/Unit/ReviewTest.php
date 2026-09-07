<?php

namespace Tests\Unit;

use App\Models\ProductReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    // Locks the product_reviews schema drift fix: the model writes these columns.
    public function test_product_review_table_has_vote_and_verified_columns(): void
    {
        foreach (['is_verified_purchase', 'helpful_votes', 'unhelpful_votes'] as $col) {
            $this->assertTrue(
                Schema::hasColumn('product_reviews', $col),
                "product_reviews is missing the {$col} column the model writes"
            );
        }
    }

    /**
     * The column ADR 0008 says must survive the merge. It came from the retired
     * stack, and losing it turns the public listing unmoderated — a
     * content-safety regression wearing a schema cleanup's clothes.
     */
    public function test_product_review_carries_moderation(): void
    {
        $this->assertTrue(Schema::hasColumn('product_reviews', 'approved'));
    }

    public function test_product_review_helpfulness_score(): void
    {
        $review = new ProductReview(['helpful_votes' => 3, 'unhelpful_votes' => 1]);
        $this->assertEquals(75.0, $review->getHelpfulnessScore());
    }

    public function test_product_review_helpfulness_score_with_no_votes(): void
    {
        $review = new ProductReview(['helpful_votes' => 0, 'unhelpful_votes' => 0]);
        $this->assertEquals(0, $review->getHelpfulnessScore());
    }

    public function test_a_review_arrives_unapproved(): void
    {
        $review = ProductReview::factory()->create();

        $this->assertFalse($review->approved, 'A review published itself on arrival.');
    }

    public function test_review_can_be_approved()
    {
        $review = ProductReview::factory()->create();

        $review->approve();

        $this->assertTrue($review->approved);
        $this->assertDatabaseHas('product_reviews', [
            'id' => $review->id,
            'approved' => true,
        ]);
    }

    public function test_review_can_be_rejected()
    {
        $review = ProductReview::factory()->approved()->create();

        $review->reject();

        $this->assertFalse($review->approved);
        $this->assertDatabaseHas('product_reviews', [
            'id' => $review->id,
            'approved' => false,
        ]);
    }

    public function test_approved_review_remains_approved_if_approved_again()
    {
        $review = ProductReview::factory()->approved()->create();

        $review->approve();

        $this->assertTrue($review->approved);
    }

    public function test_rejected_review_remains_rejected_if_rejected_again()
    {
        $review = ProductReview::factory()->create();

        $review->reject();

        $this->assertFalse($review->approved);
    }

    public function test_the_approved_scope_is_what_the_public_listing_sees(): void
    {
        ProductReview::factory()->approved()->create();
        ProductReview::factory()->create();

        $this->assertSame(1, ProductReview::query()->approved()->count());
    }
}
