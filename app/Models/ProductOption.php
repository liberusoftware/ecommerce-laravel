<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductOption extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'name',
        'position',
        'values',
    ];

    protected $casts = [
        'values' => 'array',
        'position' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getValuesListAttribute(): string
    {
        return implode(', ', $this->values ?? []);
    }

    public function scopeByPosition($query)
    {
        return $query->orderBy('position');
    }
}