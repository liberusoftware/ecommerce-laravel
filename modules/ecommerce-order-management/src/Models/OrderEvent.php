<?php

namespace Liberu\Ecommerce\OrderManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'event_type',
        'description',
        'metadata',
        'triggered_by',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /**
     * Log an order event
     */
    public static function log(
        int $orderId,
        string $eventType,
        string $description,
        ?array $metadata = null,
        ?int $userId = null
    ): self {
        return static::create([
            'order_id' => $orderId,
            'event_type' => $eventType,
            'description' => $description,
            'metadata' => $metadata,
            'triggered_by' => $userId,
        ]);
    }
}
