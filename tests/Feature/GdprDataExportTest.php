<?php

namespace Tests\Feature;

use App\Models\BrowsingHistory;
use App\Models\CustomerSegment;
use App\Models\GiftRegistry;
use App\Models\GiftRegistryItem;
use App\Models\GiftRegistryPurchase;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductInteraction;
use App\Models\ProductRating;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GDPR right-of-access (Art. 15 / data portability Art. 20): an authenticated user
 * can download their own personal data as JSON. The export must be complete for the
 * core identity + transactional data yet must never leak credentials/secrets or raw
 * payment-method details.
 */
class GdprDataExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_requires_authentication(): void
    {
        $this->get(route('account.data-export'))->assertRedirect(route('login'));
    }

    public function test_export_returns_the_users_profile_customer_and_orders(): void
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
        $customer = $user->getOrCreateCustomer();
        $order = Order::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'customer_email' => 'ada@example.com',
            'order_date' => now()->toDateString(),
            'total_amount' => 42.50,
            'payment_status' => 'paid',
            'shipping_status' => 'pending',
            'status' => 'paid',
        ]);

        $response = $this->actingAs($user)->getJson(route('account.data-export'));

        $response->assertOk();
        $response->assertJsonPath('user.email', 'ada@example.com');
        $response->assertJsonPath('user.name', 'Ada Lovelace');
        $response->assertJsonPath('customer.first_name', 'Ada');
        $response->assertJsonPath('orders.0.id', $order->id);
        $response->assertJsonPath('orders.0.total_amount', '42.50');
    }

    public function test_export_never_leaks_secrets_or_raw_payment_details(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('super-secret-pw'),
            'two_factor_secret' => 'TOTPSECRETVALUE',
        ]);
        PaymentMethod::create([
            'user_id' => $user->id,
            'name' => 'Visa ending 4242',
            'details' => 'tok_live_RAWCARDTOKEN',
            'is_default' => true,
        ]);

        $response = $this->actingAs($user)->getJson(route('account.data-export'));
        $response->assertOk();
        $body = $response->getContent();

        // Credentials / 2FA material must never appear.
        $this->assertStringNotContainsString($user->password, $body, 'Password hash leaked in export');
        $this->assertStringNotContainsString('TOTPSECRETVALUE', $body, '2FA secret leaked in export');
        $this->assertStringNotContainsString('remember_token', $body);
        // Payment-method metadata is fine, but the raw stored details must not be.
        $this->assertStringNotContainsString('tok_live_RAWCARDTOKEN', $body, 'Raw payment details leaked in export');
        $response->assertJsonPath('payment_methods.0.name', 'Visa ending 4242');
    }

    public function test_export_includes_reviews_ratings_and_registries_in_symmetry_with_erasure(): void
    {
        $user = User::factory()->create();
        $customer = $user->getOrCreateCustomer();
        $product = Product::factory()->create();

        (new ProductReview)->forceFill(['product_id' => $product->id, 'customer_id' => $customer->id, 'comments' => 'MY-COMMENT'])->save();
        (new ProductRating)->forceFill(['product_id' => $product->id, 'customer_id' => $customer->id, 'rating' => 3, 'overall_rating' => 3])->save();

        $registry = GiftRegistry::create([
            'user_id' => $user->id, 'name' => 'MyRegistry', 'slug' => 'my-registry',
            'access_code' => 'SECRETCODE123', 'shipping_name' => 'Jane',
        ]);
        $item = GiftRegistryItem::create(['registry_id' => $registry->id, 'product_id' => $product->id, 'quantity_requested' => 1, 'notes' => 'ITEM-NOTE']);
        $order = Order::create(['customer_email' => 'x@y.com', 'total_amount' => 10, 'status' => 'paid']);
        GiftRegistryPurchase::create([
            'registry_item_id' => $item->id, 'order_id' => $order->id, 'quantity' => 1,
            'purchaser_name' => 'THIRDPARTY-BUYER', 'purchaser_email' => 'buyer@third.com',
        ]);

        $response = $this->actingAs($user)->getJson(route('account.data-export'));

        $response->assertOk();
        // One stack since ADR 0008, under the keys a person understands.
        $response->assertJsonPath('reviews.0.comments', 'MY-COMMENT');
        $response->assertJsonPath('reviews.0.approved', false);
        $response->assertJsonPath('ratings.0.overall_rating', 3);
        $response->assertJsonPath('gift_registries.0.name', 'MyRegistry');
        $response->assertJsonPath('gift_registries.0.items.0.notes', 'ITEM-NOTE');

        $body = $response->getContent();
        // A private registry's access code is a secret — never exported.
        $this->assertStringNotContainsString('SECRETCODE123', $body, 'Registry access code leaked in export');
        // Registry purchases carry a third party's PII — not the exporting user's data.
        $this->assertStringNotContainsString('THIRDPARTY-BUYER', $body, "Third-party purchaser PII leaked in the user's export");
    }

    public function test_export_includes_behavioural_data_but_not_internal_segment_rules(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        BrowsingHistory::create(['user_id' => $user->id, 'product_id' => $product->id]);
        ProductInteraction::create([
            'user_id' => $user->id, 'product_id' => $product->id,
            'interaction_type' => 'add_to_cart', 'duration' => 12, 'metadata' => ['ref' => 'HOMEPAGE'],
            'interacted_at' => now(),
        ]);
        $segment = CustomerSegment::create([
            'name' => 'VIP', 'description' => 'Top spenders',
            'conditions' => [['field' => 'lifetime_value', 'operator' => '>', 'value' => 'SECRET-RULE-9000']],
            'match_type' => 'all',
        ]);
        $user->customerSegments()->attach($segment->id);

        $response = $this->actingAs($user)->getJson(route('account.data-export'));

        $response->assertOk();
        $response->assertJsonPath('browsing_history.0.product_id', $product->id);
        $response->assertJsonPath('product_interactions.0.interaction_type', 'add_to_cart');
        $response->assertJsonPath('product_interactions.0.metadata.ref', 'HOMEPAGE');
        $response->assertJsonPath('segments.0.name', 'VIP');

        // The segment's internal matching rules are not the user's data — never exported.
        $this->assertStringNotContainsString('SECRET-RULE-9000', $response->getContent());
    }
}
