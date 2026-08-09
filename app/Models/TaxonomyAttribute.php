<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxonomyAttribute extends Model
{
    use HasFactory;

    protected $fillable = [
        'taxonomy_category_id',
        'name',
        'slug',
        'type',
        'options',
        'is_required',
        'is_filterable',
        'sort_order',
    ];

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
        'is_filterable' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(TaxonomyCategory::class, 'taxonomy_category_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(ProductTaxonomyValue::class);
    }

    public function scopeFilterable($query)
    {
        return $query->where('is_filterable', true);
    }

    public function scopeRequired($query)
    {
        return $query->where('is_required', true);
    }
}
