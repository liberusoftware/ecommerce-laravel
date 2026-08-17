<?php

namespace App\Jobs;

use App\Models\FacebookConnection;
use App\Models\ProductFacebookListing;
use App\Services\Facebook\CatalogItemMapper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Removes Catalog items when a Product is unlisted or deleted — a real DELETE,
 * unlike the sold-out path, which keeps the item and flips availability. Takes
 * retailer ids and a team so it still works after the Product row is gone.
 */
class RemoveProductFromFacebookCatalog implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @param  array<int, string>  $retailerIds */
    public function __construct(public array $retailerIds, public int $teamId) {}

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(): void
    {
        if ($this->retailerIds === []) {
            return;
        }

        $connection = FacebookConnection::forTeam($this->teamId);

        if ($connection === null) {
            return;
        }

        $requests = array_map(
            fn (string $retailerId) => CatalogItemMapper::deleteRequest($retailerId),
            $this->retailerIds,
        );

        $result = $connection->catalog()->itemsBatch($requests);

        // retailer_id is unique across the table, so the ids alone are the scope.
        $listings = ProductFacebookListing::query()
            ->whereIn('retailer_id', $this->retailerIds)
            ->get();

        if (! $result['ok']) {
            $message = $result['body']['error']['message'] ?? ('HTTP '.$result['status']);

            foreach ($listings as $listing) {
                $listing->update(['status' => 'error', 'errors' => ['message' => $message]]);
            }

            throw new RuntimeException('items_batch delete failed: '.$message);
        }

        foreach ($listings as $listing) {
            $listing->update([
                'status' => 'deleted',
                'errors' => null,
                'last_synced_at' => now(),
            ]);
        }
    }
}
