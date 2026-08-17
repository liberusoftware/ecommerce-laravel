<?php

namespace Tests\Feature;

use App\Jobs\SyncProductToFacebookCatalog;
use App\Models\Product;
use App\Models\ProductFacebookListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\Concerns\ConnectsAFacebookCatalog;
use Tests\TestCase;

class FacebookCatalogSyncTest extends TestCase
{
    use ConnectsAFacebookCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectedTeam();
    }

    private function listedProduct(array $overrides = []): Product
    {
        return $this->product(array_merge(['list_on_facebook' => true], $overrides));
    }

    private function runSync(Product $product): void
    {
        (new SyncProductToFacebookCatalog($product->id))->handle();
    }

    public function test_saving_a_listed_product_enqueues_the_sync_job(): void
    {
        Queue::fake();

        $product = $this->listedProduct();

        Queue::assertPushed(
            SyncProductToFacebookCatalog::class,
            fn (SyncProductToFacebookCatalog $job) => $job->productId === $product->id
        );
    }

    public function test_saving_an_unlisted_product_does_not_enqueue(): void
    {
        Queue::fake();

        $this->product();

        Queue::assertNotPushed(SyncProductToFacebookCatalog::class);
    }

    public function test_job_upserts_product_via_items_batch_and_marks_listing_active(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['handles' => ['h1']], 200)]);
        Queue::fake();

        $product = $this->listedProduct(['name' => 'Blue Widget']);

        $this->runSync($product);

        Http::assertSent(function ($request) use ($product) {
            $body = $request->data();

            return str_contains($request->url(), '/CAT123/items_batch')
                && $body['item_type'] === 'PRODUCT_ITEM'
                && $body['allow_upsert'] === true
                && $body['requests'][0]['method'] === 'UPDATE'
                && $body['requests'][0]['data']['id'] === 'product-'.$product->id
                && $body['requests'][0]['data']['title'] === 'Blue Widget'
                && $body['requests'][0]['data']['availability'] === 'in stock';
        });

        $listing = ProductFacebookListing::where('product_id', $product->id)->sole();
        $this->assertNull($listing->product_variant_id);
        $this->assertSame('active', $listing->status);
        $this->assertNotNull($listing->last_synced_at);
    }

    public function test_resaving_updates_the_same_listing_not_a_duplicate(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['handles' => ['h1']], 200)]);
        Queue::fake();

        $product = $this->listedProduct();

        $this->runSync($product);
        $this->runSync($product);

        $this->assertSame(1, ProductFacebookListing::where('product_id', $product->id)->count());
    }

    public function test_transient_failure_records_error_and_throws_for_retry(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response('upstream boom', 500)]);
        Queue::fake();

        $product = $this->listedProduct();

        try {
            $this->runSync($product);
            $this->fail('Expected the job to throw so the queue retries.');
        } catch (RuntimeException $e) {
            // expected
        }

        $listing = ProductFacebookListing::where('product_id', $product->id)->sole();
        $this->assertSame('error', $listing->status);
        $this->assertNotNull($listing->errors);
    }

    public function test_out_of_stock_product_maps_to_out_of_stock_availability(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['handles' => ['h1']], 200)]);
        Queue::fake();

        $product = $this->listedProduct(['inventory_count' => 0]);

        $this->runSync($product);

        Http::assertSent(fn ($request) => $request->data()['requests'][0]['data']['availability'] === 'out of stock');
    }

    public function test_price_is_sent_in_the_configured_currency(): void
    {
        config(['ecommerce.default_currency' => 'GBP']);
        Http::fake(['graph.facebook.com/*' => Http::response(['handles' => ['h1']], 200)]);
        Queue::fake();

        $product = $this->listedProduct(['price' => 12.5]);

        $this->runSync($product);

        Http::assertSent(fn ($request) => $request->data()['requests'][0]['data']['price'] === '12.50 GBP');
    }
}
