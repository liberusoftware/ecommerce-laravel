<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiftRegistryPurchase extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'registry_item_id',
        'order_id',
        'quantity',
        'purchaser_name',
        'purchaser_email',
        'anonymous',
        'purchased_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'anonymous' => 'boolean',
        'purchased_at' => 'datetime',
    ];

    public function registryItem(): BelongsTo
    {
        return $this->belongsTo(GiftRegistryItem::class, 'registry_item_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
