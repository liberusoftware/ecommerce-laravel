<?php

namespace App\Models;

use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerGroup extends Model
{
    use HasFactory;
    use IsTenantModel;

    protected $fillable = [
        'name',
        'description',
        'discount_percentage',
        'discount_amount',
        'minimum_order_amount',
        'free_shipping_threshold',
        'is_active',
        'conditions',
        'benefits',
    ];

    protected $casts = [
        'discount_percentage' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'minimum_order_amount' => 'decimal:2',
        'free_shipping_threshold' => 'decimal:2',
        'is_active' => 'boolean',
        'conditions' => 'array',
        'benefits' => 'array',
    ];

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_group_memberships')
            ->withPivot(['joined_at', 'expires_at'])
            ->withTimestamps();
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(Discount::class);
    }

    public function addCustomer(Customer $customer, $expiresAt = null): void
    {
        $this->customers()->attach($customer->id, [
            'joined_at' => now(),
            'expires_at' => $expiresAt,
        ]);
    }

    public function removeCustomer(Customer $customer): void
    {
        $this->customers()->detach($customer->id);
    }

    public function hasCustomer(Customer $customer): bool
    {
        return $this->customers()->where('customer_id', $customer->id)->exists();
    }

    /**
     * The one live-membership predicate. Every question about whether a
     * membership is still current asks it here.
     *
     * It was written out three times — here, in `scopeWithActiveMembers()`
     * below, and in `Customer::getActiveGroupsAttribute()` — each time as
     * `->where('expires_at', '>', now())->orWhereNull('expires_at')` with no
     * grouping. `AND` binds tighter than `OR`, so what those three produced was
     *
     *     WHERE customer_id = 12 AND expires_at > now() OR expires_at IS NULL
     *
     * which SQL reads as `(customer_id = 12 AND expires_at > now()) OR
     * (expires_at IS NULL)`. The second half is unqualified by anything: every
     * membership on the deployment that never expires satisfies it, whoever it
     * belongs to. So a customer's "active groups" included strangers' groups,
     * and a group's active-member count counted every other group's members.
     * A never-expiring membership is the normal case, so this was not an edge.
     *
     * The `or` has to live inside its own closure to be parenthesised, and the
     * column has to be qualified because this also runs inside `whereHas`,
     * where a bare `expires_at` resolves against `customers` — a column that is
     * not there.
     */
    public static function constrainToLiveMemberships(Builder|BelongsToMany $query): Builder|BelongsToMany
    {
        return $query->where(function (Builder $query) {
            $query->where('customer_group_memberships.expires_at', '>', now())
                ->orWhereNull('customer_group_memberships.expires_at');
        });
    }

    public function getActiveCustomersCount(): int
    {
        return self::constrainToLiveMemberships($this->customers())->count();
    }

    public function calculateDiscount(float $orderAmount): float
    {
        if (! $this->is_active || $orderAmount < $this->minimum_order_amount) {
            return 0;
        }

        if ($this->discount_percentage > 0) {
            return $orderAmount * ($this->discount_percentage / 100);
        }

        return min($this->discount_amount, $orderAmount);
    }

    public function qualifiesForFreeShipping(float $orderAmount): bool
    {
        return $this->free_shipping_threshold > 0 && $orderAmount >= $this->free_shipping_threshold;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeWithActiveMembers($query)
    {
        return $query->whereHas('customers', function ($query) {
            self::constrainToLiveMemberships($query);
        });
    }
}
