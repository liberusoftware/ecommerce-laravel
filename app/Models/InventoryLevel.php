<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryLevel extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_item_id',
        'location_id',
        'available',
        'committed',
        'incoming',
        'on_hand',
        'reserved',
        'updated_at',
    ];

    protected $casts = [
        'available' => 'integer',
        'committed' => 'integer',
        'incoming' => 'integer',
        'on_hand' => 'integer',
        'reserved' => 'integer',
    ];

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class);
    }

    public function adjustQuantity(int $quantity, ?string $reason = null): void
    {
        $this->available += $quantity;
        $this->on_hand += $quantity;
        $this->save();

        // Log the adjustment. inventory_item_id is NOT NULL in the schema, so it
        // must be set here or every adjustment insert fatals.
        InventoryAdjustment::create([
            'inventory_level_id' => $this->id,
            'inventory_item_id' => $this->inventory_item_id,
            'quantity_delta' => $quantity,
            'reason' => $reason,
            'available_after' => $this->available,
        ]);
    }

    public function reserve(int $quantity): bool
    {
        if ($this->available < $quantity) {
            return false;
        }

        $this->available -= $quantity;
        $this->reserved += $quantity;
        $this->save();

        return true;
    }

    public function commit(int $quantity): bool
    {
        if ($this->reserved < $quantity) {
            return false;
        }

        $this->reserved -= $quantity;
        $this->committed += $quantity;
        $this->save();

        return true;
    }

    public function fulfill(int $quantity): bool
    {
        if ($this->committed < $quantity) {
            return false;
        }

        $this->committed -= $quantity;
        $this->on_hand -= $quantity;
        $this->save();

        return true;
    }

    public function scopeLowStock($query, int $threshold = 5)
    {
        return $query->where('available', '<=', $threshold);
    }

    public function scopeOutOfStock($query)
    {
        return $query->where('available', '<=', 0);
    }
}