<?php

namespace Liberu\Ecommerce\ProductCatalog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductBundle extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'name',
        'description',
        'discount_amount',
        'discount_percentage',
        'is_active',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'discount_percentage' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductBundleItem::class, 'bundle_id')->orderBy('sort_order');
    }

    /**
     * Calculate total price without bundle discount
     */
    public function getRegularPrice(): float
    {
        return $this->items->sum(function ($item) {
            $price = $item->variant ? $item->variant->price : $item->product->price;
            return $price * $item->quantity;
        });
    }

    /**
     * Calculate bundle price with discount
     */
    public function getBundlePrice(): float
    {
        $regularPrice = $this->getRegularPrice();
        
        if ($this->discount_percentage > 0) {
            return max(0, $regularPrice * (1 - $this->discount_percentage / 100));
        }
        
        if ($this->discount_amount > 0) {
            return max(0, $regularPrice - $this->discount_amount);
        }
        
        return $regularPrice;
    }

    /**
     * Calculate savings amount
     */
    public function getSavings(): float
    {
        return $this->getRegularPrice() - $this->getBundlePrice();
    }

    /**
     * Check if all bundle items are in stock
     */
    public function isInStock(): bool
    {
        foreach ($this->items as $item) {
            // Variants track stock in inventory_quantity; products in inventory_count.
            $inventory = $item->variant
                ? ($item->variant->inventory_quantity ?? 0)
                : ($item->product->inventory_count ?? 0);

            if ($inventory < $item->quantity) {
                return false;
            }
        }
        
        return true;
    }
}
