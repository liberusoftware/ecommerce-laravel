<?php

namespace Liberu\Ecommerce\Inventory\Models;

use Liberu\Ecommerce\Inventory\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryLocation extends Model
{
    use HasFactory;
    use IsTenantModel;

    protected $fillable = [
        'name',
        'address',
        'city',
        'state',
        'country',
        'postal_code',
        'phone',
        'email',
        'is_active',
        'is_fulfillment_service',
        'fulfills_online_orders',
        'fulfills_local_delivery',
        'fulfills_pickup',
        'legacy',
        'admin_graphql_api_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_fulfillment_service' => 'boolean',
        'fulfills_online_orders' => 'boolean',
        'fulfills_local_delivery' => 'boolean',
        'fulfills_pickup' => 'boolean',
        'legacy' => 'boolean',
    ];

    public function inventoryLevels(): HasMany
    {
        return $this->hasMany(InventoryLevel::class);
    }

    public function getFullAddressAttribute(): string
    {
        return trim(implode(', ', array_filter([
            $this->address,
            $this->city,
            $this->state,
            $this->postal_code,
            $this->country,
        ])));
    }

    public function getTotalInventoryAttribute(): int
    {
        return $this->inventoryLevels()->sum('available');
    }

    public function getInventoryForProduct(Product $product): int
    {
        return $this->inventoryLevels()
                    ->whereHas('inventoryItem', function ($query) use ($product) {
                        $query->where('product_id', $product->id);
                    })
                    ->sum('available');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeFulfillsOnlineOrders($query)
    {
        return $query->where('fulfills_online_orders', true);
    }
}