<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A storefront's worth of commerce data, owned by a Team.
 */
class Store extends Model
{
    use HasFactory;

    protected $fillable = ['team_id', 'name', 'slug'];

    /** Tables whose rows belong to a store through store_id. */
    public const COMMERCE_TABLES = [
        'cart_items', 'chat_conversations', 'collections', 'coupons', 'customers', 'downloadable_products',
        'groups', 'images', 'invoices', 'orders', 'payment_methods', 'product_categories', 'product_rating',
        'product_reviews', 'products', 'wishlists',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    /** The host of APP_URL: the main site's address. */
    public static function mainSiteHost(): string
    {
        return ChannelDomain::normalise((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    /** The main store is the one whose channel holds the main site's host. */
    public function servesMainSite(): bool
    {
        return ChannelDomain::where('host', self::mainSiteHost())
            ->whereHas('channel', fn ($query) => $query->where('store_id', $this->id))
            ->exists();
    }

    public function hasCommerceRecords(): bool
    {
        foreach (self::COMMERCE_TABLES as $table) {
            if (DB::table($table)->where('store_id', $this->id)->exists()) {
                return true;
            }
        }

        return false;
    }
}
