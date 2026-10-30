<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryLog extends Model
{
    protected $table = 'inventory_logs';

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'quantity_change',
        'old_quantity',
        'new_quantity',
        'reason',
        'reference_id',
        'reference_type',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }
}
