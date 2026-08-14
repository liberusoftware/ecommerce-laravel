<?php

namespace Tests\Unit;

use App\Models\CustomerMetric;
use App\Models\CustomerSegment;
use App\Models\Order;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerSegmentTest extends TestCase
{
    use RefreshDatabase;

    private function makeSegment(array $conditions, string $matchType): CustomerSegment
    {
        return CustomerSegment::create([
            'name' => 'seg',
            'conditions' => $conditions,
            'match_type' => $matchType,
            'is_active' => true,
        ]);
    }

    /**
     * A shopper, with the Customer file that is the only thing tying a user to a
     * merchant. The helpers below all create one now, because segment membership
     * is drawn from customer files rather than from `users` — a user who is
     * nobody's customer has no relationship with any merchant to be segmented on.
     */
    private function shopper(): User
    {
        $user = User::factory()->create();
        $user->getOrCreateCustomer();

        return $user;
    }

    private function userWithLtv(float $ltv): User
    {
        $user = $this->shopper();
        CustomerMetric::create([
            'user_id' => $user->id,
            'lifetime_value' => $ltv,
            'total_orders' => 0,
        ]);

        return $user;
    }

    private function userWithOrderCount(int $count): User
    {
        $user = $this->shopper();
        $customer = $user->customer;

        for ($i = 0; $i < $count; $i++) {
            Order::create([
                'user_id' => $user->id,
                'customer_id' => $customer->id,
                'order_date' => now()->toDateString(),
                'total_amount' => 100,
                'payment_status' => 'paid',
                'shipping_status' => 'pending',
                'status' => 'paid',
            ]);
        }

        return $user;
    }

    public function test_customer_segment_can_be_created()
    {
        $segment = CustomerSegment::factory()->create([
            'name' => 'VIP Customers',
            'conditions' => [
                [
                    'field' => 'lifetime_value',
                    'operator' => '>=',
                    'value' => 1000,
                ],
            ],
            'match_type' => 'all',
        ]);

        $this->assertDatabaseHas('customer_segments', [
            'name' => 'VIP Customers',
            'match_type' => 'all',
        ]);
    }

    public function test_segment_can_have_members()
    {
        $segment = CustomerSegment::factory()->create();
        $user = User::factory()->create();

        $segment->members()->attach($user->id);

        $this->assertTrue($segment->members->contains($user));
    }

    public function test_active_scope_filters_active_segments()
    {
        CustomerSegment::factory()->create(['is_active' => true]);
        CustomerSegment::factory()->create(['is_active' => false]);

        $activeSegments = CustomerSegment::active()->get();

        $this->assertEquals(1, $activeSegments->count());
    }

    public function test_match_type_any_matches_users_satisfying_either_condition(): void
    {
        $rich = $this->userWithLtv(2000);
        $poor = $this->userWithLtv(5);
        $mid = $this->userWithLtv(500);

        $segment = $this->makeSegment([
            ['field' => 'lifetime_value', 'operator' => '>=', 'value' => 1000],
            ['field' => 'lifetime_value', 'operator' => '<=', 'value' => 10],
        ], 'any');

        $segment->calculateMembers();

        $ids = $segment->members()->pluck('users.id')->all();
        $this->assertContains($rich->id, $ids, 'lifetime_value >= 1000 should match under "any"');
        $this->assertContains($poor->id, $ids, 'lifetime_value <= 10 should match under "any"');
        $this->assertNotContains($mid->id, $ids, 'user matching neither condition must be excluded');
        $this->assertEquals(2, $segment->customer_count);
    }

    public function test_match_type_all_requires_every_condition(): void
    {
        $mid = $this->userWithLtv(500);
        $rich = $this->userWithLtv(2000);
        $poor = $this->userWithLtv(5);

        $segment = $this->makeSegment([
            ['field' => 'lifetime_value', 'operator' => '>=', 'value' => 100],
            ['field' => 'lifetime_value', 'operator' => '<=', 'value' => 1000],
        ], 'all');

        $segment->calculateMembers();

        $ids = $segment->members()->pluck('users.id')->all();
        $this->assertContains($mid->id, $ids, 'user in [100,1000] should match under "all"');
        $this->assertNotContains($rich->id, $ids, 'user above upper bound must be excluded');
        $this->assertNotContains($poor->id, $ids, 'user below lower bound must be excluded');
    }

    public function test_total_orders_condition_matches_at_boundary_without_fataling(): void
    {
        $twoOrders = $this->userWithOrderCount(2);
        $oneOrder = $this->userWithOrderCount(1);

        $matched = function (string $operator, int $value): array {
            $segment = $this->makeSegment(
                [['field' => 'total_orders', 'operator' => $operator, 'value' => $value]],
                'all'
            );
            $segment->calculateMembers();

            return $segment->members()->pluck('users.id')->all();
        };

        // Boundary: user with exactly 2 orders
        $this->assertContains($twoOrders->id, $matched('>=', 2));
        $this->assertNotContains($twoOrders->id, $matched('>=', 3));
        $this->assertContains($twoOrders->id, $matched('=', 2));
        $this->assertContains($twoOrders->id, $matched('>', 1));
        $this->assertNotContains($twoOrders->id, $matched('>', 2));

        // The single-order user must never match a >= 2 threshold
        $this->assertNotContains($oneOrder->id, $matched('>=', 2));
    }

    private function userWithOrdersOn(array $datetimes): User
    {
        $user = $this->shopper();
        foreach ($datetimes as $dt) {
            $order = Order::create([
                'user_id' => $user->id,
                'customer_email' => uniqid().'@x.com',
                'total_amount' => 50,
                'status' => 'paid',
            ]);
            // Raw update so we control created_at without the timestamp helpers.
            Order::whereKey($order->id)->update(['created_at' => $dt]);
        }

        return $user;
    }

    public function test_last_order_date_matches_by_most_recent_order_not_any_order(): void
    {
        $userOld = $this->userWithOrdersOn(['2024-01-01 00:00:00']);
        $userMixed = $this->userWithOrdersOn(['2024-01-01 00:00:00', '2026-01-01 00:00:00']);

        $segment = $this->makeSegment(
            [['field' => 'last_order_date', 'operator' => '<=', 'value' => '2025-01-01 00:00:00']],
            'all'
        );
        $segment->calculateMembers();
        $ids = $segment->members()->pluck('users.id')->all();

        $this->assertContains($userOld->id, $ids, 'user whose last order is old should match');
        $this->assertNotContains(
            $userMixed->id,
            $ids,
            'user whose MOST RECENT order is recent must not match — an older order should not count'
        );
    }

    public function test_last_order_date_ge_matches_recent_buyers_only(): void
    {
        $recent = $this->userWithOrdersOn(['2026-01-01 00:00:00']);
        $old = $this->userWithOrdersOn(['2024-01-01 00:00:00']);

        $segment = $this->makeSegment(
            [['field' => 'last_order_date', 'operator' => '>=', 'value' => '2025-01-01 00:00:00']],
            'all'
        );
        $segment->calculateMembers();
        $ids = $segment->members()->pluck('users.id')->all();

        $this->assertContains($recent->id, $ids);
        $this->assertNotContains($old->id, $ids);
    }

    /**
     * The two tests below pin the tenant boundary on segment recalculation.
     *
     * The second one is the one that matters, and it is the reason the tenant
     * predicate cannot simply be added alongside the conditions. Under
     * `match_type = 'any'` the conditions are OR-ed, and AND binds tighter than
     * OR — so a top-level `WHERE (customer is this merchant's) OR (condition)`
     * leaves the right-hand side qualified by nothing and selects every user on
     * the deployment. The constraint has to sit outside a group the conditions
     * are inside. A test written only against `match_type = 'all'` passes either
     * way, which is how this shape keeps surviving test files.
     */
    public function test_recalculation_ignores_another_merchants_shoppers(): void
    {
        $ours = Team::factory()->create();
        $theirs = Team::factory()->create();

        $ourShopper = $this->shopperOf($ours, ltv: 2000);
        $theirShopper = $this->shopperOf($theirs, ltv: 2000);

        $segment = $this->makeSegment(
            [['field' => 'lifetime_value', 'operator' => '>=', 'value' => 1000]],
            'all'
        );
        $segment->forceFill(['team_id' => $ours->id])->save();

        $segment->calculateMembers();

        $ids = $segment->members()->pluck('users.id')->all();
        $this->assertContains($ourShopper->id, $ids);
        $this->assertNotContains(
            $theirShopper->id,
            $ids,
            'a segment must never draw members from another merchant'
        );
        $this->assertEquals(1, $segment->fresh()->customer_count);
    }

    public function test_match_type_any_does_not_widen_past_the_merchant(): void
    {
        $ours = Team::factory()->create();
        $theirs = Team::factory()->create();

        $ourShopper = $this->shopperOf($ours, ltv: 2000);
        $theirShopper = $this->shopperOf($theirs, ltv: 2000);

        $segment = $this->makeSegment([
            ['field' => 'lifetime_value', 'operator' => '>=', 'value' => 1000],
            ['field' => 'lifetime_value', 'operator' => '<=', 'value' => 10],
        ], 'any');
        $segment->forceFill(['team_id' => $ours->id])->save();

        $segment->calculateMembers();

        $ids = $segment->members()->pluck('users.id')->all();
        $this->assertContains($ourShopper->id, $ids);
        $this->assertNotContains(
            $theirShopper->id,
            $ids,
            'an OR-ed condition must not escape the tenant predicate'
        );
    }

    private function shopperOf(Team $team, float $ltv): User
    {
        $user = User::factory()->create();
        $customer = $user->getOrCreateCustomer();
        $customer->forceFill(['team_id' => $team->id])->save();

        CustomerMetric::create([
            'user_id' => $user->id,
            'lifetime_value' => $ltv,
            'total_orders' => 0,
        ]);

        return $user;
    }

    public function test_unknown_condition_field_matches_no_one(): void
    {
        $this->userWithLtv(500); // a user who would match a fail-open (empty) filter

        $segment = $this->makeSegment(
            [['field' => 'bogus_field', 'operator' => '=', 'value' => 1]],
            'all'
        );
        $segment->calculateMembers();

        $this->assertEquals(0, $segment->customer_count);
    }
}
