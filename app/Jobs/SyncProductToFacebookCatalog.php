<?php

namespace App\Jobs;

use App\Models\FacebookConnection;
use App\Models\Product;
use App\Models\ProductFacebookListing;
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

        if (! $product || ! $product->list_on_facebook) {
            return;
        }

        $connection = FacebookConnection::forTeam($product->team_id);

        if ($connection === null) {
            return;
        }

        [$requests, $listings] = $product->hasVariants()
            ? $this->variantRequests($product)
            : $this->simpleRequest($product);

        $result = $connection->catalog()->itemsBatch($requests);

        if (! $result['ok']) {
            $message = $result['body']['error']['message'] ?? ('HTTP '.$result['status']);

            foreach ($listings as $listing) {
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
    }

    /** @return array{0: array<int, array>, 1: array<string, ProductFacebookListing>} */
    private function simpleRequest(Product $product): array
    {
        $listing = $product->facebookListings()->firstOrCreate(
            ['product_variant_id' => null],
            [
                'retailer_id' => CatalogItemMapper::retailerIdForProduct($product),
                'status' => 'pending',
            ],
        );

        return [
            [CatalogItemMapper::forProduct($product, $listing->retailer_id)],
            [$listing->retailer_id => $listing],
        ];
    }

    /** @return array{0: array<int, array>, 1: array<string, ProductFacebookListing>} */
    private function variantRequests(Product $product): array
    {
        $groupId = CatalogItemMapper::itemGroupId($product);
        $requests = [];
        $listings = [];

        foreach ($product->variants as $variant) {
            $listing = $product->facebookListings()->firstOrCreate(
                ['product_variant_id' => $variant->id],
                [
                    'retailer_id' => CatalogItemMapper::retailerIdForVariant($variant),
                    'status' => 'pending',
                ],
            );

            $requests[] = CatalogItemMapper::forVariant($product, $variant, $listing->retailer_id, $groupId);
            $listings[$listing->retailer_id] = $listing;
        }

        return [$requests, $listings];
    }

    /** Per-item validation errors Meta returns inline for our retailer id. */
    private function validationErrors(string $retailerId, array $body): array
    {
        return collect($body['validation_status'] ?? [])
            ->firstWhere('retailer_id', $retailerId)['errors'] ?? [];
    }
}
