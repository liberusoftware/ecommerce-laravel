<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One way into a Store — a set of hostnames and the theme they render with.
 */
class Channel extends Model
{
    use HasFactory;

    protected $fillable = ['store_id', 'name', 'theme'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(ChannelDomain::class);
    }

    /**
     * The hostname canonical URLs are built from, which is not necessarily the
     * one the request arrived on.
     */
    public function primaryDomain(): ?ChannelDomain
    {
        return $this->domains()->where('is_primary', true)->first()
            ?? $this->domains()->first();
    }
}
