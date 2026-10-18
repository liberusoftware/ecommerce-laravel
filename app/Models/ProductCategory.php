<?php

namespace App\Models;

use App\Traits\IsStoreScoped;
use App\Traits\IsTenantModel;
use Biostate\FilamentMenuBuilder\Traits\Menuable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
        'image',
        'meta_title',
        'meta_description',
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

    protected static function booted(): void
    {
        // `creating`, not `saving`: the store scope stamps store_id in `creating`, and both checks need it.
        static::creating(function (ProductCategory $category) {
            if (blank($category->slug)) {
                $category->slug = $category->uniqueSlug(Str::slug((string) $category->name) ?: 'category');
            }

            $category->assertValidParent();
        });

        static::updating(fn (ProductCategory $category) => $category->assertValidParent());

        static::deleting(function (ProductCategory $category) {
            if ($reason = $category->deletionBlocker()) {
                throw ValidationException::withMessages(['category' => $reason]);
            }
        });
    }

    /** The base slug, or the first `-N` variant not yet used in this store. */
    public function uniqueSlug(string $base): string
    {
        $slug = $base;
        $n = 2;

        while (static::withoutGlobalScopes()
            ->where('store_id', $this->store_id)
            ->where('slug', $slug)
            ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
            ->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /**
     * Two levels: departments and their subcategories, which is what the
     * storefront lists and navigates. The parent must be a top-level category in
     * the same store, and a category with subcategories cannot become one.
     */
    public function assertValidParent(): void
    {
        if ($this->parent_category_id === null) {
            return;
        }

        $parent = static::withoutGlobalScopes()->find($this->parent_category_id);

        $error = match (true) {
            $parent === null => 'The parent category does not exist.',
            $this->exists && (int) $parent->getKey() === (int) $this->getKey() => 'A category cannot be its own parent.',
            (int) $parent->store_id !== (int) $this->store_id => 'The parent category belongs to another store.',
            $parent->parent_category_id !== null => 'Subcategories cannot have subcategories of their own.',
            $this->exists && $this->children()->exists() => 'A category with subcategories cannot become a subcategory.',
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages(['parent_category_id' => $error]);
        }
    }

    /** Why this category cannot be deleted, or null when it can. */
    public function deletionBlocker(): ?string
    {
        if ($this->children()->exists()) {
            return "“{$this->name}” has subcategories. Move or delete them first.";
        }

        $products = $this->products()->count();

        return $products > 0
            ? "“{$this->name}” still has {$products} ".Str::plural('product', $products).'. Move them to another category first.'
            : null;
    }

    public function imageUrl(): ?string
    {
        if (blank($this->image)) {
            return null;
        }

        if (Str::startsWith($this->image, ['http://', 'https://', '/'])) {
            return $this->image;
        }

        return '/'.ltrim((string) parse_url(Storage::disk('public')->url($this->image), PHP_URL_PATH), '/');
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
