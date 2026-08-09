<?php

namespace Tests\Unit;

use App\Models\Coupon;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CouponServiceTest extends TestCase
{
    use RefreshDatabase;

    private CouponService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CouponService;
    }

    public function test_invalid_coupon_code_returns_error(): void
    {
        $result = $this->service->validateAndApplyCoupon('NONEXISTENT', 100.0);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Invalid', $result['error']);
        $this->assertEquals(0, $result['discount']);
    }

    public function test_expired_coupon_returns_error(): void
    {
        Coupon::create([
            'code' => 'EXPIRED10',
            'type' => 'percentage',
            'value' => 10,
            'valid_from' => now()->subDays(30),
            'valid_until' => now()->subDay(),
            'max_uses' => null,
            'min_purchase_amount' => null,
        ]);

        $result = $this->service->validateAndApplyCoupon('EXPIRED10', 100.0);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('expired', $result['error']);
    }

    public function test_percentage_coupon_calculates_correct_discount(): void
    {
        Coupon::create([
            'code' => 'SAVE20',
            'type' => 'percentage',
            'value' => 20,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addDay(),
            'max_uses' => null,
            'min_purchase_amount' => null,
        ]);

        $result = $this->service->validateAndApplyCoupon('SAVE20', 100.0);

        $this->assertTrue($result['valid']);
        $this->assertEquals(20.0, $result['discount']);
    }

    public function test_fixed_coupon_calculates_correct_discount(): void
    {
        Coupon::create([
            'code' => 'FLAT5',
            'type' => 'fixed',
            'value' => 5,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addDay(),
            'max_uses' => null,
            'min_purchase_amount' => null,
        ]);

        $result = $this->service->validateAndApplyCoupon('FLAT5', 100.0);

        $this->assertTrue($result['valid']);
        $this->assertEquals(5.0, $result['discount']);
    }

    public function test_coupon_requires_minimum_purchase(): void
    {
        Coupon::create([
            'code' => 'MINBUY',
            'type' => 'percentage',
            'value' => 10,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addDay(),
            'max_uses' => null,
            'min_purchase_amount' => 50.0,
        ]);

        $result = $this->service->validateAndApplyCoupon('MINBUY', 30.0);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Minimum', $result['error']);
    }

    public function test_coupon_above_minimum_purchase_succeeds(): void
    {
        Coupon::create([
            'code' => 'MINBUY2',
            'type' => 'percentage',
            'value' => 10,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addDay(),
            'max_uses' => null,
            'min_purchase_amount' => 50.0,
        ]);

        $result = $this->service->validateAndApplyCoupon('MINBUY2', 100.0);

        $this->assertTrue($result['valid']);
        $this->assertEquals(10.0, $result['discount']);
    }

    public function test_usage_limited_coupon_applies_without_error(): void
    {
        // max_uses set + no orders: must apply cleanly (relation must resolve, not fatal).
        Coupon::create([
            'code' => 'LIMITED',
            'type' => 'percentage',
            'value' => 10,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addDay(),
            'max_uses' => 10,
            'min_purchase_amount' => null,
        ]);

        $result = $this->service->validateAndApplyCoupon('LIMITED', 100.0);

        $this->assertTrue($result['valid']);
        $this->assertEquals(10.0, $result['discount']);
    }

    public function test_usage_limit_reached_returns_error(): void
    {
        $coupon = Coupon::create([
            'code' => 'USEDUP',
            'type' => 'percentage',
            'value' => 10,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addDay(),
            'max_uses' => 1,
            'min_purchase_amount' => null,
        ]);

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
        // Stamped with the coupon's store, as the application writes it. Codes
        // are unique per store, so an order that names no store is nobody's use
        // of nobody's coupon.
        DB::table('orders')->insert([
            'customer_id' => $customerId,
            'store_id' => $coupon->store_id,
            'order_date' => now()->toDateString(),
            'total_amount' => 100,
            'payment_status' => 'paid',
            'shipping_status' => 'pending',
            'coupon_code' => 'USEDUP',
        ]);

        $result = $this->service->validateAndApplyCoupon('USEDUP', 100.0);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('usage limit', $result['error']);
        $this->assertEquals(0, $result['discount']);
    }

    public function test_percentage_discount_capped_at_subtotal(): void
    {
        // A percentage over 100 must never discount more than the cart subtotal.
        Coupon::create([
            'code' => 'OVER100',
            'type' => 'percentage',
            'value' => 150,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addDay(),
            'max_uses' => null,
            'min_purchase_amount' => null,
        ]);

        $result = $this->service->validateAndApplyCoupon('OVER100', 80.0);

        $this->assertTrue($result['valid']);
        $this->assertEquals(80.0, $result['discount']);
    }
}
