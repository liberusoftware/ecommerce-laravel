<?php

namespace Liberu\Ecommerce\Marketing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WholesaleGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'discount_percentage',
        'hide_retail_price',
        'requires_approval',
        'is_active',
    ];

    protected $casts = [
        'discount_percentage' => 'decimal:2',
        'hide_retail_price' => 'boolean',
        'requires_approval' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function priceTiers(): HasMany
    {
        return $this->hasMany(WholesalePriceTier::class);
    }
}
