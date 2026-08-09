<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartRecoveryAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'abandoned_cart_id',
        'campaign_id',
        'channel',
        'sent_at',
        'clicked_at',
        'converted_at',
        'order_id',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'clicked_at' => 'datetime',
        'converted_at' => 'datetime',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(AbandonedCart::class, 'abandoned_cart_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CartRecoveryCampaign::class, 'campaign_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Mark recovery email/SMS as clicked
     */
    public function markClicked(): void
    {
        $this->update(['clicked_at' => now()]);
    }

    /**
     * Mark as converted
     */
    public function markConverted(int $orderId): void
    {
        $this->update([
            'converted_at' => now(),
            'order_id' => $orderId,
        ]);
    }

    public function scopeConverted($query)
    {
        return $query->whereNotNull('converted_at');
    }

    public function scopeClicked($query)
    {
        return $query->whereNotNull('clicked_at');
    }
}
