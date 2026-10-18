<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'user_id',
        'email',
        'notified',
        'notified_at',
        'notification_type',
    ];

    protected $casts = [
        'notified' => 'boolean',
        'notified_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mark notification as sent
     */
    public function markAsNotified(): void
    {
        $this->update([
            'notified' => true,
            'notified_at' => now(),
        ]);
    }

    /**
     * Get pending notifications for a product
     */
    public static function getPendingForProduct(int $productId)
    {
        return static::where('product_id', $productId)
            ->where('notified', false)
            ->get();
    }
}
