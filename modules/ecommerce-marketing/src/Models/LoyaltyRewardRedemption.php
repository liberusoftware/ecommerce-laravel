<?php

namespace Liberu\Ecommerce\Marketing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyRewardRedemption extends Model
{
    use HasFactory;

    protected $fillable = [
        'loyalty_reward_id',
        'user_id',
        'order_id',
        'points_spent',
        'status',
        'expires_at',
    ];

    protected $casts = [
        'points_spent' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function reward(): BelongsTo
    {
        return $this->belongsTo(LoyaltyReward::class, 'loyalty_reward_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
