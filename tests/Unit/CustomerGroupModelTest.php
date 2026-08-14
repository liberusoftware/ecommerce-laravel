<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerGroupModelTest extends TestCase
{
    use RefreshDatabase;

    private function makeGroup(array $overrides = []): CustomerGroup
    {
        return CustomerGroup::create(array_merge([
            'name' => 'VIP Customers',
            'discount_percentage' => 10,
            'discount_amount' => 0,
            'minimum_order_amount' => 50,
            'free_shipping_threshold' => 100,
            'is_active' => true,
        ], $overrides));
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();

        return Customer::create([
            'user_id' => $user->id,
            'first_name' => 'Group',
            'last_name' => 'Member',
            'email' => $user->email,
            'phone_number' => '555-1111',
            'address' => '100 Oak St',
            'city' => 'Springfield',
            'state' => 'IL',
            'postal_code' => '62701',
        ]);
    }

    public function test_calculate_discount_with_percentage(): void
    {
        $group = $this->makeGroup(['discount_percentage' => 10, 'minimum_order_amount' => 0]);

        $discount = $group->calculateDiscount(100.00);

        $this->assertEquals(10.0, $discount);
    }

    public function test_calculate_discount_with_flat_amount(): void
    {
        $group = $this->makeGroup([
            'discount_percentage' => 0,
            'discount_amount' => 15,
            'minimum_order_amount' => 0,
        ]);

        $discount = $group->calculateDiscount(100.00);

        $this->assertEquals(15.0, $discount);
    }

    public function test_calculate_discount_returns_zero_below_minimum(): void
    {
        $group = $this->makeGroup(['minimum_order_amount' => 100]);

        $discount = $group->calculateDiscount(50.00);

        $this->assertEquals(0, $discount);
    }

    public function test_calculate_discount_returns_zero_when_inactive(): void
    {
        $group = $this->makeGroup(['is_active' => false, 'minimum_order_amount' => 0]);

        $discount = $group->calculateDiscount(100.00);

        $this->assertEquals(0, $discount);
    }

    public function test_qualifies_for_free_shipping(): void
    {
        $group = $this->makeGroup(['free_shipping_threshold' => 100]);

        $this->assertTrue($group->qualifiesForFreeShipping(150.00));
        $this->assertFalse($group->qualifiesForFreeShipping(50.00));
    }

    public function test_qualifies_for_free_shipping_false_when_no_threshold(): void
    {
        $group = $this->makeGroup(['free_shipping_threshold' => 0]);

        $this->assertFalse($group->qualifiesForFreeShipping(500.00));
    }

    public function test_add_and_remove_customer(): void
    {
        $group = $this->makeGroup();
        $customer = $this->makeCustomer();

        $group->addCustomer($customer);
        $this->assertTrue($group->hasCustomer($customer));

        $group->removeCustomer($customer);
        $this->assertFalse($group->hasCustomer($customer));
    }

    public function test_active_scope(): void
    {
        $active = $this->makeGroup(['name' => 'Active Group', 'is_active' => true]);
        $inactive = $this->makeGroup(['name' => 'Inactive Group', 'is_active' => false]);

        $results = CustomerGroup::active()->pluck('id');

        $this->assertContains($active->id, $results);
        $this->assertNotContains($inactive->id, $results);
    }

    /**
     * The four tests below pin the ungrouped `or`.
     *
     * Two of them fail against the old predicate and two do not, and the split
     * is the lesson: the ones that catch it are the ones that put a second
     * customer's never-expiring membership in the database and then assert it
     * is *absent*. A test that sets up one customer and asks only "is my group
     * here" passes either way, which is why this survived a test file.
     */
    public function test_active_groups_does_not_include_another_customers_groups(): void
    {
        $mine = $this->makeGroup(['name' => 'Mine']);
        $theirs = $this->makeGroup(['name' => 'Theirs']);

        $me = $this->makeCustomer();
        $them = $this->makeCustomer();

        // Both memberships never expire, which is the ordinary case.
        $mine->addCustomer($me);
        $theirs->addCustomer($them);

        $names = $me->active_groups->pluck('name');

        $this->assertContains('Mine', $names);
        $this->assertNotContains('Theirs', $names);
    }

    public function test_active_groups_excludes_an_expired_membership(): void
    {
        $group = $this->makeGroup(['name' => 'Lapsed']);
        $customer = $this->makeCustomer();

        $group->addCustomer($customer, now()->subDay());

        $this->assertNotContains('Lapsed', $customer->active_groups->pluck('name'));
    }

    public function test_active_member_count_counts_only_this_groups_members(): void
    {
        $group = $this->makeGroup(['name' => 'Counted']);
        $other = $this->makeGroup(['name' => 'Not counted']);

        $group->addCustomer($this->makeCustomer());
        $other->addCustomer($this->makeCustomer());
        $other->addCustomer($this->makeCustomer());

        $this->assertSame(1, $group->getActiveCustomersCount());
    }

    public function test_with_active_members_ignores_a_group_whose_memberships_have_all_lapsed(): void
    {
        $live = $this->makeGroup(['name' => 'Live']);
        $lapsed = $this->makeGroup(['name' => 'Lapsed']);

        $live->addCustomer($this->makeCustomer());
        $lapsed->addCustomer($this->makeCustomer(), now()->subDay());

        $names = CustomerGroup::withActiveMembers()->pluck('name');

        $this->assertContains('Live', $names);
        $this->assertNotContains('Lapsed', $names);
    }
}
