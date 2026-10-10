<?php

namespace Liberu\Ecommerce\ProductCatalog\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'tax_class_id',
        'country',
        'state',
        'city',
        'zip_code',
        'rate',
        'name',
        'priority',
        'compound',
        'shipping',
        'is_active',
    ];

    protected $casts = [
        'rate' => 'decimal:4',
        'priority' => 'integer',
        'compound' => 'boolean',
        'shipping' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function taxClass(): BelongsTo
    {
        return $this->belongsTo(TaxClass::class);
    }

    /**
     * Calculate tax amount for a given price
     */
    public function calculateTax(float $price): float
    {
        return round($price * ($this->rate / 100), 2);
    }

    /**
     * Get matching tax rates for a location
     */
    public static function findMatchingRates(
        string $country,
        ?string $state = null,
        ?string $city = null,
        ?string $zipCode = null,
        ?int $taxClassId = null
    ): Collection {
        // A product with no tax class must not be taxed by EVERY class's rate summed.
        // Fall back to the default (first active) tax class so exactly one class
        // applies. (Assign products a tax class to control which one.)
        if ($taxClassId === null) {
            $taxClassId = TaxClass::where('is_active', true)->orderBy('id')->value('id');
        }

        $query = static::where('is_active', true)
            ->where('country', $country);

        if ($taxClassId) {
            $query->where('tax_class_id', $taxClassId);
        }

        // A rate applies when each of its non-null location columns matches the
        // address — a null column is a wildcard ("applies everywhere at this level").
        // (Laravel turns where('state', null) into whereNull, so a null address field
        // only matches wildcard rates, never a location-specific one.)
        $candidates = $query
            ->where(fn ($q) => $q->whereNull('state')->orWhere('state', $state))
            ->where(fn ($q) => $q->whereNull('city')->orWhere('city', $city))
            ->where(fn ($q) => $q->whereNull('zip_code')->orWhere('zip_code', $zipCode))
            ->orderBy('priority', 'desc')
            ->get();

        if ($candidates->isEmpty()) {
            return $candidates;
        }

        // Most specific wins: a ZIP/city rate beats a state rate beats a country rate.
        // The old exact->state->country tiers made a partially-specified rate (e.g.
        // state + city but no ZIP) unreachable, silently falling through to a coarser one.
        $specificity = fn (TaxRate $rate): int => (int) ($rate->zip_code !== null)
            + (int) ($rate->city !== null)
            + (int) ($rate->state !== null);
        $mostSpecific = $candidates->max($specificity);

        return $candidates->filter(fn (TaxRate $rate): bool => $specificity($rate) === $mostSpecific)->values();
    }
}
