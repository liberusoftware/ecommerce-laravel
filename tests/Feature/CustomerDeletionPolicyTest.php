<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductRating;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * product_reviews and product_rating restrict deleting their customer, so the
 * policy must not offer a delete the database will refuse.
 */
class CustomerDeletionPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Ada', 'last_name' => 'Byron', 'email' => uniqid().'@example.test', 'phone_number' => '1',
            'address' => 'x', 'city' => 'x', 'state' => 'x', 'postal_code' => '1',
        ]);
    }

    private function admin(): User
    {
        Role::findOrCreate('super_admin', 'web');

        return User::factory()->create()->assignRole('super_admin');
    }

    public function test_a_customer_with_a_review_cannot_be_deleted(): void
    {
        $customer = $this->customer();
        ProductReview::create(['product_id' => Product::factory()->create()->id, 'customer_id' => $customer->id, 'comments' => 'Great']);

        $this->assertFalse($this->admin()->can('delete', $customer));
    }

    public function test_a_customer_with_a_rating_cannot_be_deleted(): void
    {
        $customer = $this->customer();
        ProductRating::create(['product_id' => Product::factory()->create()->id, 'customer_id' => $customer->id, 'rating' => 5]);

        $this->assertFalse($this->admin()->can('delete', $customer));
    }

    public function test_a_customer_with_nothing_attached_can_be_deleted(): void
    {
        $customer = $this->customer();

        $this->assertTrue($this->admin()->can('delete', $customer));
        $customer->delete();
        $this->assertNull(Customer::withoutGlobalScopes()->find($customer->id));
    }
}
