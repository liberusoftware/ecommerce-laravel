<?php

namespace Liberu\Ecommerce\Marketing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyProgram extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'points_per_dollar',
        'points_value',
        'points_expiry_days',
        'min_points_redemption',
        'is_active',
    ];

    protected $casts = [
        'points_per_dollar' => 'decimal:2',
        'points_value' => 'decimal:4',
        'points_expiry_days' => 'integer',
        'min_points_redemption' => 'integer',
        'is_active' => 'boolean',
    ];

    public function loyaltyPoints(): HasMany
    {
        return $this->hasMany(LoyaltyPoints::class);
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(LoyaltyTier::class)->orderBy('sort_order');
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(LoyaltyReward::class);
    }

    /**
     * Calculate points earned for an amount
     */
    public function calculatePointsEarned(float $amount): int
    {
        return (int) floor($amount * $this->points_per_dollar);
    }

    /**
     * Calculate dollar value of points
     */
    public function calculatePointsValue(int $points): float
    {
        return round($points * $this->points_value, 2);
    }
}
