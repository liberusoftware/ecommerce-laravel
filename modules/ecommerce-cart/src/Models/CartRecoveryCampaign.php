<?php

namespace Liberu\Ecommerce\Cart\Models;

use Liberu\Ecommerce\Cart\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CartRecoveryCampaign extends Model
{
    use HasFactory, IsTenantModel;

    protected $fillable = [
        'name',
        'description',
        'trigger_type',
        'delay_minutes',
        'email_subject',
        'email_body',
        'sms_body',
        'include_discount',
        'discount_type',
        'discount_value',
        'is_active',
        'priority',
        'conditions',
    ];

    protected $casts = [
        'delay_minutes' => 'integer',
        'include_discount' => 'boolean',
        'discount_value' => 'decimal:2',
        'is_active' => 'boolean',
        'priority' => 'integer',
        'conditions' => 'array',
    ];

    public function attempts(): HasMany
    {
        return $this->hasMany(CartRecoveryAttempt::class, 'campaign_id');
    }

    /**
     * Check if campaign conditions are met
     */
    public function meetsConditions(AbandonedCart $cart): bool
    {
        if (empty($this->conditions)) {
            return true;
        }

        foreach ($this->conditions as $condition) {
            $field = $condition['field'] ?? null;
            $operator = $condition['operator'] ?? '=';
            $value = $condition['value'] ?? null;

            $met = match($field) {
                'cart_value' => $this->compareValues($cart->total, $operator, $value),
                'item_count' => $this->compareValues($cart->items->count(), $operator, $value),
                default => true
            };

            if (!$met) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compare values based on operator
     */
    protected function compareValues($actual, string $operator, $expected): bool
    {
        return match($operator) {
            '=' => $actual == $expected,
            '!=' => $actual != $expected,
            '>' => $actual > $expected,
            '>=' => $actual >= $expected,
            '<' => $actual < $expected,
            '<=' => $actual <= $expected,
            default => false
        };
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('priority', 'desc');
    }
}
