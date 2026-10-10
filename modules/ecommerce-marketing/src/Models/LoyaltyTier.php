<?php

namespace Liberu\Ecommerce\Marketing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'loyalty_program_id',
        'name',
        'min_points',
        'min_spend',
        'points_multiplier',
        'discount_percentage',
        'benefits',
        'sort_order',
    ];

    protected $casts = [
        'min_points' => 'integer',
        'min_spend' => 'decimal:2',
        'points_multiplier' => 'decimal:2',
        'discount_percentage' => 'decimal:2',
        'benefits' => 'array',
        'sort_order' => 'integer',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'loyalty_program_id');
    }

    /**
     * Check if customer qualifies for this tier
     */
    public function qualifiesForTier(int $points, float $totalSpend): bool
    {
        return $points >= $this->min_points || $totalSpend >= $this->min_spend;
    }
}
