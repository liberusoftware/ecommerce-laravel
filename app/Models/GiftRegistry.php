<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

class GiftRegistry extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'type',
        'event_date',
        'message',
        'location',
        'privacy',
        'access_code',
        'is_active',
        'shipping_name',
        'shipping_address',
        'shipping_city',
        'shipping_state',
        'shipping_postal_code',
        'shipping_country',
    ];

    protected $casts = [
        'event_date' => 'date',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function ($registry) {
            if (!$registry->slug) {
                $registry->slug = Str::slug($registry->name . '-' . Str::random(8));
            }
            if ($registry->privacy === 'private' && !$registry->access_code) {
                $registry->access_code = strtoupper(Str::random(8));
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(GiftRegistryItem::class, 'registry_id');
    }

    public function purchases(): HasManyThrough
    {
        return $this->hasManyThrough(
            GiftRegistryPurchase::class,
            GiftRegistryItem::class,
            'registry_id',
            'registry_item_id'
        );
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePublic($query)
    {
        return $query->where('privacy', 'public');
    }

    public function scopeUpcoming($query)
    {
        return $query->where('event_date', '>=', now());
    }

    /**
     * Calculate completion percentage
     */
    public function getCompletionPercentage(): float
    {
        $totalRequested = $this->items->sum('quantity_requested');
        $totalPurchased = $this->items->sum('quantity_purchased');

        if ($totalRequested === 0) {
            return 0;
        }

        return round(($totalPurchased / $totalRequested) * 100, 2);
    }

    /**
     * Check if access code is valid
     */
    public function verifyAccessCode(string $code): bool
    {
        if ($this->privacy !== 'private') {
            return true;
        }

        return $this->access_code === $code;
    }
}
