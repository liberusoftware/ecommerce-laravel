<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductRating;
use App\Models\ProductReview;
use App\Models\User;
use App\Models\Wishlist;
use App\Services\GdprErasureService;
use App\Services\GdprExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A subject-access export and an erasure are two views of one set: whatever the
 * export shows, the erasure must be able to remove. Each service looks complete
 * alone, so these check both against one person with a row in every store of
 * personal data.
 */
class GdprSymmetryTest extends TestCase
{
    use RefreshDatabase;

    private function populatedUser(): User
    {
        $user = User::factory()->create();
        $customer = $user->getOrCreateCustomer();
        $product = Product::factory()->create();

        Wishlist::create(['user_id' => $user->id, 'product_id' => $product->id]);
        PaymentMethod::create(['user_id' => $user->id, 'name' => 'Visa …4242', 'details' => 'tok_x']);
        ProductReview::create(['product_id' => $product->id, 'customer_id' => $customer->id, 'comments' => 'Lovely']);
        ProductRating::create(['product_id' => $product->id, 'customer_id' => $customer->id, 'rating' => 5]);

        $user->wishlist_share_token = 'a-published-share-token';
        $user->save();

        return $user;
    }

    public function test_the_wishlist_is_disclosed_because_erasure_destroys_it(): void
    {
        $export = app(GdprExportService::class)->export($this->populatedUser());

        $this->assertCount(1, $export['wishlist'] ?? [], 'erasure deletes the wishlist, so access must show it');
    }

    public function test_erasure_revokes_a_published_share_link(): void
    {
        $user = $this->populatedUser();

        app(GdprErasureService::class)->erase($user);

        $this->assertNull($user->fresh()->wishlist_share_token);
    }

    /** A new store of personal data added to only one of the two services fails here. */
    public function test_nothing_the_export_can_show_survives_an_erasure(): void
    {
        $user = $this->populatedUser();

        $export = app(GdprExportService::class)->export($user);
        $disclosed = array_filter([
            'wishlist' => $export['wishlist'],
            'payment_methods' => $export['payment_methods'],
            'reviews' => $export['reviews'],
            'ratings' => $export['ratings'],
        ]);
        $this->assertCount(4, $disclosed, 'the fixture must actually populate these, or this proves nothing');

        app(GdprErasureService::class)->erase($user);
        $after = app(GdprExportService::class)->export($user->fresh());

        foreach (array_keys($disclosed) as $key) {
            $this->assertEmpty($after[$key], "'{$key}' was disclosed by the export and survived the erasure");
        }
    }
}
