<?php

namespace Tests\Feature;

use App\Jobs\SyncProductToFacebookCatalog;
use App\Models\Product;
use App\Models\ProductFacebookListing;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ConnectsAFacebookCatalog;
use Tests\TestCase;

/**
 * The connection is per Team. A product reaches its own merchant's Catalog or
 * none — never the platform's, and never another merchant's.
 */
class FacebookCatalogTenancyTest extends TestCase
{
    use ConnectsAFacebookCatalog;
    use RefreshDatabase;

    public function test_a_team_without_a_connection_syncs_nothing(): void
    {
        Http::fake();
        Queue::fake();

        // Another merchant is connected; this product's team is not.
        $this->connectTeam('SOMEONE-ELSE');
        $this->team = Team::factory()->create();

        $product = $this->product(['list_on_facebook' => true]);

        (new SyncProductToFacebookCatalog($product->id))->handle();

        Http::assertNothingSent();
        $this->assertSame(0, ProductFacebookListing::where('product_id', $product->id)->count());
    }

    public function test_each_team_pushes_into_its_own_catalog(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['handles' => ['h']], 200)]);
        Queue::fake();

        $first = $this->connectTeam('CAT-ONE');
        $second = $this->connectTeam('CAT-TWO');

        $this->team = $first->team;
        $productOne = $this->product(['list_on_facebook' => true]);

        $this->team = $second->team;
        $productTwo = $this->product(['list_on_facebook' => true]);

        (new SyncProductToFacebookCatalog($productOne->id))->handle();
        (new SyncProductToFacebookCatalog($productTwo->id))->handle();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/CAT-ONE/items_batch')
            && $request->data()['requests'][0]['data']['id'] === 'product-'.$productOne->id);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/CAT-TWO/items_batch')
            && $request->data()['requests'][0]['data']['id'] === 'product-'.$productTwo->id);

        $this->assertSame(1, ProductFacebookListing::where('product_id', $productOne->id)->count());
        $this->assertSame(1, ProductFacebookListing::where('product_id', $productTwo->id)->count());
    }

    public function test_an_unattributed_product_syncs_nothing(): void
    {
        Http::fake();
        Queue::fake();

        $this->connectTeam();

        // team_id null is the state IsTenantModel leaves a row nothing could
        // attribute. It belongs to no merchant, so it reaches no catalogue.
        $product = Product::factory()->create(['list_on_facebook' => true, 'team_id' => null]);

        (new SyncProductToFacebookCatalog($product->id))->handle();

        Http::assertNothingSent();
        $this->assertSame(0, ProductFacebookListing::where('product_id', $product->id)->count());
    }

    public function test_the_token_is_encrypted_at_rest(): void
    {
        $connection = $this->connectTeam();

        $stored = DB::table('facebook_connections')
            ->where('id', $connection->id)
            ->value('access_token');

        $this->assertNotSame('test-token', $stored);
        $this->assertSame('test-token', $connection->fresh()->access_token);
    }
}
