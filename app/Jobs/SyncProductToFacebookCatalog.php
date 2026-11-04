<?php

namespace App\Jobs;

use App\Models\FacebookConnection;
use App\Models\Product;
use App\Models\ProductFacebookListing;
use App\Models\ProductVariant;
use App\Services\Facebook\CatalogItemMapper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Upserts a listed Product into its Team's Meta Catalog. Idempotent — keyed by
 * a stable retailer id, so a re-run updates rather than duplicates. Transient
 * failures throw so the queue retries with backoff.
 */
class SyncProductToFacebookCatalog implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public int $productId) {}

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(): void
    {
        $product = Product::find($this->productId);

        if (! $product || ! $product->list_on_facebook || ! $product->isActive()) {
            return;
        }

        $connection = FacebookConnection::forTeam($product->team_id);

        if ($connection === null) {
            return;
        }

        [$requests, $listings, $removed] = $this->request($product);

        $result = $connection->catalog()->itemsBatch($requests);

        if (! $result['ok']) {
            $message = $result['body']['error']['message'] ?? ('HTTP '.$result['status']);

            foreach ([...array_values($listings), ...$removed] as $listing) {
                $listing->update(['status' => 'error', 'errors' => ['message' => $message]]);
            }

            throw new RuntimeException('items_batch failed: '.$message);
        }

        foreach ($listings as $retailerId => $listing) {
            $errors = $this->validationErrors($retailerId, $result['body']);

            $listing->update([
                'status' => $errors ? 'error' : 'active',
                'errors' => $errors ?: null,
                'last_synced_at' => now(),
            ]);
        }

        foreach ($removed as $listing) {
            $listing->update(['status' => 'deleted', 'errors' => null, 'last_synced_at' => now()]);
        }
    }

    /**
     * A product with variants is one item per variant on sale; a simple product is one item.
     * Items the product no longer offers are deleted from the Catalog in the same batch.
     *
     * @return array{0: array<int, array>, 1: array<string, ProductFacebookListing>, 2: list<ProductFacebookListing>}
     */
    private function request(Product $product): array
    {
        $requests = [];
        $listings = [];
        $removed = [];

        $items = $product->hasVariants()
            ? $product->variants->filter->is_active->map(fn (ProductVariant $variant) => [CatalogItemMapper::retailerIdForVariant($variant), $variant])
            : collect([[CatalogItemMapper::retailerIdForProduct($product), null]]);

        foreach ($items as [$retailerId, $variant]) {
            $listing = $product->facebookListings()->updateOrCreate(
                ['retailer_id' => $retailerId],
                ['product_variant_id' => $variant?->id, 'status' => 'pending'],
            );

            $requests[] = $variant
                ? CatalogItemMapper::forVariant($product, $variant, $retailerId)
                : CatalogItemMapper::forProduct($product, $retailerId);
            $listings[$retailerId] = $listing;
        }

        $stale = $product->facebookListings()
            ->whereNotIn('retailer_id', array_keys($listings))
            ->where('status', '!=', 'deleted')
            ->get();

        foreach ($stale as $listing) {
            $requests[] = CatalogItemMapper::deleteRequest($listing->retailer_id);
            $removed[] = $listing;
        }

        return [$requests, $listings, $removed];
    }

    /** Per-item validation errors Meta returns inline for our retailer id. */
    private function validationErrors(string $retailerId, array $body): array
    {
        return collect($body['validation_status'] ?? [])
            ->firstWhere('retailer_id', $retailerId)['errors'] ?? [];
    }
}
