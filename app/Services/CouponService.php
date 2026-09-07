<?php

namespace App\Services;

use App\Models\Coupon;

class CouponService
{
    /**
     * The one refusal. Every way of failing says exactly this.
     *
     * `/cart/apply-coupon` is unauthenticated and the route already names the
     * threat — "distinguishable valid/invalid responses make this
     * brute-forceable to enumerate discount codes" — but the mitigation chosen
     * was a per-IP throttle while the responses stayed distinguishable. They
     * were three: a code that does not exist, a code that exists but is spent,
     * and a code that exists, is live, and whose *minimum spend was printed to
     * the caller*. The last is the worst of the three: it hands a stranger the
     * terms of a coupon they do not hold.
     *
     * A throttle limits how fast an oracle can be asked. It does not stop it
     * being an oracle, and merchant codes are guessable by construction —
     * SUMMER10, WELCOME, BLACKFRIDAY are the codes people actually issue, and
     * they fall well inside 10 guesses a minute.
     *
     * This is the rule the gift-card module already settled for the same
     * reason: enumeration is closed by making every wrong answer the same
     * answer. It costs a shopper holding a real code the "spend £8 more"
     * prompt, and that is the trade — the prompt cannot be given to the holder
     * without also giving it to the guesser, because the server cannot tell
     * them apart.
     *
     * Response *timing* still differs slightly, since a found coupon runs a
     * usage count and a missing one does not. That is a far weaker oracle than
     * distinct text over a throttled route, and closing it means doing the
     * count either way.
     */
    private const REFUSED = 'That code cannot be applied to this order.';

    /**
     * Validate and apply a coupon to a cart
     */
    public function validateAndApplyCoupon(string $couponCode, float $subtotal): array
    {
        $coupon = Coupon::where('code', $couponCode)->first();

        if (! $coupon || ! $coupon->isValid()) {
            return $this->refuse();
        }

        if ($coupon->min_purchase_amount && $subtotal < $coupon->min_purchase_amount) {
            return $this->refuse();
        }

        $discount = $this->calculateDiscount($coupon, $subtotal);

        return [
            'valid' => true,
            'coupon' => $coupon,
            'discount' => $discount,
            'message' => sprintf('Coupon applied! You saved $%.2f', $discount),
        ];
    }

    /** @return array{valid: false, error: string, discount: int} */
    private function refuse(): array
    {
        return [
            'valid' => false,
            'error' => self::REFUSED,
            'discount' => 0,
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
