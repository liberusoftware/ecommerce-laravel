<?php

namespace App\Console\Commands;

use App\Models\FacebookConnection;
use App\Models\ProductFacebookListing;
use Illuminate\Console\Command;

/**
 * Re-reads Meta-owned Catalog status into local Listings, per connected Team,
 * so the merchant sees rejections and stock drift Meta applied after upload.
 */
class ReconcileFacebookCatalog extends Command
{
    protected $signature = 'facebook:reconcile-catalog';

    protected $description = 'Re-read Meta Catalog item status into local Listings (drift reconciliation).';

    public function handle(): int
    {
        $updated = 0;

        foreach (FacebookConnection::all() as $connection) {
            $updated += $this->reconcile($connection);
        }

        $this->info("Reconciled {$updated} listing(s).");

        return self::SUCCESS;
    }

    private function reconcile(FacebookConnection $connection): int
    {
        $listings = ProductFacebookListing::query()
            ->where('status', '!=', 'deleted')
            ->whereHas('product', fn ($query) => $query->where('team_id', $connection->team_id))
            ->get();

        if ($listings->isEmpty()) {
            return 0;
        }

        $statuses = $connection->catalog()->readItemStatuses($listings->pluck('retailer_id')->all());
        $updated = 0;

        foreach ($listings as $listing) {
            $item = $statuses[$listing->retailer_id] ?? null;

            if ($item === null) {
                continue;
            }

            $listing->update($this->mapStatus($item));
            $updated++;
        }

        return $updated;
    }

    /** Translate Meta's review/availability into a local Listing status. */
    private function mapStatus(array $item): array
    {
        $review = strtolower($item['review_status'] ?? '');
        $availability = strtolower($item['availability'] ?? '');
        $errors = $item['errors'] ?? null;

        $status = match (true) {
            in_array($review, ['rejected', 'disapproved'], true) => 'error',
            $availability === 'out of stock' => 'out_of_stock',
            in_array($review, ['approved', 'active'], true) => 'active',
            default => 'pending',
        };

        return [
            'status' => $status,
            'errors' => $errors ?: null,
            'catalog_item_id' => $item['id'] ?? null,
            'last_synced_at' => now(),
        ];
    }
}
