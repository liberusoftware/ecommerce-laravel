<?php

namespace App\Models;

use App\Traits\IsStoreScoped;
use App\Traits\IsTenantModel;
use Biostate\FilamentMenuBuilder\Traits\Menuable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductCategory extends Model
{
    use HasFactory;
    use IsStoreScoped;
    use IsTenantModel;
    use Menuable;

    protected $table = 'product_categories';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'parent_category_id',
        'meta_title',
        'meta_description',
        'meta_keywords',
    ];

    public function products()
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    public function parent()
    {
        return $this->belongsTo(static::class, 'parent_category_id');
    }

    public function children()
    {
        return $this->hasMany(static::class, 'parent_category_id');
    }

    public function scopeTopLevel($query)
    {
        return $query->whereNull('parent_category_id');
    }

    /**
     * This category and its direct subcategories: a category page lists the
     * products of both. The storefront hierarchy is two levels deep.
     *
     * @return list<int>
     */
    public function listingCategoryIds(): array
    {
        return [$this->id, ...$this->children()->pluck('id')->all()];
    }

    public function getRouteKeyName()
    {
        return 'slug';
    }

    public function getMenuLinkAttribute(): string
    {
        return route('categories.show', $this);
    }

    public function getMenuNameAttribute(): string
    {
        return $this->name;
    }

    public static function getFilamentSearchLabel(): string
    {
        return 'name';
    }
}
