<?php

namespace Liberu\Ecommerce\Marketing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyPointTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'loyalty_points_id',
        'order_id',
        'points',
        'type',
        'description',
        'expires_at',
        'is_expired',
        'created_by',
    ];

    protected $casts = [
        'points' => 'integer',
        'expires_at' => 'datetime',
        'is_expired' => 'boolean',
    ];

    public function loyaltyPoints(): BelongsTo
    {
        return $this->belongsTo(LoyaltyPoints::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
