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
