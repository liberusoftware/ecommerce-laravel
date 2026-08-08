<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One hostname a Channel answers on. Hostname only — no scheme, no port.
 */
class ChannelDomain extends Model
{
    use HasFactory;

    protected $fillable = ['channel_id', 'host', 'is_primary'];

    protected $casts = ['is_primary' => 'boolean'];

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
