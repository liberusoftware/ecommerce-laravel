<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One choice in an option group. Owned by its group, so scoped through it. */
class ProductOptionChoice extends Model
{
    protected $fillable = ['name', 'price_adjustment', 'is_default', 'is_available', 'position'];

    protected $casts = [
        'price_adjustment' => 'decimal:2',
        'is_default' => 'boolean',
        'is_available' => 'boolean',
        'position' => 'integer',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductOptionGroup::class, 'product_option_group_id');
    }
}
