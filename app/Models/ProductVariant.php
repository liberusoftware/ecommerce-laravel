<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'sku',
        'title',
        'price',
        'compare_at_price',
        'inventory_quantity',
        'inventory_policy',
        'fulfillment_service',
        'inventory_management',
        'option1',
        'option2',
        'option3',
        'taxable',
        'barcode',
        'grams',
        'image_id',
        'weight',
        'weight_unit',
        'inventory_item_id',
        'old_inventory_quantity',
        'requires_shipping',
        'position',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'compare_at_price' => 'decimal:2',
        'inventory_quantity' => 'integer',
        'taxable' => 'boolean',
        'grams' => 'integer',
        'weight' => 'decimal:2',
        'old_inventory_quantity' => 'integer',
        'requires_shipping' => 'boolean',
        'position' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(ProductImage::class, 'image_id');
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function isInStock(): bool
    {
        return $this->inventory_quantity > 0;
    }

    public function isLowStock(): bool
    {
        return $this->inventory_quantity <= ($this->product->low_stock_threshold ?? 5);
    }

    public function getDisplayTitleAttribute(): string
    {
        $options = array_filter([$this->option1, $this->option2, $this->option3]);
        return $this->title ?: implode(' / ', $options);
    }

    public function scopeInStock($query)
    {
        return $query->where('inventory_quantity', '>', 0);
    }

    public function scopeLowStock($query)
    {
        return $query->whereRaw('inventory_quantity <= COALESCE((SELECT low_stock_threshold FROM products WHERE products.id = product_variants.product_id), 5)');
    }
}