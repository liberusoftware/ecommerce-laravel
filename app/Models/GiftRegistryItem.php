<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GiftRegistryItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'registry_id',
        'product_id',
        'product_variant_id',
        'quantity_requested',
        'quantity_purchased',
        'priority',
        'notes',
    ];

    protected $casts = [
        'quantity_requested' => 'integer',
        'quantity_purchased' => 'integer',
        'priority' => 'integer',
    ];

    public function registry(): BelongsTo
    {
        return $this->belongsTo(GiftRegistry::class, 'registry_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(GiftRegistryPurchase::class, 'registry_item_id');
    }

    /**
     * Get remaining quantity needed
     */
    public function getRemainingQuantity(): int
    {
        return max(0, $this->quantity_requested - $this->quantity_purchased);
    }

    /**
     * Check if item is fully purchased
     */
    public function isFullyPurchased(): bool
    {
        return $this->quantity_purchased >= $this->quantity_requested;
    }

    /**
     * Mark quantity as purchased
     */
    public function markPurchased(int $quantity, int $orderId, ?string $purchaserName = null, ?string $purchaserEmail = null, bool $anonymous = false): GiftRegistryPurchase
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Purchase quantity must be at least 1.');
        }

        $remaining = $this->getRemainingQuantity();
        if ($quantity > $remaining) {
            throw new \InvalidArgumentException("Cannot purchase {$quantity}; only {$remaining} remaining for this item.");
        }

        // Record the purchase first: a failed insert must not bump the count.
        $purchase = $this->purchases()->create([
            'order_id' => $orderId,
            'quantity' => $quantity,
            'purchaser_name' => $purchaserName,
            'purchaser_email' => $purchaserEmail,
            'anonymous' => $anonymous,
            'purchased_at' => now(),
        ]);

        $this->increment('quantity_purchased', $quantity);

        return $purchase;
    }
}
