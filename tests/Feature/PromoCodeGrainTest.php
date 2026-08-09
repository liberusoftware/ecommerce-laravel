<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelDomain;
use App\Models\Coupon;
use App\Models\Discount;
use App\Models\Store;
use App\Models\Team;
use App\Services\ChannelResolver;
use App\Services\CouponService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A promo code belongs to the merchant who issued it.
 *
 * `coupons.code` and `discounts.code` were unique across the whole
 * installation, so the first merchant to issue `SUMMER10` took that code from
 * everybody else — enforced by a database error on a form with no way to
 * explain it. Wave 1.5 fixed the *reads*: a code entered on one storefront
 * cannot find another merchant's coupon. This is the index grain it left behind.
 *
 * Uniqueness is only half of it. Once two merchants hold the same code, every
 * query that identifies a coupon *by code alone* reaches across the boundary —
 * and `max_uses` is derived from exactly such a query.
 */
class PromoCodeGrainTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_merchants_can_issue_the_same_code(): void
    {
        $this->couponFor($this->store(), ['code' => 'SUMMER10']);
        $this->couponFor($this->store(), ['code' => 'SUMMER10']);

        $this->assertSame(2, Coupon::query()->where('code', 'SUMMER10')->count());
    }

    public function test_one_merchant_cannot_issue_the_same_code_twice(): void
    {
        $store = $this->store();

        $this->couponFor($store, ['code' => 'SUMMER10']);

        // Within one store the constraint is real and wanted: two live coupons
        // with one code means the checkout picks whichever the database returns
        // first, and the merchant cannot tell which discount they gave away.
        $this->expectException(QueryException::class);

        $this->couponFor($store, ['code' => 'SUMMER10']);
    }

    public function test_a_storefront_finds_its_own_coupon_and_not_the_other_merchants(): void
    {
        $mine = $this->store();
        $theirs = $this->store();

        $myCoupon = $this->couponFor($mine, ['code' => 'SUMMER10', 'value' => 10]);
        $this->couponFor($theirs, ['code' => 'SUMMER10', 'value' => 90]);

        $this->onHost($this->hostFor($mine), function () use ($myCoupon) {
            $found = app(CouponService::class)->getCouponByCode('SUMMER10');

            $this->assertNotNull($found, 'The storefront could not find its own coupon.');
            $this->assertSame($myCoupon->id, $found->id, 'The storefront was given another merchant\'s coupon.');
        });
    }

    public function test_usage_counts_only_the_orders_of_the_store_that_issued_the_coupon(): void
    {
        $mine = $this->store();
        $theirs = $this->store();

        $myCoupon = $this->couponFor($mine, ['code' => 'SUMMER10', 'max_uses' => 2]);

        // One use of my coupon, and two of theirs — same code, different shop.
        $this->seedOrders($mine, 'SUMMER10', 1);
        $this->seedOrders($theirs, 'SUMMER10', 2);

        $this->assertSame(1, $myCoupon->orders()->count(), 'Another merchant\'s orders were counted as mine.');
        $this->assertTrue($myCoupon->isValid(), 'The coupon was spent by a customer who never shopped here.');
    }

    public function test_the_active_coupon_listing_counts_uses_per_store(): void
    {
        $mine = $this->store();
        $theirs = $this->store();

        $this->couponFor($mine, [
            'code' => 'SUMMER10',
            'max_uses' => 2,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addDay(),
        ]);

        $this->seedOrders($theirs, 'SUMMER10', 5);

        // The listing joins orders to coupons by code, and the store scope
        // reaches `coupons` but not the table joined to it.
        $active = $this->onHost($this->hostFor($mine), fn () => app(CouponService::class)->getActiveCoupons());

        $this->assertCount(1, $active, 'My coupon was withdrawn by another merchant\'s order volume.');
    }

    public function test_two_teams_can_issue_the_same_discount_code(): void
    {
        $this->discountFor(Team::factory()->create(), 'SPRING');
        $this->discountFor(Team::factory()->create(), 'SPRING');

        $this->assertSame(2, Discount::query()->where('code', 'SPRING')->count());
    }

    public function test_one_team_cannot_issue_the_same_discount_code_twice(): void
    {
        $team = Team::factory()->create();

        $this->discountFor($team, 'SPRING');

        $this->expectException(QueryException::class);

        $this->discountFor($team, 'SPRING');
    }

    private function store(): Store
    {
        return Store::factory()->create(['team_id' => Team::factory()->create()->id]);
    }

    private function hostFor(Store $store): string
    {
        $channel = Channel::factory()->create(['store_id' => $store->id]);

        return ChannelDomain::factory()->primary()->create(['channel_id' => $channel->id])->host;
    }

    /**
     * `store_id` is not fillable — the trait's hook is its only writer — so a
     * fixture that needs a specific store sets the attribute directly, which is
     * what the hook itself does.
     */
    private function couponFor(Store $store, array $attributes): Coupon
    {
        $coupon = new Coupon(array_merge([
            'type' => 'percentage',
            'value' => 10,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addDay(),
        ], $attributes));

        $coupon->store_id = $store->id;
        $coupon->save();

        return $coupon;
    }

    private function discountFor(Team $team, string $code): Discount
    {
        $discount = new Discount([
            'title' => 'Spring sale',
            'code' => $code,
            'type' => Discount::TYPE_PERCENTAGE,
            'value' => 10,
            'target_type' => Discount::TARGET_ORDER,
        ]);

        $discount->team_id = $team->id;
        $discount->save();

        return $discount;
    }

    /** Orders link to coupons by code, so the linkage is written the way the app writes it. */
    private function seedOrders(Store $store, string $code, int $count): void
    {
        $customerId = DB::table('customers')->insertGetId([
            'first_name' => 'Test',
            'last_name' => 'Buyer',
            'email' => 'buyer'.$store->id.'@example.com',
            'phone_number' => 5551234,
            'address' => '1 Main St',
            'city' => 'Town',
            'state' => 'CA',
            'postal_code' => '00000',
        ]);

        for ($i = 0; $i < $count; $i++) {
            DB::table('orders')->insert([
                'customer_id' => $customerId,
                'store_id' => $store->id,
                'order_date' => now()->toDateString(),
                'total_amount' => 100,
                'payment_status' => 'paid',
                'shipping_status' => 'pending',
                'coupon_code' => $code,
            ]);
        }
    }

    /**
     * Run a callback as though the request had arrived on a host — through the
     * resolver and the request attribute the middleware uses, so this exercises
     * the path a real request takes rather than a stub of it.
     */
    private function onHost(string $host, callable $callback): mixed
    {
        $channel = app(ChannelResolver::class)->resolve($host);

        $this->assertNotNull($channel, "No channel resolves {$host} — the fixture is wrong.");

        request()->attributes->set(ChannelResolver::ATTRIBUTE, $channel);

        try {
            return $callback();
        } finally {
            request()->attributes->remove(ChannelResolver::ATTRIBUTE);
        }
    }
}
