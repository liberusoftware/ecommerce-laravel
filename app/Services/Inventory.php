<?php

namespace App\Services;

use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The stock ledger. A simple product holds its own stock; a product with
 * variants holds none, and each variant holds its own. Every movement is a
 * guarded atomic update with an old/new InventoryLog row naming the holder.
 */
class Inventory
{
    /**
     * Add (positive) or remove (negative) stock. A removal only succeeds while
     * enough remains, so concurrent writers can neither lose an update nor take
     * the count below zero. Returns false when a removal was refused.
     */
    public function adjust(Product|ProductVariant $holder, int $delta, string $reason, ?Model $reference = null): bool
    {
        return (bool) DB::transaction(function () use ($holder, $delta, $reason, $reference) {
            if ($delta < 0) {
                $affected = $holder->newQueryWithoutScopes()
                    ->whereKey($holder->getKey())
                    ->where('inventory_count', '>=', abs($delta))
                    ->decrement('inventory_count', abs($delta));

                if ($affected === 0) {
                    return false;
                }
                // Read after the update: inside the transaction the row is ours.
                $holder->setAttribute('inventory_count', (int) $holder->newQueryWithoutScopes()->whereKey($holder->getKey())->value('inventory_count'));
                $holder->syncOriginalAttribute('inventory_count');
            } else {
                // A model increment, so back-in-stock listeners see it.
                $holder->increment('inventory_count', $delta);
            }

            $after = (int) $holder->inventory_count;

            InventoryLog::create([
                'product_id' => $holder instanceof ProductVariant ? $holder->product_id : $holder->getKey(),
                'product_variant_id' => $holder instanceof ProductVariant ? $holder->getKey() : null,
                'quantity_change' => $delta,
                'old_quantity' => $after - $delta,
                'new_quantity' => $after,
                'reason' => $reason,
                'reference_id' => $reference?->getKey(),
                'reference_type' => $reference ? $reference::class : null,
            ]);

            return true;
        });
    }

    /** The stock that supplies a line: its variant when it has one, else the product. */
    public function holderFor(int $productId, ?int $variantId): Product|ProductVariant|null
    {
        return $variantId !== null
            ? ProductVariant::withoutGlobalScopes()->withTrashed()->find($variantId)
            : Product::withoutGlobalScopes()->withTrashed()->find($productId);
    }
}
