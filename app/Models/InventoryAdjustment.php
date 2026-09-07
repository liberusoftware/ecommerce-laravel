<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_level_id',
        'inventory_item_id',
        'quantity_delta',
        'reason',
        'available_after',
        'user_id',
        'note',
    ];

    protected $casts = [
        'quantity_delta' => 'integer',
        'available_after' => 'integer',
    ];

    public function inventoryLevel(): BelongsTo
    {
        return $this->belongsTo(InventoryLevel::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isIncrease(): bool
    {
        return $this->quantity_delta > 0;
    }

    public function isDecrease(): bool
    {
        return $this->quantity_delta < 0;
    }

    public function scopeIncreases($query)
    {
        return $query->where('quantity_delta', '>', 0);
    }

    public function scopeDecreases($query)
    {
        return $query->where('quantity_delta', '<', 0);
    }

    public function scopeByReason($query, string $reason)
    {
        return $query->where('reason', $reason);
    }
}
