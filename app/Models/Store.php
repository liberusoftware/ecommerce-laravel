<?php

namespace App\Models;

use App\Factories\PaymentGatewayFactory;
use App\Services\ChannelResolver;
use App\Settings\GeneralSettings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * A storefront's worth of commerce data, owned by a Team.
 */
class Store extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_id', 'name', 'slug', 'logo_path', 'tagline', 'email', 'phone',
        'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'country_code',
        'currency_code', 'locale', 'display_prices_with_tax', 'payment_gateways', 'social_links', 'footer_text',
    ];

    protected $casts = [
        'display_prices_with_tax' => 'boolean',
        'payment_gateways' => 'array',
        'social_links' => 'array',
    ];

    /** Social networks a store can link to, keyed as stored in social_links. */
    public const SOCIAL_NETWORKS = [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'x' => 'X (Twitter)',
        'youtube' => 'YouTube',
        'tiktok' => 'TikTok',
    ];

    protected static function booted(): void
    {
        // `saving` runs before `creating`, so new-store defaults are filled here too.
        static::saving(function (Store $store) {
            if (blank($store->currency_code)) {
                $store->currency_code = self::platformDefault('default_currency', 'USD');
            }
            if (blank($store->locale)) {
                $store->locale = self::platformDefault('default_locale', 'en');
            }
            $store->currency_code = strtoupper((string) $store->currency_code);
            $store->country_code = filled($store->country_code) ? strtoupper($store->country_code) : null;
        });
    }

    /** The store the current storefront request resolved to. */
    public static function current(): ?self
    {
        return ChannelResolver::current()?->store;
    }

    private static function platformDefault(string $key, string $fallback): string
    {
        try {
            return (string) app(GeneralSettings::class)->{$key};
        } catch (Throwable) {
            return $fallback;
        }
    }

    /** Tables whose rows belong to a store through store_id. */
    public const COMMERCE_TABLES = [
        'cart_items', 'chat_conversations', 'collections', 'contact_requests', 'coupons', 'customers', 'downloadable_products',
        'invoices', 'orders', 'payment_methods', 'product_categories', 'product_rating',
        'product_reviews', 'products', 'shipping_methods', 'wishlists',
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

    /**
     * Point the main site's hostname at this store. The host moves to this store's
     * first channel, so resolution stays host → channel → store with no fallback.
     */
    public function makeMainSite(): void
    {
        DB::transaction(function () {
            $channel = $this->channels()->orderBy('id')->first()
                ?? $this->channels()->create(['name' => 'Web', 'theme' => 'theme-ecommerce']);

            $domain = ChannelDomain::firstOrNew(['host' => self::mainSiteHost()]);
            $domain->channel_id = $channel->id;
            $domain->is_primary = ! $channel->domains()->where('is_primary', true)
                ->when($domain->exists, fn ($query) => $query->whereKeyNot($domain->id))
                ->exists();
            $domain->save();
        });
    }

    /** The store currently answering on the main site's host. */
    public static function mainSite(): ?self
    {
        return self::query()
            ->whereHas('channels.domains', fn ($query) => $query->where('host', self::mainSiteHost()))
            ->first();
    }

    /** Uploaded logos are public-disk paths served host-relative, like product images. */
    public function logoUrl(): ?string
    {
        if (blank($this->logo_path)) {
            return null;
        }

        if (Str::startsWith($this->logo_path, ['http://', 'https://', '/'])) {
            return $this->logo_path;
        }

        return '/'.ltrim((string) parse_url(Storage::disk('public')->url($this->logo_path), PHP_URL_PATH), '/');
    }

    /** @return list<string> */
    public function addressLines(): array
    {
        return array_values(array_filter([
            $this->address_line1,
            $this->address_line2,
            trim(implode(' ', array_filter([$this->city, $this->region, $this->postal_code]))),
            $this->country_code,
        ], 'filled'));
    }

    /** @return array<string, string> network label => url, only those configured */
    public function socialLinks(): array
    {
        $links = [];

        foreach (self::SOCIAL_NETWORKS as $key => $label) {
            if (filled($url = $this->social_links[$key] ?? null)) {
                $links[$label] = $url;
            }
        }

        return $links;
    }

    /**
     * The gateways this store accepts at checkout. Unset means all of them.
     *
     * @return list<string>
     */
    public function paymentGateways(): array
    {
        $known = array_keys(PaymentGatewayFactory::GATEWAYS);

        if ($this->payment_gateways === null) {
            return $known;
        }

        return array_values(array_intersect($known, $this->payment_gateways));
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
