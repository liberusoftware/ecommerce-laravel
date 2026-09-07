<?php

namespace Tests\Feature;

use App\Jobs\RemoveProductFromFacebookCatalog;
use App\Jobs\SyncProductToFacebookCatalog;
use App\Models\Product;
use App\Models\ProductFacebookListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\Concerns\ConnectsAFacebookCatalog;
use Tests\TestCase;

class FacebookCatalogUnlistTest extends TestCase
{
    use ConnectsAFacebookCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectedTeam();
    }

    /** A listed Product with an existing Listing, built without firing sync. */
    private function listedWithListing(int $inventory = 5): Product
    {
        $product = $this->product(['inventory_count' => $inventory]);

        $product->facebookListings()->create([
            'retailer_id' => 'product-'.$product->id,
            'status' => 'active',
        ]);

        $product->list_on_facebook = true;
        $product->saveQuietly();

        return $product;
    }

    public function test_toggling_off_enqueues_removal(): void
    {
        $product = $this->listedWithListing();

        Queue::fake();
        $product->update(['list_on_facebook' => false]);

        Queue::assertPushed(
            RemoveProductFromFacebookCatalog::class,
            fn (RemoveProductFromFacebookCatalog $job) => $job->retailerIds === ['product-'.$product->id]
                && $job->teamId === $this->team->id
        );
        Queue::assertNotPushed(SyncProductToFacebookCatalog::class);
    }

    public function test_deleting_a_listed_product_enqueues_removal(): void
    {
        $product = $this->listedWithListing();

        Queue::fake();
        $product->delete();

        Queue::assertPushed(
            RemoveProductFromFacebookCatalog::class,
            fn (RemoveProductFromFacebookCatalog $job) => $job->retailerIds === ['product-'.$product->id]
        );
    }

    public function test_force_deleting_still_enqueues_removal_before_cascade(): void
    {
        $product = $this->listedWithListing();

        Queue::fake();
        $product->forceDelete();

        // `deleting` fires before the FK cascade wipes the listing rows, so the
        // retailer ids are still there to capture.
        Queue::assertPushed(
            RemoveProductFromFacebookCatalog::class,
            fn (RemoveProductFromFacebookCatalog $job) => $job->retailerIds === ['product-'.$product->id]
        );
    }

    public function test_removal_job_sends_delete_and_marks_listing_deleted(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['handles' => ['h1']], 200)]);
        $product = $this->listedWithListing();

        (new RemoveProductFromFacebookCatalog(['product-'.$product->id], $this->team->id))->handle();

        Http::assertSent(function ($request) use ($product) {
            $req = $request->data()['requests'][0];

            return str_contains($request->url(), '/CAT123/items_batch')
                && $req['method'] === 'DELETE'
                && $req['data']['id'] === 'product-'.$product->id;
        });

        $this->assertSame('deleted', ProductFacebookListing::where('product_id', $product->id)->sole()->status);
    }

    public function test_removal_failure_throws_for_retry(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response('boom', 500)]);
        $product = $this->listedWithListing();

        $this->expectException(RuntimeException::class);
        (new RemoveProductFromFacebookCatalog(['product-'.$product->id], $this->team->id))->handle();
    }

    public function test_stock_hitting_zero_syncs_out_of_stock_and_keeps_item(): void
    {
        $product = $this->listedWithListing(inventory: 3);

        Queue::fake();
        $ok = $product->adjustInventory(-3, 'sold out');

        $this->assertTrue($ok);
        // Selling out is an availability flip, NOT a removal.
        Queue::assertPushed(
            SyncProductToFacebookCatalog::class,
            fn (SyncProductToFacebookCatalog $job) => $job->productId === $product->id
        );
        Queue::assertNotPushed(RemoveProductFromFacebookCatalog::class);
    }

    public function test_sold_out_push_sets_out_of_stock_availability_not_delete(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['handles' => ['h1']], 200)]);
        $product = $this->listedWithListing(inventory: 0);

        (new SyncProductToFacebookCatalog($product->id))->handle();

        Http::assertSent(function ($request) {
            $req = $request->data()['requests'][0];

            return $req['method'] === 'UPDATE'
                && $req['data']['availability'] === 'out of stock';
        });
    }

    public function test_restock_enqueues_sync(): void
    {
        $product = $this->listedWithListing(inventory: 0);

        Queue::fake();
        $product->adjustInventory(2, 'restock');

        Queue::assertPushed(SyncProductToFacebookCatalog::class);
    }
}
