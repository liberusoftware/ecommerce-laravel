<?php

namespace Tests\Feature;

use App\Jobs\SyncProductToFacebookCatalog;
use App\Models\Product;
use App\Models\ProductFacebookListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ConnectsAFacebookCatalog;
use Tests\TestCase;

class FacebookCatalogVariantTest extends TestCase
{
    use ConnectsAFacebookCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectedTeam();
    }

    /** A listed Product with two Variants, built without firing sync. */
    private function variantProduct(int $stockA = 5, int $stockB = 5): Product
    {
        $product = $this->product(['price' => 20]);

        $product->variants()->create(['sku' => 'A-'.$product->id, 'title' => 'Small', 'price' => 10, 'inventory_quantity' => $stockA, 'position' => 1]);
        $product->variants()->create(['sku' => 'B-'.$product->id, 'title' => 'Large', 'price' => 15, 'inventory_quantity' => $stockB, 'position' => 2]);

        $product->list_on_facebook = true;
        $product->saveQuietly();

        return $product->fresh();
    }

    private function runSync(Product $product): void
    {
        (new SyncProductToFacebookCatalog($product->id))->handle();
    }

    public function test_each_variant_is_its_own_item_sharing_one_item_group(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['handles' => ['h']], 200)]);
        $product = $this->variantProduct();
        [$v1, $v2] = $product->variants->all();

        $this->runSync($product);

        Http::assertSent(function ($request) use ($product, $v1, $v2) {
            $requests = $request->data()['requests'];
            $ids = array_column(array_column($requests, 'data'), 'id');
            $groups = array_column(array_column($requests, 'data'), 'item_group_id');

            return count($requests) === 2
                && in_array('variant-'.$v1->id, $ids, true)
                && in_array('variant-'.$v2->id, $ids, true)
                && $groups === ['product-'.$product->id, 'product-'.$product->id];
        });

        $this->assertSame(2, ProductFacebookListing::where('product_id', $product->id)->whereNotNull('product_variant_id')->count());
        $this->assertSame('active', ProductFacebookListing::where('product_variant_id', $v1->id)->sole()->status);
    }

    public function test_per_variant_status_is_recorded_independently(): void
    {
        $product = $this->variantProduct();
        [$v1, $v2] = $product->variants->all();

        Http::fake(['graph.facebook.com/*' => Http::response([
            'validation_status' => [
                ['retailer_id' => 'variant-'.$v2->id, 'errors' => [['message' => 'missing image']]],
            ],
        ], 200)]);

        $this->runSync($product);

        $this->assertSame('active', ProductFacebookListing::where('product_variant_id', $v1->id)->sole()->status);
        $errored = ProductFacebookListing::where('product_variant_id', $v2->id)->sole();
        $this->assertSame('error', $errored->status);
        $this->assertNotNull($errored->errors);
    }

    public function test_a_variant_change_enqueues_a_sync(): void
    {
        $product = $this->variantProduct();

        Queue::fake();
        $product->variants->first()->update(['price' => 12]);

        Queue::assertPushed(
            SyncProductToFacebookCatalog::class,
            fn (SyncProductToFacebookCatalog $job) => $job->productId === $product->id
        );
    }

    public function test_sold_out_is_per_variant(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['handles' => ['h']], 200)]);
        $product = $this->variantProduct(stockA: 0, stockB: 4);
        [$v1, $v2] = $product->variants->all();

        $this->runSync($product);

        Http::assertSent(function ($request) use ($v1, $v2) {
            $byId = [];

            foreach ($request->data()['requests'] as $r) {
                $byId[$r['data']['id']] = $r['data']['availability'];
            }

            return $byId['variant-'.$v1->id] === 'out of stock'
                && $byId['variant-'.$v2->id] === 'in stock';
        });
    }
}
