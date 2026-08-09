<?php

namespace App\Services;

use App\Models\Coupon;

class CouponService
{
    /**
     * Validate and apply a coupon to a cart
     */
    public function validateAndApplyCoupon(string $couponCode, float $subtotal): array
    {
        $coupon = Coupon::where('code', $couponCode)->first();

        if (! $coupon) {
            return [
                'valid' => false,
                'error' => 'Invalid coupon code.',
                'discount' => 0,
            ];
        }

        if (! $coupon->isValid()) {
            return [
                'valid' => false,
                'error' => 'This coupon has expired or reached its usage limit.',
                'discount' => 0,
            ];
        }

        if ($coupon->min_purchase_amount && $subtotal < $coupon->min_purchase_amount) {
            return [
                'valid' => false,
                'error' => sprintf(
                    'Minimum purchase amount of $%.2f required to use this coupon.',
                    $coupon->min_purchase_amount
                ),
                'discount' => 0,
            ];
        }

        $discount = $this->calculateDiscount($coupon, $subtotal);

        return [
            'valid' => true,
            'coupon' => $coupon,
            'discount' => $discount,
            'message' => sprintf('Coupon applied! You saved $%.2f', $discount),
        ];
    }

    /**
     * Calculate discount amount based on coupon type
     */
    public function calculateDiscount(Coupon $coupon, float $subtotal): float
    {
        if ($coupon->type === 'percentage') {
            $discount = min(($subtotal * $coupon->value) / 100, $subtotal); // Never exceed subtotal
        } elseif ($coupon->type === 'fixed') {
            $discount = min($coupon->value, $subtotal); // Don't exceed subtotal
        } else {
            $discount = 0;
        }

        return round($discount, 2);
    }

    /**
     * Get coupon by code
     */
    public function getCouponByCode(string $code): ?Coupon
    {
        return Coupon::where('code', $code)->first();
    }

    /**
     * Check if coupon can be applied to cart
     */
    public function canApplyCoupon(Coupon $coupon, float $subtotal): bool
    {
        if (! $coupon->isValid()) {
            return false;
        }

        if ($coupon->min_purchase_amount && $subtotal < $coupon->min_purchase_amount) {
            return false;
        }

        return true;
    }

    /**
     * Get all active coupons
     */
    public function getActiveCoupons()
    {
        $now = now();

        return Coupon::where('valid_from', '<=', $now)
            ->where('valid_until', '>=', $now)
            // Joined on the store as well as the code: the global scope reaches
            // `coupons` and not the table joined to it, so on code alone this
            // counts every merchant's use of the same code against one
            // merchant's `max_uses`.
            ->leftJoin('orders', function ($join) {
                $join->on('coupons.code', '=', 'orders.coupon_code')
                    ->on('coupons.store_id', '=', 'orders.store_id');
            })
            ->select('coupons.*')
            ->selectRaw('COUNT(orders.id) as usage_count')
            ->groupBy('coupons.id')
            ->havingRaw('coupons.max_uses IS NULL OR COUNT(orders.id) < coupons.max_uses')
            ->get();
    }
}
