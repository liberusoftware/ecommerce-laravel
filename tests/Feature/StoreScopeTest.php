<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelDomain;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wave 1.5, step 3: a storefront serves its own store's catalogue and nobody
 * else's.
 *
 * #939, #950 and #952 are one root cause with three surfaces — the Blade
 * storefront, the GraphQL endpoint, the REST API. Each was going to be fixed at
 * its own call site, and scoping at the caller is the original failure: it has
 * to be remembered every time, and it was not. This scopes once, in the model.
 */
class StoreScopeTest extends TestCase
{
    use RefreshDatabase;

    private function storefront(string $host): Store
    {
        $store = Store::factory()->create();
        $channel = Channel::factory()->create(['store_id' => $store->id]);
        ChannelDomain::factory()->primary()->create(['channel_id' => $channel->id, 'host' => $host]);

        return $store;
    }

    public function test_a_storefront_does_not_publish_another_stores_products(): void
    {
        $mine = $this->storefront('mine.example.com');
        $theirs = $this->storefront('theirs.example.com');

        $ours = Product::factory()->create(['store_id' => $mine->id, 'name' => 'Mine For Sale']);
        $competitor = Product::factory()->create(['store_id' => $theirs->id, 'name' => 'Theirs For Sale']);

        $response = $this->get('http://mine.example.com/sitemap.xml')->assertOk();

        $response->assertSee(route('products.show', $ours, absolute: false), escape: false);
        $response->assertDontSee(route('products.show', $competitor, absolute: false), escape: false);
    }

    /**
     * The sitemap is the harm that outlives its own fix: a request-time leak
     * stops the moment it is patched, but URLs handed to a crawler stay in the
     * index until it comes back.
     */
    public function test_the_sitemap_lists_only_the_resolved_stores_products(): void
    {
        $mine = $this->storefront('mine.example.com');
        $theirs = $this->storefront('theirs.example.com');

        Product::factory()->count(2)->create(['store_id' => $mine->id]);
        Product::factory()->count(3)->create(['store_id' => $theirs->id]);

        $body = $this->get('http://mine.example.com/sitemap.xml')->assertOk()->getContent();

        // Two products plus the home page.
        $this->assertSame(3, substr_count($body, '<loc>'));
    }

    /**
     * No default-merchant fallback. An unconfigured hostname is an unscoped one,
     * which is the leak with extra steps.
     */
    public function test_an_unresolved_host_is_a_404(): void
    {
        $this->storefront('mine.example.com');

        $this->get('http://somebody-elses.example.com/sitemap.xml')->assertNotFound();
    }

    /**
     * Probes arrive on the pod's own address rather than a configured hostname,
     * and 404ing them restarts healthy pods.
     */
    public function test_the_health_probe_answers_on_an_unresolved_host(): void
    {
        $this->get('http://10.0.0.7/health')->assertOk()->assertJsonPath('status', 'ok');
    }

    /**
     * Off a resolved host the scope is inert — otherwise every console command
     * and queued job would see an empty catalogue.
     */
    public function test_the_scope_is_inert_without_a_resolved_host(): void
    {
        $mine = $this->storefront('mine.example.com');
        $theirs = $this->storefront('theirs.example.com');

        Product::factory()->create(['store_id' => $mine->id]);
        Product::factory()->create(['store_id' => $theirs->id]);

        $this->assertSame(2, Product::query()->count());
    }

    /**
     * A product created where no host is resolved — a panel, a seeder — still
     * has to appear on the storefront that sells it. With one store that is not
     * a guess, it is the whole truth.
     */
    public function test_a_row_created_off_a_storefront_is_stamped_with_the_only_store(): void
    {
        $only = Store::query()->firstOrFail();

        $product = Product::factory()->create();

        $this->assertSame($only->id, (int) $product->store_id);
    }

    public function test_a_row_is_left_unstamped_when_there_is_more_than_one_store(): void
    {
        Store::factory()->create();

        $product = Product::factory()->create(['store_id' => null]);

        $this->assertNull(
            $product->store_id,
            'A row was attributed to a store nothing pointed at, which is how default(1) started.',
        );
    }
}
