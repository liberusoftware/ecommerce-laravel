<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class LoyaltyReward extends Model
{
    use HasFactory;

    protected $fillable = [
        'loyalty_program_id',
        'name',
        'description',
        'reward_type',
        'discount_value',
        'free_product_id',
        'points_cost',
        'max_redemptions',
        'stock_quantity',
        'is_active',
        'available_from',
        'available_until',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'points_cost' => 'integer',
        'max_redemptions' => 'integer',
        'stock_quantity' => 'integer',
        'is_active' => 'boolean',
        'available_from' => 'datetime',
        'available_until' => 'datetime',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'loyalty_program_id');
    }

    public function freeProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'free_product_id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(LoyaltyRewardRedemption::class);
    }

    /**
     * Check if reward is available
     */
    public function isAvailable(?int $userId = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->available_from && now()->lt($this->available_from)) {
            return false;
        }

        if ($this->available_until && now()->gt($this->available_until)) {
            return false;
        }

        if ($this->stock_quantity !== null && $this->stock_quantity <= 0) {
            return false;
        }

        if ($userId && $this->max_redemptions) {
            $userRedemptions = $this->redemptions()
                ->where('user_id', $userId)
                ->where('status', '!=', 'cancelled')
                ->count();

            if ($userRedemptions >= $this->max_redemptions) {
                return false;
            }
        }

        return true;
    }

    /**
     * Redeem reward for user
     */
    public function redeem(int $userId, ?int $orderId = null): ?LoyaltyRewardRedemption
    {
        // Four writes across two tables. Unwrapped and unlocked, `isAvailable()`
        // is a check-then-act on both limits it enforces: two callers racing for
        // the last unit both read `stock_quantity = 1`, both pay, both decrement,
        // and the stock lands at -1 with two redemption rows. `max_redemptions`
        // has the same window — it is a `count()` compared to a limit with
        // nothing holding the rows still in between.
        //
        // Locking the reward row serialises callers competing for the same
        // reward and leaves callers of different rewards alone, which is the
        // narrowest lock that closes both windows.
        return DB::transaction(function () use ($userId, $orderId) {
            $reward = static::query()->whereKey($this->getKey())->lockForUpdate()->first();

            if ($reward === null || ! $reward->isAvailable($userId)) {
                return null;
            }

            $loyaltyPoints = LoyaltyPoints::where('user_id', $userId)
                ->where('loyalty_program_id', $reward->loyalty_program_id)
                ->first();

            // The debit reports its own outcome under its own lock, so the
            // balance is not checked twice from out here. Dropping this boolean
            // discarded the one answer the caller's own check could not give.
            if (! $loyaltyPoints || ! $loyaltyPoints->redeemPoints($reward->points_cost, "Redeemed: {$reward->name}", $orderId)) {
                return null;
            }

            if ($reward->stock_quantity !== null) {
                $reward->decrement('stock_quantity');
                $this->refresh();
            }

            return $reward->redemptions()->create([
                'user_id' => $userId,
                'order_id' => $orderId,
                'points_spent' => $reward->points_cost,
                'status' => 'pending',
            ]);
        });
    }
}
