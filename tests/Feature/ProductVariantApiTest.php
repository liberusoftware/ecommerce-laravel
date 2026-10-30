<?php

namespace Tests\Feature;

use App\Interfaces\PaymentGatewayInterface;
use App\Models\Product;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use App\Services\PaymentGateways\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsVariants;
use Tests\TestCase;

/** REST and GraphQL describe a product's variants alike, and the REST write path takes them through the shared service. */
class ProductVariantApiTest extends TestCase
{
    use BuildsVariants;
    use RefreshDatabase;

    private Product $laptop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->laptop = $this->variableProduct(['RAM' => ['16 GB', '32 GB'], 'Storage' => ['512 GB']], [
            $this->row(['RAM' => '16 GB', 'Storage' => '512 GB'], 1199, 4, ['sku' => 'LT-16']),
            $this->row(['RAM' => '32 GB', 'Storage' => '512 GB'], 1499, 0, ['sku' => 'LT-32']),
        ], ['name' => 'Workbook', 'slug' => 'workbook']);
    }

    private function staff(): User
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $owner->id]);
        Store::query()->update(['team_id' => $team->id]);
        Product::query()->update(['team_id' => $team->id]);
        Sanctum::actingAs($owner);

        return $owner;
    }

    public function test_rest_and_graphql_describe_the_same_variants_and_price_range(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $rest = $this->getJson('/api/products/workbook')->assertOk()->json('data');
        $gql = $this->postJson('/api/graphql', ['query' => '{ product(slug: "workbook") {
            hasVariants price priceMin priceMax inventoryCount inStock
            variantAttributes { name values }
            variants { id sku label price isAvailable inStock inventoryCount attributes { name value } }
        } }'])->json('data.product');

        $this->assertTrue($rest['has_variants']);
        $this->assertTrue($gql['hasVariants']);
        $this->assertEquals(['min' => 1199, 'max' => 1499], $rest['price_range']);
        $this->assertEquals([1199, 1199, 1499], [$gql['price'], $gql['priceMin'], $gql['priceMax']]);
        $this->assertNull($gql['inventoryCount']);
        $this->assertTrue($gql['inStock']);
        $this->assertSame($rest['variant_attributes'], array_map(fn ($a) => ['name' => $a['name'], 'values' => $a['values']], $gql['variantAttributes']));

        $this->assertSame(array_column($rest['variants'], 'id'), array_column($gql['variants'], 'id'));
        $this->assertSame(array_column($rest['variants'], 'sku'), array_column($gql['variants'], 'sku'));
        $this->assertEquals(array_column($rest['variants'], 'price'), array_column($gql['variants'], 'price'));
        $this->assertSame(array_column($rest['variants'], 'attribute_values'), array_column($gql['variants'], 'attributes'));
        $this->assertSame(['16 GB / 512 GB', '32 GB / 512 GB'], array_column($gql['variants'], 'label'));
        $this->assertSame([true, false], array_column($gql['variants'], 'inStock'));
        $this->assertSame([4, 0], array_column($gql['variants'], 'inventoryCount'));
    }

    public function test_listings_carry_the_price_range_on_both_apis(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Product::factory()->create(['name' => 'Sleeve', 'price' => 30]);

        $rest = collect($this->getJson('/api/products?sort_by=name&sort_order=asc')->json('data'));
        $gql = collect($this->postJson('/api/graphql', ['query' => '{ products { data { name hasVariants priceMin priceMax } } }'])->json('data.products.data'));

        $this->assertEquals(['min' => 30, 'max' => 30], $rest->firstWhere('name', 'Sleeve')['price_range']);
        $this->assertEquals(['min' => 1199, 'max' => 1499], $rest->firstWhere('name', 'Workbook')['price_range']);
        $this->assertEquals(['name' => 'Workbook', 'hasVariants' => true, 'priceMin' => 1199, 'priceMax' => 1499], $gql->firstWhere('name', 'Workbook'));
        $this->assertFalse($gql->firstWhere('name', 'Sleeve')['hasVariants']);
    }

    public function test_rest_creates_a_product_with_variants(): void
    {
        $this->staff();

        $response = $this->postJson('/api/products', [
            'name' => 'Desk Lamp',
            'short_description' => 'A lamp.',
            'category_id' => $this->laptop->category_id,
            'variant_attributes' => [['name' => 'Finish', 'values' => ['Brass', 'Black']]],
            'variants' => [
                ['attribute_values' => [['name' => 'Finish', 'value' => 'Brass']], 'price' => 89, 'inventory_count' => 3, 'sku' => 'LAMP-BR'],
                ['attribute_values' => [['name' => 'Finish', 'value' => 'Black']], 'price' => 79, 'inventory_count' => 6, 'sku' => 'LAMP-BK'],
            ],
        ])->assertCreated();

        $this->assertEquals(['min' => 79, 'max' => 89], $response->json('data.price_range'));
        $lamp = Product::where('name', 'Desk Lamp')->sole();
        $this->assertSame(['LAMP-BR', 'LAMP-BK'], $lamp->variants()->pluck('sku')->all());
        $this->assertSame([3, 6], $lamp->variants()->pluck('inventory_count')->all());
        $this->assertEquals(79, $lamp->price);
    }

    public function test_rest_refuses_invalid_variants_and_writes_nothing(): void
    {
        $this->staff();

        $this->postJson('/api/products', [
            'name' => 'Desk Lamp',
            'short_description' => 'A lamp.',
            'category_id' => $this->laptop->category_id,
            'variant_attributes' => [['name' => 'Finish', 'values' => ['Brass']]],
            'variants' => [
                ['attribute_values' => [['name' => 'Finish', 'value' => 'Brass']], 'price' => 89, 'sku' => 'LT-16'],
            ],
        ])->assertStatus(422)->assertJsonPath('errors', ['variants.0.sku' => ['Another product or variant in this store already has this SKU.']]);

        $this->assertSame(0, Product::where('name', 'Desk Lamp')->count());
    }

    public function test_rest_updates_keep_variant_stock_and_ignore_product_level_price(): void
    {
        $this->staff();
        $sixteen = $this->variantOf($this->laptop, '16 GB / 512 GB');

        $this->putJson("/api/products/{$this->laptop->id}", ['price' => 1, 'inventory_count' => 999])->assertOk();
        $this->assertEquals(1199, $this->laptop->fresh()->price);

        $this->putJson("/api/products/{$this->laptop->id}", [
            'variants' => [
                ['id' => $sixteen->id, 'attribute_values' => $sixteen->attribute_values, 'price' => 1099, 'inventory_count' => 500, 'sku' => 'LT-16'],
            ],
        ])->assertOk()->assertJsonPath('data.price_range.min', 1099);

        $this->assertSame(4, $sixteen->fresh()->inventory_count);
        $this->assertSame(['16 GB / 512 GB'], $this->laptop->variants()->get()->map->label()->all());
        $this->assertSoftDeleted('product_variants', ['sku' => 'LT-32']);
    }

    public function test_rest_and_graphql_order_lines_carry_the_same_snapshot(): void
    {
        Sanctum::actingAs($buyer = User::factory()->create());
        $this->laptop->update(['is_downloadable' => true]);
        $variantId = $this->variantOf($this->laptop, '16 GB / 512 GB')->id;
        $this->postJson('/api/cart', ['product_id' => $this->laptop->id, 'variant_id' => $variantId, 'quantity' => 1])->assertCreated();
        $this->app->instance(StripeGateway::class, new class implements PaymentGatewayInterface
        {
            public function processPayment(float $amount, array $paymentDetails): array
            {
                return ['success' => true, 'transaction_id' => 'ch'];
            }

            public function processSubscription(string $planId, array $subscriptionDetails): array
            {
                return ['success' => true];
            }

            public function refundPayment(string $transactionId, float $amount): array
            {
                return ['success' => true];
            }
        });
        $this->postJson('/api/graphql', ['query' => 'mutation { checkout(input: {country: "US", stripeToken: "tok"}) { id } }'])->assertJsonMissingPath('errors');

        $rest = $this->getJson('/api/orders')->assertOk()->json('data.0.items.0');
        $gql = $this->postJson('/api/graphql', ['query' => '{ orders { items { variantId sku variantAttributes { name value } price } } }'])->json('data.orders.0.items.0');

        $this->assertSame([$variantId, 'LT-16'], [$rest['product_variant_id'], $rest['sku']]);
        $this->assertSame([$variantId, 'LT-16'], [$gql['variantId'], $gql['sku']]);
        $this->assertSame($rest['variant_attributes'], $gql['variantAttributes']);
        $this->assertEquals((float) $rest['price'], $gql['price']);
    }
}
