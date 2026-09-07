<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductTaxonomyValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'taxonomy_attribute_id',
        'value',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(TaxonomyAttribute::class, 'taxonomy_attribute_id');
    }
}
