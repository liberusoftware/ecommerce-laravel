<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductRating;
use App\Models\ProductReview;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rows created outside a panel must carry their store's team, or the panels,
 * which filter resources by team_id, never show them to the merchant.
 */
class StorefrontRowTeamAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_storefront_review_and_rating_belong_to_the_stores_team(): void
    {
        $team = Team::factory()->create();
        $store = Store::query()->firstOrFail();
        $store->update(['team_id' => $team->id]);
        $product = Product::factory()->create(['store_id' => $store->id, 'team_id' => $team->id]);

        $this->actingAs(User::factory()->create())
            ->postJson('/reviews', ['product_id' => $product->id, 'rating' => 4, 'review' => 'Good.'])
            ->assertSuccessful();

        $this->assertSame($team->id, (int) ProductReview::withoutGlobalScopes()->value('team_id'));
        $this->assertSame($team->id, (int) ProductRating::withoutGlobalScopes()->value('team_id'));
    }

    public function test_an_invoice_takes_its_team_and_store_from_the_order(): void
    {
        $team = Team::factory()->create();
        $store = Store::factory()->create(['team_id' => $team->id]);
        $order = Order::forceCreate([
            'store_id' => $store->id, 'team_id' => $team->id, 'customer_email' => 'guest@example.test',
            'total_amount' => 10, 'status' => 'paid', 'payment_status' => 'paid', 'order_date' => now(),
        ]);

        // As from a payment webhook: no user, no storefront host, several stores.
        $invoice = Invoice::generateForOrder($order);

        $this->assertSame($team->id, (int) $invoice->team_id);
        $this->assertSame($store->id, (int) $invoice->store_id);

        $guest = $invoice->customer()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame($team->id, (int) $guest->team_id);
        $this->assertSame($store->id, (int) $guest->store_id);
    }
}
