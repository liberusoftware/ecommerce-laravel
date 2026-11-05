<?php

namespace Tests\Feature;

use App\Jobs\SyncProductToFacebookCatalog;
use App\Models\Product;
use App\Models\ProductFacebookListing;
use App\Services\ProductVariantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\BuildsVariants;
use Tests\Feature\Concerns\ConnectsAFacebookCatalog;
use Tests\TestCase;

/** A product with variants is one Catalog item per variant on sale, grouped under the product. */
class FacebookCatalogVariantTest extends TestCase
{
    use BuildsVariants;
    use ConnectsAFacebookCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->connectedTeam();
    }

    private function phone(int $blackStock = 5, int $silverStock = 5): Product
    {
        return $this->variableProduct(['Colour' => ['Black', 'Silver'], 'Storage' => ['128 GB']], [
            $this->row(['Colour' => 'Black', 'Storage' => '128 GB'], 399, $blackStock, ['sku' => 'PH-BLK']),
            $this->row(['Colour' => 'Silver', 'Storage' => '128 GB'], 419, $silverStock, ['sku' => 'PH-SLV']),
        ], ['name' => 'Pixel', 'team_id' => $this->team->id, 'list_on_facebook' => true]);
    }

    private function runSync(Product $product): void
    {
        (new SyncProductToFacebookCatalog($product->id))->handle();
    }

    /** @return array<string, array> the batch's request data, by retailer id */
    private function sent(): array
    {
        $recorded = Http::recorded()->last()[0]->data()['requests'];

        return collect($recorded)->mapWithKeys(fn ($r) => [$r['data']['id'] => $r['data'] + ['method' => $r['method']]])->all();
    }

    public function test_each_variant_is_its_own_item_in_one_group_with_its_own_price_and_stock(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['handles' => ['h']], 200)]);
        $phone = $this->phone(blackStock: 0);
        $black = $this->variantOf($phone, 'Black / 128 GB');
        $silver = $this->variantOf($phone, 'Silver / 128 GB');

        $this->runSync($phone);

        $sent = $this->sent();
        $this->assertSame(['variant-'.$black->id, 'variant-'.$silver->id], array_keys($sent));
        $this->assertSame(['product-'.$phone->id], array_values(array_unique(array_column($sent, 'item_group_id'))));
        $this->assertSame('Pixel - Silver / 128 GB', $sent['variant-'.$silver->id]['title']);
        $this->assertSame('Silver', $sent['variant-'.$silver->id]['color']);
        $this->assertStringStartsWith('419.00 ', $sent['variant-'.$silver->id]['price']);
        $this->assertSame('out of stock', $sent['variant-'.$black->id]['availability']);
        $this->assertSame('in stock', $sent['variant-'.$silver->id]['availability']);

        $this->assertSame([$black->id, $silver->id], ProductFacebookListing::where('status', 'active')->orderBy('id')->pluck('product_variant_id')->all());
    }

    public function test_an_untracked_variant_is_always_in_stock(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['handles' => ['h']], 200)]);
        $tee = $this->variableProduct(['Size' => ['M']], [
            $this->row(['Size' => 'M'], 25, 0, ['track_inventory' => false]),
        ], ['team_id' => $this->team->id, 'list_on_facebook' => true]);

        $this->runSync($tee);

        $item = array_values($this->sent())[0];
        $this->assertSame('in stock', $item['availability']);
        $this->assertSame('M', $item['size']);
    }

    public function test_per_variant_errors_are_recorded_independently(): void
    {
        $phone = $this->phone();
        $silver = $this->variantOf($phone, 'Silver / 128 GB');
        Http::fake(['graph.facebook.com/*' => Http::response([
            'validation_status' => [['retailer_id' => 'variant-'.$silver->id, 'errors' => [['message' => 'missing image']]]],
        ], 200)]);

        $this->runSync($phone);

        $this->assertSame('active', ProductFacebookListing::where('product_variant_id', $this->variantOf($phone, 'Black / 128 GB')->id)->sole()->status);
        $this->assertSame('error', ProductFacebookListing::where('product_variant_id', $silver->id)->sole()->status);
    }

    public function test_a_variant_taken_off_sale_is_deleted_from_the_catalog(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['handles' => ['h']], 200)]);
        $phone = $this->phone();
        $silver = $this->variantOf($phone, 'Silver / 128 GB');
        $this->runSync($phone);

        $rows = $phone->variants->map(fn ($v) => ['id' => $v->id, 'attribute_values' => $v->attribute_values, 'sku' => $v->sku, 'price' => $v->price, 'is_active' => $v->isNot($silver)])->all();
        app(ProductVariantService::class)->sync($phone, $phone->variant_attributes, $rows);
        $this->runSync($phone->fresh());

        $this->assertSame('DELETE', $this->sent()['variant-'.$silver->id]['method']);
        $this->assertSame('deleted', ProductFacebookListing::where('retailer_id', 'variant-'.$silver->id)->sole()->status);
    }

    public function test_a_simple_product_that_gains_variants_replaces_its_single_item(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['handles' => ['h']], 200)]);
        $product = $this->product(['list_on_facebook' => true]);
        $this->runSync($product);

        app(ProductVariantService::class)->sync($product, $this->attributes(['Size' => ['S']]), [$this->row(['Size' => 'S'], 12)]);
        $this->runSync($product->fresh());

        $sent = $this->sent();
        $this->assertSame('DELETE', $sent['product-'.$product->id]['method']);
        $this->assertSame('UPDATE', $sent['variant-'.$product->variants()->sole()->id]['method']);
        $this->assertSame('deleted', ProductFacebookListing::where('retailer_id', 'product-'.$product->id)->sole()->status);
    }

    public function test_a_variant_edit_queues_a_sync(): void
    {
        $phone = $this->phone();
        Queue::fake();

        $rows = $phone->variants->map(fn ($v) => ['id' => $v->id, 'attribute_values' => $v->attribute_values, 'sku' => $v->sku, 'price' => $v->price + 10])->all();
        app(ProductVariantService::class)->sync($phone, $phone->variant_attributes, $rows);

        Queue::assertPushed(SyncProductToFacebookCatalog::class, fn ($job) => $job->productId === $phone->id);
    }
}
