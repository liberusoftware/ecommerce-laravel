<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelDomain;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wave 1.5, step 4: one sitemap per resolved storefront.
 *
 * *Which* products it lists is settled by the store scope, and covered by
 * `StoreScopeTest`. What is left is the two questions scoping does not answer:
 * which hostname the URLs are written on, and how many of them there are.
 */
class SitemapCanonicalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A storefront answering on several hostnames, the first one flagged
     * primary — the apex, `www`, a custom domain and a platform subdomain is the
     * realistic shape from day one, not an edge case.
     */
    private function storefront(string $primary, string ...$aliases): Store
    {
        $store = Store::factory()->create();
        $channel = Channel::factory()->create(['store_id' => $store->id]);

        ChannelDomain::factory()->primary()->create(['channel_id' => $channel->id, 'host' => $primary]);

        foreach ($aliases as $alias) {
            ChannelDomain::factory()->create(['channel_id' => $channel->id, 'host' => $alias]);
        }

        return $store;
    }

    /**
     * @return array<int, string>
     */
    private function locations(string $url): array
    {
        $body = $this->get($url)->assertOk()->getContent();

        preg_match_all('#<loc>(.*?)</loc>#', $body, $matches);

        return $matches[1];
    }

    /**
     * The canonical is the primary hostname, not the one the crawler used.
     *
     * Otherwise two hostnames publish two sitemaps naming the same pages by
     * different absolute URLs — duplicate content, announced to the crawler in
     * the one file whose whole job is telling it what to index.
     */
    public function test_urls_are_written_on_the_primary_hostname_not_the_one_requested(): void
    {
        $store = $this->storefront('shop.example.com', 'www.example.com');

        Product::factory()->create(['store_id' => $store->id]);

        $locations = $this->locations('http://www.example.com/sitemap.xml');

        $this->assertNotEmpty($locations);

        foreach ($locations as $location) {
            $this->assertStringStartsWith('http://shop.example.com/', $location);
        }
    }

    /**
     * The same sitemap fetched on the primary hostname is the same sitemap. A
     * canonical that changes with the request is not a canonical.
     */
    public function test_the_sitemap_is_the_same_on_every_hostname_the_storefront_answers_on(): void
    {
        $store = $this->storefront('shop.example.com', 'www.example.com');

        Product::factory()->count(3)->create(['store_id' => $store->id]);

        $this->assertSame(
            $this->locations('http://shop.example.com/sitemap.xml'),
            $this->locations('http://www.example.com/sitemap.xml'),
        );
    }

    public function test_a_products_url_and_last_modified_date_are_listed(): void
    {
        $store = $this->storefront('shop.example.com');

        $product = Product::factory()->create(['store_id' => $store->id]);

        $this->get('http://shop.example.com/sitemap.xml')
            ->assertOk()
            ->assertSee('http://shop.example.com'.route('products.show', $product, absolute: false), escape: false)
            ->assertSee('<lastmod>'.$product->updated_at->toAtomString().'</lastmod>', escape: false);
    }

    public function test_a_categorys_listing_url_is_included(): void
    {
        $store = $this->storefront('shop.example.com');

        $category = ProductCategory::factory()->create(['store_id' => $store->id, 'slug' => 'mugs & cups']);

        $this->assertContains(
            'http://shop.example.com/products?category='.urlencode($category->slug),
            $this->locations('http://shop.example.com/sitemap.xml'),
        );
    }

    /**
     * The sitemap protocol's ceiling is 50,000 URLs per file, and a crawler is
     * entitled to ignore a file that exceeds it. An unbounded `Product::all()`
     * does not merely render slowly at 60,000 products — it publishes a sitemap
     * that need not be read at all.
     */
    public function test_the_url_count_is_capped(): void
    {
        $store = $this->storefront('shop.example.com');

        Product::factory()->count(5)->create(['store_id' => $store->id]);

        config(['sitemap.max_urls' => 3]);

        $this->assertCount(3, $this->locations('http://shop.example.com/sitemap.xml'));
    }

    /**
     * The home page is the one URL the cap may never spend on something else: a
     * sitemap that omits the site is worse than no sitemap.
     */
    public function test_the_home_page_survives_the_cap(): void
    {
        $store = $this->storefront('shop.example.com');

        Product::factory()->count(5)->create(['store_id' => $store->id]);

        config(['sitemap.max_urls' => 1]);

        $this->assertSame(
            ['http://shop.example.com/'],
            $this->locations('http://shop.example.com/sitemap.xml'),
        );
    }
}
