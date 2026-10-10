<?php

namespace Liberu\Ecommerce\ProductCatalog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBundleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'bundle_id',
        'product_id',
        'product_variant_id',
        'quantity',
        'is_optional',
        'discount_amount',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'is_optional' => 'boolean',
        'discount_amount' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(ProductBundle::class, 'bundle_id');
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
     * Get the effective price for this bundle item
     */
    public function getPrice(): float
    {
        $basePrice = $this->variant ? $this->variant->price : $this->product->price;
        return max(0, ($basePrice * $this->quantity) - $this->discount_amount);
    }
}
