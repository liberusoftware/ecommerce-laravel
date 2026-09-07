<?php

namespace Tests\Feature;

use App\Models\GiftRegistry;
use App\Models\GiftRegistryItem;
use App\Models\GiftRegistryPurchase;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductRating;
use App\Models\ProductReview;
use App\Models\User;
use App\Services\GdprErasureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GDPR content-erasure (Art. 17 follow-up to the identity/PII erasure). The user's
 * reviews, ratings and gift registries are user-authored content — user_id/customer_id
 * are NOT NULL and reviews carry free text, so they are DELETED, not anonymised.
 * Product rating/review aggregates recompute naturally. Another user's content must be
 * untouched.
 */
class GdprContentErasureTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->product = Product::factory()->create();
    }

    /** Seed one of every content type for a user; returns [user, customerId]. */
    private function seedContentFor(string $slugPrefix): array
    {
        $user = User::factory()->create();
        $customer = $user->getOrCreateCustomer();

        (new ProductReview)->forceFill(['product_id' => $this->product->id, 'customer_id' => $customer->id, 'comments' => 'My private thoughts'])->save();
        (new ProductRating)->forceFill(['product_id' => $this->product->id, 'customer_id' => $customer->id, 'rating' => 3, 'overall_rating' => 3])->save();

        $registry = GiftRegistry::create([
            'user_id' => $user->id, 'name' => 'Wedding', 'slug' => $slugPrefix.'-wedding',
            'shipping_name' => 'Jane Doe', 'shipping_address' => '1 Test St',
        ]);
        $item = GiftRegistryItem::create(['registry_id' => $registry->id, 'product_id' => $this->product->id, 'quantity_requested' => 1]);
        $order = Order::create(['customer_email' => 'buyer@example.com', 'total_amount' => 10, 'status' => 'paid']);
        GiftRegistryPurchase::create([
            'registry_item_id' => $item->id, 'order_id' => $order->id, 'quantity' => 1,
            'purchaser_name' => 'Gift Buyer', 'purchaser_email' => 'buyer@example.com',
        ]);

        return [$user, $customer->id];
    }

    public function test_erasure_deletes_the_users_reviews_ratings_and_registries(): void
    {
        [$user, $customerId] = $this->seedContentFor('victim');
        [$survivor, $survivorCustomerId] = $this->seedContentFor('survivor');

        app(GdprErasureService::class)->erase($user);

        // The erased user's content is gone.
        $this->assertSame(0, ProductReview::where('customer_id', $customerId)->count());
        $this->assertSame(0, ProductRating::where('customer_id', $customerId)->count());
        $this->assertSame(0, GiftRegistry::where('user_id', $user->id)->count());
        // Registry children cascade away with the registry.
        $this->assertSame(0, GiftRegistryItem::count() - GiftRegistryItem::whereHas('registry', fn ($q) => $q->where('user_id', $survivor->id))->count());
        $this->assertSame(0, GiftRegistryPurchase::count() - 1); // only the survivor's purchase remains

        // The other user's content is untouched.
        $this->assertSame(1, ProductReview::where('customer_id', $survivorCustomerId)->count());
        $this->assertSame(1, ProductRating::where('customer_id', $survivorCustomerId)->count());
        $this->assertSame(1, GiftRegistry::where('user_id', $survivor->id)->count());
    }
}
