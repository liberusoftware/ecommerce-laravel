<?php

namespace App\Models;

use App\Http\Middleware\TrustHosts;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * One hostname a Channel answers on. Hostname only — no scheme, no port.
 */
class ChannelDomain extends Model
{
    use HasFactory;

    protected $fillable = ['channel_id', 'host', 'is_primary'];

    protected $casts = ['is_primary' => 'boolean'];

    /**
     * The trusted-host list is this table, cached. Clearing it here rather than
     * at the call sites that add domains is the same rule the store scope
     * follows: a cache invalidated by whoever remembers is a cache that goes
     * stale, and stale here means a merchant adds a domain and their storefront
     * answers 400 until something else happens to clear it.
     */
    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(TrustHosts::CACHE_KEY));
        static::deleted(fn () => Cache::forget(TrustHosts::CACHE_KEY));
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * Hosts are stored and compared in one shape, so that `EXAMPLE.com:8000`
     * and `example.com` are the same hostname rather than two.
     */
    public static function normalise(string $host): string
    {
        return strtolower(trim(explode(':', trim($host), 2)[0]));
    }

    public function setHostAttribute(string $value): void
    {
        $this->attributes['host'] = self::normalise($value);
    }
}
