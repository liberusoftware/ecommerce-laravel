<?php

namespace App\Models;

use App\Interfaces\Orderable;
use App\Jobs\RemoveProductFromFacebookCatalog;
use App\Jobs\SyncProductToFacebookCatalog;
use App\Notifications\ProductBackInStockNotification;
use App\Services\TaxCalculator;
use App\Traits\IsStoreScoped;
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
    use IsStoreScoped;
    use IsTenantModel;
    use SoftDeletes;

    protected $table = 'products';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_ACTIVE => 'Active',
        self::STATUS_ARCHIVED => 'Archived',
    ];

    public const VISIBILITY_VISIBLE = 'visible';

    public const VISIBILITY_HIDDEN = 'hidden';

    public const VISIBILITIES = [
        self::VISIBILITY_VISIBLE => 'Listed in catalogue and search',
        self::VISIBILITY_HIDDEN => 'Hidden — reachable by direct link only',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'visibility' => self::VISIBILITY_VISIBLE,
        'inventory_count' => 0,
        'low_stock_threshold' => 0,
    ];

    protected $fillable = [
        'name',
        'slug',
        'sku',
        'status',
        'visibility',
        'description',
        'short_description',
        'long_description',
        'price',
        'sale_price',
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
        'list_on_facebook',
        'tax_class_id',
        'tax_status',

        // Fillable so the API write paths can stamp the creating admin's team.
        // No validator accepts it from request input, so it cannot be set by a
        // caller — see Api\Concerns\OwnsTeamResources.
        'team_id',
    ];

    protected $casts = [
        'is_downloadable' => 'boolean',
        'list_on_facebook' => 'boolean',
        'price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'is_featured' => 'boolean',
        'tax_status' => 'boolean',
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

    /**
     * Uploaded images are public-disk paths; external URLs and root-relative
     * paths are used as given. Local-disk files are served host-relative so they
     * load on every storefront domain and port, not only on APP_URL.
     */
    public function getImageUrlAttribute(): ?string
    {
        $path = $this->featured_image;

        if (blank($path)) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://', '//', '/'])) {
            return $path;
        }

        $url = Storage::disk('public')->url($path);
        $appUrl = rtrim((string) config('app.url'), '/').'/';

        return str_starts_with($url, $appUrl) ? '/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/') : $url;
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
        // Only on create: a product's URL does not change because it was renamed.
        static::creating(function (Product $product) {
            if (empty($product->slug)) {
                $product->slug = $product->uniqueSlug(Str::slug($product->name) ?: 'product');
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

        static::saved(function ($product) {
            if ($product->list_on_facebook) {
                SyncProductToFacebookCatalog::dispatch($product->id);
            } elseif ($product->wasChanged('list_on_facebook')) {
                $product->dispatchFacebookUnlist();
            }
        });

        // `deleting`, not `deleted`: the retailer ids have to be read before a
        // force delete cascades the listing rows away.
        static::deleting(function ($product) {
            $product->dispatchFacebookUnlist();
        });
    }

    public function facebookListings(): HasMany
    {
        return $this->hasMany(ProductFacebookListing::class);
    }

    /** Queue removal of whatever this Product currently occupies in the Catalog. */
    public function dispatchFacebookUnlist(): void
    {
        $retailerIds = $this->facebookListings()->pluck('retailer_id')->all();

        if ($retailerIds !== [] && $this->team_id !== null) {
            RemoveProductFromFacebookCatalog::dispatch($retailerIds, (int) $this->team_id);
        }
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

    /** The base slug, or the first `-N` variant of it not yet used in this store. */
    public function uniqueSlug(string $base): string
    {
        $slug = $base;
        $n = 2;

        while (static::withoutGlobalScopes()
            ->where('slug', $slug)
            ->where('store_id', $this->store_id)
            ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
            ->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /** Active products can be viewed and bought on a storefront. */
    public function scopeActive(Builder $query): void
    {
        $query->where($this->qualifyColumn('status'), self::STATUS_ACTIVE);
    }

    /** Active and listed: what catalogue pages, search and feeds show. */
    public function scopeListed(Builder $query): void
    {
        $query->active()->where($this->qualifyColumn('visibility'), self::VISIBILITY_VISIBLE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isOnSale(): bool
    {
        return $this->sale_price !== null && (float) $this->sale_price < (float) $this->price;
    }

    /** What a shopper pays per unit before tax and discounts. */
    public function sellingPrice(): float
    {
        if ($this->isFree()) {
            return 0.0;
        }

        return $this->isOnSale() ? (float) $this->sale_price : (float) $this->price;
    }

    public function isInStock(): bool
    {
        return (int) $this->inventory_count > 0;
    }

    public function isLowStock()
    {
        return $this->inventory_count <= $this->low_stock_threshold;
    }

    public function getPrice(): float
    {
        return $this->sellingPrice();
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
        $adjusted = (bool) DB::transaction(function () use ($delta, $reason) {
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

        // increment()/decrement() bypass `saved`, so the Catalog push that a
        // plain save() would have queued has to be queued here. Selling out is
        // an availability flip, never an unlist.
        if ($adjusted && $this->list_on_facebook) {
            SyncProductToFacebookCatalog::dispatch($this->id);
        }

        return $adjusted;
    }
}
