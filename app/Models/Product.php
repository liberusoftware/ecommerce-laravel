<?php

namespace App\Models;

use App\Models\Brand;
use Illuminate\Support\Str;
use App\Interfaces\Orderable;
use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model implements Orderable
{
    use HasFactory;
    use IsTenantModel;

    protected $table = 'products';

 
    protected $fillable = [
        'name',
        'price',
        'short_description',
        'long_description',
        'category_id',
        'vehicle_model_id',
        'brand_id',
        'is_variable',
        'is_grouped',
        'is_simple',
        'is_featured',
        'featured_image'
    ];

    public function vehicleModel()
    {
        return $this->belongsTo(VehicleModel::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }


    public function category()
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function collections()
    {
        return $this->belongsToMany(ProductCollection::class, 'collection_items');
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('order');
    }

    public function getImageUrlAttribute()
    {
        return $this->featured_image;
        // return asset(Storage::url($this->featured_image));
    }

    public function cartItems()
    {
        return $this->hasMany(CartItem::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function review()
    {
        return $this->hasMany(ProductReview::class);
    }

    public function rating()
    {
        return $this->hasMany(ProductRating::class);
    }

    public function downloadable()
    {
        return $this->hasMany(DownloadableProduct::class);
    }

    public function scopeWithTag($query, Tag $tag)
    {
        return $query->whereHas('tags', function ($query) use ($tag) {
            $query->where('tags.id', $tag->id);
        });
    }

    public function scopeWithTagNames($query, array $tagNames)
    {
        return $query->whereHas('tags', function ($query) use ($tagNames) {
            $query->whereIn('name', $tagNames);
        });
    }

    public function scopeSearch($query, $keyword)
    {
        return $query->where(function ($q) use ($keyword) {
            $q->where('name', 'like', '%' . $keyword . '%')
                ->orWhere('description', 'like', '%' . $keyword . '%');
        });
    }

    public function scopeCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopePriceRange($query, $min, $max)
    {
        return $query->when($min, function ($q) use ($min) {
            $q->where('price', '>=', $min);
        })
            ->when($max, function ($q) use ($max) {
                $q->where('price', '<=', $max);
            });
    }

    public function scopePriceMin(Builder $query, $min): void
    {
        $query->where('price', '>=', (float) $min);
    }

    public function scopePriceMax(Builder $query, $max): void
    {
        $query->where('price', '<=', (float) $max);
    }

    public function isLowStock()
    {
        return $this->inventory_count <= $this->low_stock_threshold;
    }

    public function getSlugAttribute()
    {
        return Str::slug($this->name);
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
