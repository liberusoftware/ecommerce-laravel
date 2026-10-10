<?php

namespace Liberu\Ecommerce\Marketing\Models;

use Liberu\Ecommerce\Marketing\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Discount extends Model
{
    use HasFactory;
    use IsTenantModel;

    const TYPE_PERCENTAGE = 'percentage';
    const TYPE_FIXED_AMOUNT = 'fixed_amount';
    const TYPE_FREE_SHIPPING = 'free_shipping';
    const TYPE_BUY_X_GET_Y = 'buy_x_get_y';

    const TARGET_ORDER = 'order';
    const TARGET_PRODUCT = 'product';
    const TARGET_COLLECTION = 'collection';
    const TARGET_SHIPPING = 'shipping';

    protected $fillable = [
        'title',
        'code',
        'description',
        'type',
        'value',
        'target_type',
        'target_selection',
        'minimum_requirements',
        'customer_eligibility',
        'usage_limits',
        'active_dates',
        'is_active',
        'applies_once_per_customer',
        'applies_to_each_item',
        'customer_group_id',
        'prerequisite_subtotal_range',
        'prerequisite_quantity_range',
        'prerequisite_shipping_price_range',
        'entitled_product_ids',
        'entitled_collection_ids',
        'entitled_country_ids',
        'prerequisite_product_ids',
        'prerequisite_collection_ids',
        'prerequisite_customer_ids',
        'allocation_method',
        'once_per_customer',
        'usage_limit',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'target_selection' => 'array',
        'minimum_requirements' => 'array',
        'customer_eligibility' => 'array',
        'usage_limits' => 'array',
        'active_dates' => 'array',
        'is_active' => 'boolean',
        'applies_once_per_customer' => 'boolean',
        'applies_to_each_item' => 'boolean',
        'prerequisite_subtotal_range' => 'array',
        'prerequisite_quantity_range' => 'array',
        'prerequisite_shipping_price_range' => 'array',
        'entitled_product_ids' => 'array',
        'entitled_collection_ids' => 'array',
        'entitled_country_ids' => 'array',
        'prerequisite_product_ids' => 'array',
        'prerequisite_collection_ids' => 'array',
        'prerequisite_customer_ids' => 'array',
        'once_per_customer' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'discount_products');
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(ProductCollection::class, 'discount_collections');
    }

    public function isActive(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $now = now();

        if ($this->starts_at && $now->isBefore($this->starts_at)) {
            return false;
        }

        if ($this->ends_at && $now->isAfter($this->ends_at)) {
            return false;
        }

        return true;
    }

    public function canBeUsedBy(Customer $customer): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        // Check usage limits
        if ($this->usage_limit && $this->orders()->count() >= $this->usage_limit) {
            return false;
        }

        // Check once per customer
        if ($this->once_per_customer && $this->orders()->where('customer_id', $customer->id)->exists()) {
            return false;
        }

        // Check customer eligibility
        if (!empty($this->prerequisite_customer_ids) && !in_array($customer->id, $this->prerequisite_customer_ids)) {
            return false;
        }

        // Check customer group
        if ($this->customer_group_id && !$customer->groups()->where('customer_group_id', $this->customer_group_id)->exists()) {
            return false;
        }

        return true;
    }

    public function calculateDiscount(array $cartItems, float $subtotal): float
    {
        switch ($this->type) {
            case self::TYPE_PERCENTAGE:
                return min($subtotal * ($this->value / 100), $subtotal); // Never exceed subtotal

            case self::TYPE_FIXED_AMOUNT:
                return min($this->value, $subtotal);

            case self::TYPE_FREE_SHIPPING:
                return 0; // Handled separately in shipping calculation

            case self::TYPE_BUY_X_GET_Y:
                return $this->calculateBuyXGetYDiscount($cartItems);

            default:
                return 0;
        }
    }

    protected function calculateBuyXGetYDiscount(array $cartItems): float
    {
        // Implementation for Buy X Get Y logic
        $buyQuantity = $this->minimum_requirements['buy_quantity'] ?? 1;
        $getQuantity = $this->minimum_requirements['get_quantity'] ?? 1;
        $getDiscount = $this->value; // percentage or fixed amount

        $eligibleItems = collect($cartItems)->filter(function ($item) {
            return in_array($item['product_id'], $this->entitled_product_ids ?? []);
        });

        $totalDiscount = 0;
        foreach ($eligibleItems as $item) {
            $sets = intval($item['quantity'] / $buyQuantity);
            $freeItems = $sets * $getQuantity;
            $itemDiscount = $freeItems * $item['price'] * ($getDiscount / 100);
            $totalDiscount += $itemDiscount;
        }

        return $totalDiscount;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                    ->where(function ($q) {
                        $q->whereNull('starts_at')
                          ->orWhere('starts_at', '<=', now());
                    })
                    ->where(function ($q) {
                        $q->whereNull('ends_at')
                          ->orWhere('ends_at', '>=', now());
                    });
    }

    public function scopeByCode($query, string $code)
    {
        return $query->where('code', $code);
    }
}