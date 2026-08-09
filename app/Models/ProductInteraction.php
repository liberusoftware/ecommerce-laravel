<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductInteraction extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'session_id',
        'product_id',
        'interaction_type',
        'duration',
        'metadata',
        'interacted_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'duration' => 'integer',
        'interacted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Track a product interaction
     */
    public static function track(
        ?int $userId,
        ?string $sessionId,
        int $productId,
        string $interactionType,
        ?int $duration = null,
        ?array $metadata = null
    ): self {
        return static::create([
            'user_id' => $userId,
            'session_id' => $sessionId ?? session()->getId(),
            'product_id' => $productId,
            'interaction_type' => $interactionType,
            'duration' => $duration,
            'metadata' => $metadata,
            'interacted_at' => now(),
        ]);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('interaction_type', $type);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('interacted_at', '>=', now()->subDays($days));
    }
}
