<?php

namespace App\Models;

use App\Interfaces\Orderable;
use App\Notifications\ProductBackInStockNotification;
use App\Services\TaxCalculator;
use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Product extends Model implements Orderable
{
    use HasFactory;
    use IsTenantModel;
    use SoftDeletes;

    protected $table = 'products';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'short_description',
        'long_description',
        'price',
        'category_id',
        'featured_image',
        'inventory_count',
        'low_stock_threshold',
        'weight',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'is_downloadable',
        'downloadable_file',
        'download_limit',
        'expiration_time',
        'pricing_type',
        'suggested_price',
        'minimum_price',
        'is_featured',

        // Fillable so the API write paths can stamp the creating admin's team.
        // No validator accepts it from request input, so it cannot be set by a
        // caller — see Api\Concerns\OwnsTeamResources.
        'team_id',
    ];

    protected $casts = [
        'is_downloadable' => 'boolean',
        'price' => 'decimal:2',
        'weight' => 'decimal:2',
        'suggested_price' => 'decimal:2',
        'minimum_price' => 'decimal:2',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
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
        return $this->hasOne(DownloadableProduct::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function options()
    {
        return $this->hasMany(ProductOption::class)->orderBy('position');
    }

    public function inventoryItems()
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function seoSettings()
    {
        return $this->morphOne(SeoSetting::class, 'seoable');
    }

    public function analyticsEvents()
    {
        return $this->hasMany(AnalyticsEvent::class);
    }

    public function crossSells()
    {
        return $this->belongsToMany(
            Product::class,
            'product_cross_sells',
            'product_id',
            'cross_sell_product_id'
        )->withTimestamps()->orderBy('sort_order');
    }

    public function upsells()
    {
        return $this->belongsToMany(
            Product::class,
            'product_upsells',
            'product_id',
            'upsell_product_id'
        )->withTimestamps()->orderBy('sort_order');
    }

    public function relatedProducts()
    {
        return $this->belongsToMany(
            Product::class,
            'product_related',
            'product_id',
            'related_product_id'
        )->withTimestamps()->orderBy('sort_order');
    }

    public function taxClass()
    {
        return $this->belongsTo(TaxClass::class);
    }

    public function isDownloadable(): bool
    {
        return $this->is_downloadable && $this->downloadable()->exists();
    }

    /**
     * Catalogue display price — tax-inclusive when the store is configured to
     * show prices with tax, otherwise the bare price. Delegates to TaxCalculator.
     *
     * ponytail: resolves the calculator per call; fine for a product page, batch
     * it (one rate lookup for the list) if a big catalogue index gets slow.
     */
    public function displayPrice(): float
    {
        return app(TaxCalculator::class)->displayPrice($this);
    }

    protected static function booted(): void
    {
        static::creating(function ($product) {
            // Auto-generate slug if not provided
            if (empty($product->slug)) {
                $product->slug = Str::slug($product->name);
            }
        });

        static::updating(function ($product) {
            // Update slug if name changed and slug not manually set
            if ($product->isDirty('name') && ! $product->isDirty('slug')) {
                $product->slug = Str::slug($product->name);
            }
        });

        static::saved(function ($product) {
            if ($product->is_downloadable && $product->downloadable_file) {
                $product->downloadable()->updateOrCreate(
                    ['product_id' => $product->id],
                    [
                        'file_url' => $product->downloadable_file,
                        'download_limit' => $product->download_limit ?? PHP_INT_MAX,
                        'expiration_time' => $product->expiration_time,
                    ]
                );
            }
        });

        // Fire back-in-stock notifications when inventory crosses 0 -> positive.
        // `updated` (not `saved`) so increment()/decrement() — the admin & refund
        // restock paths — are caught alongside plain save()/update().
        static::updated(function ($product) {
            if ($product->wasChanged('inventory_count')
                && (int) $product->getOriginal('inventory_count') <= 0
                && $product->inventory_count > 0) {
                $product->notifyBackInStockSubscribers();
            }
        });
    }

    /**
     * Email everyone waiting on this product's restock, then mark them notified
     * so they're told once. Logged-in subscribers go through their User; guests
     * get an on-demand email.
     *
     * ponytail: synchronous loop — fine for typical waitlists; push to a queued
     * job if a single product ever has thousands of subscribers.
     */
    public function notifyBackInStockSubscribers(): void
    {
        $pending = StockNotification::getPendingForProduct($this->id)
            ->where('notification_type', 'back_in_stock');

        foreach ($pending as $subscriber) {
            $notification = new ProductBackInStockNotification($this, $subscriber->variant);

            if ($subscriber->user_id && $subscriber->user) {
                $subscriber->user->notify($notification);
            } elseif ($subscriber->email) {
                Notification::route('mail', $subscriber->email)->notify($notification);
            }

            $subscriber->markAsNotified();
        }
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
            $q->where('name', 'like', '%'.$keyword.'%')
                ->orWhere('description', 'like', '%'.$keyword.'%');
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

    public function getPrice(): float
    {
        if ($this->isFree()) {
            return 0.00;
        }

        return $this->price;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isFree(): bool
    {
        return $this->pricing_type === 'free';
    }

    public function isDonationBased(): bool
    {
        return $this->pricing_type === 'donation';
    }

    public function hasVariants(): bool
    {
        return $this->variants()->exists();
    }

    public function getDefaultVariant()
    {
        return $this->variants()->orderBy('position')->first();
    }

    public function getTotalInventory(): int
    {
        if ($this->hasVariants()) {
            return $this->variants()->sum('inventory_quantity');
        }

        return $this->inventory_count;
    }

    public function getLowestPrice(): float
    {
        if ($this->hasVariants()) {
            return $this->variants()->min('price') ?? $this->price;
        }

        return $this->price;
    }

    public function getHighestPrice(): float
    {
        if ($this->hasVariants()) {
            return $this->variants()->max('price') ?? $this->price;
        }

        return $this->price;
    }

    public function getAverageRating(): float
    {
        return $this->rating()->avg('rating') ?? 0;
    }

    public function getTotalReviews(): int
    {
        return $this->review()->count();
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Atomically adjust stock by $delta (positive to add, negative to remove) and
     * record a reconcilable old/new InventoryLog row. A decrease is a guarded atomic
     * decrement — it only succeeds while enough stock remains, so concurrent
     * adjustments (or an adjustment racing a checkout decrement) can neither lose an
     * update nor drive the count negative. Returns false if the decrease was refused.
     */
    public function adjustInventory(int $delta, string $reason): bool
    {
        return (bool) DB::transaction(function () use ($delta, $reason) {
            if ($delta < 0) {
                $affected = static::whereKey($this->getKey())
                    ->where('inventory_count', '>=', abs($delta))
                    ->decrement('inventory_count', abs($delta));

                if ($affected === 0) {
                    return false;
                }
            } else {
                $this->increment('inventory_count', $delta);
            }

            $this->refresh();

            InventoryLog::create([
                'product_id' => $this->id,
                'quantity_change' => $delta,
                'old_quantity' => $this->inventory_count - $delta,
                'new_quantity' => $this->inventory_count,
                'reason' => $reason,
            ]);

            return true;
        });
    }
}
