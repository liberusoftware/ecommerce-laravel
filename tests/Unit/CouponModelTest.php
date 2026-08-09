<?php

namespace Tests\Unit;

use App\Models\Coupon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CouponModelTest extends TestCase
{
    use RefreshDatabase;

    private function makeCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'code' => 'TESTCODE',
            'type' => 'percentage',
            'value' => 10,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addDay(),
            'max_uses' => null,
            'min_purchase_amount' => null,
        ], $overrides));
    }

    /**
     * Insert $count orders linked to a coupon by code (the real linkage column)
     * and to the coupon's own store, which is how the application writes them.
     *
     * The store matters now that codes are unique per store rather than per
     * installation: the code alone names one coupon per merchant, so the
     * relation carries the store too. An order with no store belongs to no
     * merchant and counts against nobody's `max_uses`.
     */
    private function seedOrders(Coupon $coupon, int $count): void
    {
        $customerId = DB::table('customers')->insertGetId([
            'first_name' => 'Test',
            'last_name' => 'Buyer',
            'email' => 'buyer@example.com',
            'phone_number' => 5551234,
            'address' => '1 Main St',
            'city' => 'Town',
            'state' => 'CA',
            'postal_code' => '00000',
        ]);

        for ($i = 0; $i < $count; $i++) {
            DB::table('orders')->insert([
                'customer_id' => $customerId,
                'store_id' => $coupon->store_id,
                'order_date' => now()->toDateString(),
                'total_amount' => 100,
                'payment_status' => 'paid',
                'shipping_status' => 'pending',
                'coupon_code' => $coupon->code,
            ]);
        }
    }

    public function test_is_valid_returns_true_for_active_coupon(): void
    {
        $coupon = $this->makeCoupon();

        $this->assertTrue($coupon->isValid());
    }

    public function test_is_valid_returns_false_before_valid_from(): void
    {
        $coupon = $this->makeCoupon([
            'valid_from' => now()->addDay(),
            'valid_until' => now()->addWeek(),
        ]);

        $this->assertFalse($coupon->isValid());
    }

    public function test_is_valid_returns_false_after_valid_until(): void
    {
        $coupon = $this->makeCoupon([
            'valid_from' => now()->subWeek(),
            'valid_until' => now()->subDay(),
        ]);

        $this->assertFalse($coupon->isValid());
    }

    public function test_coupon_value_cast_to_float(): void
    {
        $coupon = $this->makeCoupon(['value' => 25]);

        $this->assertIsFloat($coupon->value);
        $this->assertEquals(25.0, $coupon->value);
    }

    public function test_coupon_valid_until_cast_to_datetime(): void
    {
        $coupon = $this->makeCoupon();

        $this->assertInstanceOf(\Carbon\Carbon::class, $coupon->valid_until);
    }

    public function test_is_valid_handles_usage_limit_without_error(): void
    {
        // Coupon with a usage limit and no orders yet must be valid, not fatal.
        // The orders() relation must resolve against the real linkage column.
        $coupon = $this->makeCoupon(['max_uses' => 5]);

        $this->assertTrue($coupon->isValid());
    }

    public function test_is_valid_false_when_usage_limit_reached(): void
    {
        $coupon = $this->makeCoupon(['code' => 'LIMIT2', 'max_uses' => 2]);
        $this->seedOrders($coupon, 2);

        $this->assertSame(2, $coupon->orders()->count());
        $this->assertFalse($coupon->isValid());
    }

    public function test_is_valid_true_below_usage_limit(): void
    {
        $coupon = $this->makeCoupon(['code' => 'LIMIT3', 'max_uses' => 3]);
        $this->seedOrders($coupon, 2);

        $this->assertTrue($coupon->isValid());
    }

    public function test_is_valid_true_with_null_dates(): void
    {
        // Null valid_from/valid_until means unbounded (always valid), not expired.
        $coupon = $this->makeCoupon([
            'valid_from' => null,
            'valid_until' => null,
        ]);

        $this->assertTrue($coupon->isValid());
    }
}
