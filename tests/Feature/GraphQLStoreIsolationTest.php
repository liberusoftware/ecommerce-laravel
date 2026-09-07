<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelDomain;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #950: the anonymous GraphQL endpoint served every merchant's catalogue.
 *
 * The scope that fixes it lives on the models, not here — but the models are not
 * where the issue was reported, and a scope nobody exercises through the actual
 * surface is a scope that gets removed by the next refactor. These drive
 * `/api/graphql` the way a caller does, on a real `Host`, with no token.
 *
 * The nested read matters most: `collections { products }` reaches Product
 * through a pivot rather than through the `products` query, so it is the path a
 * caller-side fix would have missed.
 */
class GraphQLStoreIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function storefront(string $host): Store
    {
        $store = Store::factory()->create();
        $channel = Channel::factory()->create(['store_id' => $store->id]);
        ChannelDomain::factory()->primary()->create(['channel_id' => $channel->id, 'host' => $host]);

        return $store;
    }

    private function gql(string $host, string $query): array
    {
        return $this->postJson("http://{$host}/api/graphql", ['query' => $query])->json();
    }

    private function collectionOf(Store $store, string $name, Product ...$products): ProductCollection
    {
        $collection = ProductCollection::factory()->create(['store_id' => $store->id, 'name' => $name]);

        // attach() writes the pivot without reading the related model, which is
        // what lets the mis-stamped-pivot case below be set up at all.
        $collection->products()->attach(collect($products)->pluck('id')->all(), ['quantity' => 1]);

        return $collection;
    }

    public function test_the_products_query_returns_only_the_resolved_stores_catalogue(): void
    {
        $mine = $this->storefront('mine.example.com');
        $theirs = $this->storefront('theirs.example.com');

        Product::factory()->create(['store_id' => $mine->id, 'name' => 'Mine For Sale']);
        Product::factory()->create(['store_id' => $theirs->id, 'name' => 'Theirs For Sale']);

        $data = $this->gql('mine.example.com', '{ products { data { name } total } }')['data']['products'];

        $this->assertSame(['Mine For Sale'], array_column($data['data'], 'name'));
        $this->assertSame(1, $data['total']);
    }

    /**
     * `search` is the field an attacker reaches for: it is the difference between
     * having to page through a competitor's catalogue and asking for a product by
     * name. The scope has to apply before the filter, not instead of it.
     */
    public function test_a_search_cannot_reach_across_stores(): void
    {
        $mine = $this->storefront('mine.example.com');
        $theirs = $this->storefront('theirs.example.com');

        Product::factory()->create(['store_id' => $mine->id, 'name' => 'Widget Blue']);
        Product::factory()->create(['store_id' => $theirs->id, 'name' => 'Widget Red']);

        $data = $this->gql('mine.example.com', '{ products(search: "Widget") { data { name } total } }')['data']['products'];

        $this->assertSame(['Widget Blue'], array_column($data['data'], 'name'));
    }

    /**
     * A known id is the other half of the same reach: the listing hiding a row
     * means nothing if fetching it by id still works.
     */
    public function test_a_product_from_another_store_is_not_readable_by_id(): void
    {
        $this->storefront('mine.example.com');
        $theirs = $this->storefront('theirs.example.com');

        $competitor = Product::factory()->create(['store_id' => $theirs->id]);

        $data = $this->gql('mine.example.com', '{ product(id: '.$competitor->id.') { id name } }');

        $this->assertNull($data['data']['product']);
    }

    public function test_collections_and_their_nested_products_are_scoped(): void
    {
        $mine = $this->storefront('mine.example.com');
        $theirs = $this->storefront('theirs.example.com');

        $ours = Product::factory()->create(['store_id' => $mine->id, 'name' => 'Mine For Sale']);
        $competitor = Product::factory()->create(['store_id' => $theirs->id, 'name' => 'Theirs For Sale']);

        $this->collectionOf($mine, 'Mine Featured', $ours);
        $this->collectionOf($theirs, 'Theirs Featured', $competitor);

        $collections = $this->gql(
            'mine.example.com',
            '{ collections { name products { name } } }',
        )['data']['collections'];

        $this->assertSame(['Mine Featured'], array_column($collections, 'name'));
        $this->assertSame(['Mine For Sale'], array_column($collections[0]['products'], 'name'));
    }

    /**
     * The pivot is not a way around the scope. A row in `collection_items`
     * pointing at another store's product is a mis-stamped row, not permission.
     */
    public function test_a_pivot_row_pointing_at_another_stores_product_does_not_expose_it(): void
    {
        $mine = $this->storefront('mine.example.com');
        $theirs = $this->storefront('theirs.example.com');

        $competitor = Product::factory()->create(['store_id' => $theirs->id, 'name' => 'Theirs For Sale']);
        $this->collectionOf($mine, 'Mine Featured', $competitor);

        $collections = $this->gql(
            'mine.example.com',
            '{ collections { name products { name } } }',
        )['data']['collections'];

        $this->assertSame([], $collections[0]['products']);
    }

    /**
     * An unconfigured hostname resolves to no store, and a request that cannot be
     * scoped is refused rather than served unscoped.
     */
    public function test_an_unresolved_host_gets_no_catalogue_at_all(): void
    {
        $mine = $this->storefront('mine.example.com');
        Product::factory()->create(['store_id' => $mine->id]);

        $this->postJson('http://somebody-elses.example.com/api/graphql', [
            'query' => '{ products { total } }',
        ])->assertNotFound();
    }

    /**
     * `collections { products }` is depth 3 and inside the complexity limit, so
     * neither rule bounded it — it returned the whole catalogue once per
     * collection, in one query per collection.
     */
    public function test_the_nested_collection_read_is_bounded_and_not_an_n_plus_1(): void
    {
        $mine = $this->storefront('mine.example.com');

        $products = Product::factory()->count(3)->create(['store_id' => $mine->id])->all();
        foreach (range(1, 3) as $i) {
            $this->collectionOf($mine, "Collection {$i}", ...$products);
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $collections = $this->gql('mine.example.com', '{ collections { name products { name } } }')['data']['collections'];

        $this->assertCount(3, $collections);
        $this->assertCount(3, $collections[0]['products']);

        // One for the collections, one for all their products. Per-collection
        // loading would make this grow with the number of collections.
        $this->assertLessThan(
            10,
            $queries,
            'The nested read is issuing a query per collection again.',
        );
    }
}
