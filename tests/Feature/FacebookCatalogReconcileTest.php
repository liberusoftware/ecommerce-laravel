<?php

namespace Tests\Feature;

use App\Jobs\SyncProductToFacebookCatalog;
use App\Models\ProductFacebookListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\ConnectsAFacebookCatalog;
use Tests\TestCase;

class FacebookCatalogReconcileTest extends TestCase
{
    use ConnectsAFacebookCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectedTeam();
    }

    private function listing(string $status = 'active'): ProductFacebookListing
    {
        $product = $this->product();

        return $product->facebookListings()->create([
            'retailer_id' => 'product-'.$product->id,
            'status' => $status,
        ]);
    }

    private function fakeProducts(array $items): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => $items], 200)]);
    }

    public function test_reconcile_marks_rejection_from_meta(): void
    {
        $listing = $this->listing('active');
        $this->fakeProducts([[
            'id' => '99887766',
            'retailer_id' => $listing->retailer_id,
            'review_status' => 'rejected',
            'errors' => [['message' => 'Image missing']],
        ]]);

        $this->artisan('facebook:reconcile-catalog')->assertSuccessful();

        $listing->refresh();
        $this->assertSame('error', $listing->status);
        $this->assertSame('99887766', $listing->catalog_item_id);
        $this->assertNotNull($listing->errors);
    }

    public function test_reconcile_marks_out_of_stock_drift(): void
    {
        $listing = $this->listing('active');
        $this->fakeProducts([[
            'id' => '1',
            'retailer_id' => $listing->retailer_id,
            'review_status' => 'approved',
            'availability' => 'out of stock',
        ]]);

        $this->artisan('facebook:reconcile-catalog')->assertSuccessful();

        $this->assertSame('out_of_stock', $listing->refresh()->status);
    }

    public function test_reconcile_marks_approved_active(): void
    {
        $listing = $this->listing('pending');
        $this->fakeProducts([[
            'id' => '1',
            'retailer_id' => $listing->retailer_id,
            'review_status' => 'approved',
            'availability' => 'in stock',
        ]]);

        $this->artisan('facebook:reconcile-catalog')->assertSuccessful();

        $this->assertSame('active', $listing->refresh()->status);
    }

    public function test_reconcile_ignores_deleted_listings(): void
    {
        $listing = $this->listing('deleted');
        $this->fakeProducts([[
            'id' => '1',
            'retailer_id' => $listing->retailer_id,
            'review_status' => 'approved',
        ]]);

        $this->artisan('facebook:reconcile-catalog')->assertSuccessful();

        $this->assertSame('deleted', $listing->refresh()->status);
    }

    public function test_reconcile_reads_each_teams_own_catalog(): void
    {
        $mine = $this->listing('pending');

        $other = $this->connectTeam('CAT-OTHER');
        $this->team = $other->team;
        $theirs = $this->listing('pending');

        Http::fake([
            'graph.facebook.com/*/CAT123/products*' => Http::response(['data' => [
                ['id' => 'A', 'retailer_id' => $mine->retailer_id, 'review_status' => 'approved'],
            ]], 200),
            'graph.facebook.com/*/CAT-OTHER/products*' => Http::response(['data' => [
                ['id' => 'B', 'retailer_id' => $theirs->retailer_id, 'review_status' => 'rejected'],
            ]], 200),
        ]);

        $this->artisan('facebook:reconcile-catalog')->assertSuccessful();

        $this->assertSame('active', $mine->refresh()->status);
        $this->assertSame('error', $theirs->refresh()->status);
    }

    public function test_repush_clears_errors_on_success(): void
    {
        $product = $this->product(['inventory_count' => 3]);
        $listing = $product->facebookListings()->create([
            'retailer_id' => 'product-'.$product->id,
            'status' => 'error',
            'errors' => ['message' => 'Image missing'],
        ]);
        $product->list_on_facebook = true;
        $product->saveQuietly();

        Http::fake(['graph.facebook.com/*' => Http::response(['handles' => ['h']], 200)]);

        (new SyncProductToFacebookCatalog($product->id))->handle();

        $listing->refresh();
        $this->assertSame('active', $listing->status);
        $this->assertNull($listing->errors);
    }
}
