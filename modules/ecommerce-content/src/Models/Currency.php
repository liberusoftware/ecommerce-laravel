<?php

namespace Liberu\Ecommerce\Content\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Currency extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'symbol_position',
        'decimal_places',
        'thousand_separator',
        'decimal_separator',
        'exchange_rate',
        'is_default',
        'is_active',
        'rate_updated_at',
    ];

    protected $casts = [
        'decimal_places' => 'integer',
        'exchange_rate' => 'decimal:6',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'rate_updated_at' => 'datetime',
    ];

    public function productPrices(): HasMany
    {
        return $this->hasMany(ProductCurrencyPrice::class, 'currency_code', 'code');
    }

    /**
     * Format price in this currency
     */
    public function formatPrice(float $price): string
    {
        $formattedPrice = number_format(
            $price,
            $this->decimal_places,
            $this->decimal_separator,
            $this->thousand_separator
        );

        if ($this->symbol_position === 'before') {
            return $this->symbol . $formattedPrice;
        }

        return $formattedPrice . $this->symbol;
    }

    /**
     * Convert amount from base currency to this currency
     */
    public function convertFromBase(float $amount): float
    {
        return round($amount * $this->exchange_rate, $this->decimal_places);
    }

    /**
     * Convert amount from this currency to base currency
     */
    public function convertToBase(float $amount): float
    {
        // exchange_rate is a decimal-cast string; cast to float so 0 / tiny / negative all guard.
        if ((float) $this->exchange_rate < 0.000001) {
            return 0;
        }

        // Result is expressed in the base currency, so round to the base currency's precision
        // (mirrors convertFromBase rounding to this currency's decimal_places). Fall back to 2.
        $baseDecimals = static::getDefault()->decimal_places ?? 2;

        return round($amount / $this->exchange_rate, $baseDecimals);
    }

    /**
     * Get default currency
     */
    public static function getDefault(): ?self
    {
        return static::where('is_default', true)->where('is_active', true)->first();
    }

    /**
     * Get active currencies
     */
    public static function getActive()
    {
        return static::where('is_active', true)->orderBy('code')->get();
    }
}
