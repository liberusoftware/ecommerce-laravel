<?php

namespace Liberu\Ecommerce\ProductCatalog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Preorder extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'order_item_id',
        'product_id',
        'product_variant_id',
        'quantity',
        'expected_release_date',
        'status',
        'customer_notified',
        'released_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'expected_release_date' => 'datetime',
        'customer_notified' => 'boolean',
        'released_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Check if pre-order is available
     */
    public function isAvailable(): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        $product = $this->product;

        // Check if pre-order period is active
        if ($product->preorder_available_from && now()->lt($product->preorder_available_from)) {
            return false;
        }

        if ($product->preorder_available_until && now()->gt($product->preorder_available_until)) {
            return false;
        }

        return true;
    }

    /**
     * Release the pre-order
     */
    public function release(): void
    {
        $this->update([
            'status' => 'released',
            'released_at' => now(),
        ]);

        // Add to inventory if needed
        if ($this->variant) {
            $this->variant->decrement('inventory_quantity', $this->quantity);
        } else {
            $this->product->decrement('inventory_count', $this->quantity);
        }
    }

    /**
     * Get total pre-order quantity for a product
     */
    public static function getTotalPreorderQuantity(int $productId, ?int $variantId = null): int
    {
        return static::where('product_id', $productId)
            ->where('status', 'pending')
            ->when($variantId, function ($query) use ($variantId) {
                $query->where('product_variant_id', $variantId);
            })
            ->sum('quantity');
    }
}
