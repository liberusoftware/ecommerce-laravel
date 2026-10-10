<?php

namespace Liberu\Ecommerce\ProductCatalog\Models;

use Liberu\Ecommerce\ProductCatalog\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TaxonomyCategory extends Model
{
    use HasFactory, IsTenantModel;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'parent_id',
        'level',
        'path',
        'metadata',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_active' => 'boolean',
        'level' => 'integer',
        'sort_order' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(TaxonomyCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(TaxonomyCategory::class, 'parent_id')->orderBy('sort_order');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_taxonomy', 'taxonomy_category_id', 'product_id')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(TaxonomyAttribute::class, 'taxonomy_category_id')->orderBy('sort_order');
    }

    /**
     * Update the materialized path when parent changes
     */
    protected static function booted(): void
    {
        static::saving(function ($category) {
            if ($category->parent_id) {
                $parent = static::find($category->parent_id);
                $category->level = $parent->level + 1;
                $category->path = $parent->path . '/' . $category->id;
            } else {
                $category->level = 0;
                $category->path = '/' . $category->id;
            }
        });
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeRootCategories($query)
    {
        return $query->whereNull('parent_id')->orderBy('sort_order');
    }

    /**
     * Get full category path as array
     */
    public function getPathArray(): array
    {
        return array_filter(explode('/', $this->path));
    }

    /**
     * Get breadcrumb trail
     */
    public function getBreadcrumbs(): array
    {
        $ids = $this->getPathArray();
        return static::whereIn('id', $ids)->orderBy('level')->get()->toArray();
    }
}
