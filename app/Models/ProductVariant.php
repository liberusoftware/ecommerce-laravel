<?php

namespace App\Models;

use App\Jobs\SyncProductToFacebookCatalog;
use App\Services\Inventory;
use App\Traits\IsStoreScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One purchasable version of a product: a combination of its variation
 * attributes with its own SKU, selling price and stock. Removed variants are
 * soft-deleted so carts, orders and stock history can still name them.
 */
class ProductVariant extends Model
{
    use IsStoreScoped;
    use SoftDeletes;

    protected $fillable = [
        'attribute_values',
        'combination_key',
        'sku',
        'supplier_variant_id',
        'price',
        'track_inventory',
        'inventory_count',
        'is_active',
        'position',
    ];

    protected $attributes = [
        'track_inventory' => true,
        'inventory_count' => 0,
        'is_active' => true,
    ];

    protected $casts = [
        'attribute_values' => 'array',
        'price' => 'decimal:2',
        'track_inventory' => 'boolean',
        'inventory_count' => 'integer',
        'is_active' => 'boolean',
        'position' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (ProductVariant $variant) {
            $variant->store_id = $variant->product()->withoutGlobalScopes()->value('store_id') ?? $variant->store_id;
        });

        // A removed variant gives up its combination, so the same one can be offered again.
        static::softDeleted(function (ProductVariant $variant) {
            $variant->forceFill(['combination_key' => null, 'is_active' => false])->saveQuietly();
        });

        static::updated(function (ProductVariant $variant) {
            if ($variant->track_inventory
                && $variant->wasChanged('inventory_count')
                && (int) $variant->getOriginal('inventory_count') <= 0
                && $variant->inventory_count > 0) {
                $variant->product?->notifyBackInStockSubscribers();
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeAvailable(Builder $query): void
    {
        $query->where($this->qualifyColumn('is_active'), true);
    }

    /** "Black / 128 GB" */
    public function label(): string
    {
        return self::labelFor($this->attribute_values ?? []);
    }

    /** @param  list<array{name: string, value: string}>  $values */
    public static function labelFor(array $values): string
    {
        return implode(' / ', array_column($values, 'value'));
    }

    public function sellingPrice(): float
    {
        return (float) $this->price;
    }

    public function tracksInventory(): bool
    {
        return (bool) $this->track_inventory;
    }

    public function isPurchasable(): bool
    {
        return $this->is_active && ! $this->trashed() && $this->isInStock();
    }

    public function isInStock(): bool
    {
        return ! $this->tracksInventory() || $this->inventory_count > 0;
    }

    public function canSupply(int $quantity): bool
    {
        return ! $this->tracksInventory() || $this->inventory_count >= $quantity;
    }

    public function isLowStock(): bool
    {
        return $this->tracksInventory() && $this->inventory_count <= (int) $this->product?->low_stock_threshold;
    }

    public function adjustInventory(int $delta, string $reason): bool
    {
        $adjusted = app(Inventory::class)->adjust($this, $delta, $reason);

        // The Catalog shows the product as available while any variant is.
        if ($adjusted && $this->product?->list_on_facebook) {
            SyncProductToFacebookCatalog::dispatch($this->product_id);
        }

        return $adjusted;
    }
}
