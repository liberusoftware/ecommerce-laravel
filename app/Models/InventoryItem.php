<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'sku',
        'cost',
        'country_code_of_origin',
        'province_code_of_origin',
        'harmonized_system_code',
        'tracked',
        'country_harmonized_system_codes',
        'requires_shipping',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'tracked' => 'boolean',
        'country_harmonized_system_codes' => 'array',
        'requires_shipping' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function inventoryLevels(): HasMany
    {
        return $this->hasMany(InventoryLevel::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(InventoryAdjustment::class);
    }

    public function getTotalAvailableAttribute(): int
    {
        return $this->inventoryLevels()->sum('available');
    }

    public function getTotalOnHandAttribute(): int
    {
        return $this->inventoryLevels()->sum('on_hand');
    }

    public function getTotalCommittedAttribute(): int
    {
        return $this->inventoryLevels()->sum('committed');
    }

    public function getTotalReservedAttribute(): int
    {
        return $this->inventoryLevels()->sum('reserved');
    }

    public function getAvailableAtLocation(InventoryLocation $location): int
    {
        return $this->inventoryLevels()
            ->where('location_id', $location->id)
            ->value('available') ?? 0;
    }

    public function adjustInventory(InventoryLocation $location, int $quantity, ?string $reason = null): void
    {
        $level = $this->inventoryLevels()->firstOrCreate([
            'location_id' => $location->id,
        ], [
            'available' => 0,
            'committed' => 0,
            'incoming' => 0,
            'on_hand' => 0,
            'reserved' => 0,
        ]);

        $level->adjustQuantity($quantity, $reason);
    }

    public function scopeTracked($query)
    {
        return $query->where('tracked', true);
    }

    public function scopeLowStock($query, int $threshold = 5)
    {
        return $query->whereHas('inventoryLevels', function ($q) use ($threshold) {
            $q->where('available', '<=', $threshold);
        });
    }
}
