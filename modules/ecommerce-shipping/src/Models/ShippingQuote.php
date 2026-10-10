<?php

namespace Liberu\Ecommerce\Shipping\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A persisted live carrier rate. The stored `amount` is authoritative at checkout —
 * see the create_shipping_quotes_table migration for why the client price is never
 * trusted.
 */
class ShippingQuote extends Model
{
    protected $fillable = [
        'session_id',
        'user_id',
        'carrier',
        'service',
        'amount',
        'currency',
        'delivery_days',
        'rate_id',
        'expires_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'delivery_days' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Not-yet-expired quotes. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }
}
