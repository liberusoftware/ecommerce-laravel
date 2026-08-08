<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Locks down five verified authorization holes surfaced by an audit sweep:
 *  1. Api\ProductController writes — admin-only, were auth-only.
 *  2. Api\CollectionController writes — admin-only, were auth-only.
 *  3. checkout.confirmation — was an unauthenticated order-PII IDOR.
 *  4. InvoiceController index/show — was readable across all users.
 *  5. PaypalPaymentController subscription mutations — were unauthenticated.
 */
class AuthorizationHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate('super_admin', 'web');

        $admin = User::factory()->create()->assignRole('super_admin');

        // Products and collections carry team_id with a database default of 1,
        // and the API writes are now ownership-checked (#939). These tests are
        // about the role gate, not the ownership one, so the admin is put in
        // team 1 — where the rows they act on land — to keep the two apart.
        // Team::create would drop id and user_id — neither is fillable. The
        // factory writes unguarded, which is why the other suites can pass both.
        $team = Team::find(1) ?? Team::factory()->create([
            'id' => 1,
            'name' => 'default',
            'user_id' => $admin->id,
        ]);

        // Membership rather than ownership, since admin() is called more than
        // once per test and only the first caller can own team 1.
        $admin->teams()->syncWithoutDetaching([$team->id => ['role' => 'admin']]);

        return $admin;
    }

    private function order(?int $userId): Order
    {
        return Order::create([
            'user_id' => $userId,
            'customer_email' => 'buyer@example.com',
            'total_amount' => 10,
            'status' => 'pending',
        ]);
    }

    // ---- 1. Product API is admin-only -------------------------------------

    public function test_non_admin_cannot_create_a_product(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/products', ['name' => 'x', 'short_description' => 'x', 'price' => 1, 'category_id' => 1])
            ->assertForbidden();
    }

    public function test_non_admin_cannot_update_or_delete_a_product(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 10]);

        $this->actingAs($user)->putJson("/api/products/{$product->id}", ['price' => 0])->assertForbidden();
        $this->actingAs($user)->deleteJson("/api/products/{$product->id}")->assertForbidden();

        $this->assertNotNull($product->fresh(), 'Product must survive a non-admin delete attempt');
        $this->assertEquals(10, $product->fresh()->price);
    }

    public function test_admin_can_delete_a_product(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin())->deleteJson("/api/products/{$product->id}")->assertOk();
    }

    // ---- 2. Collection API is admin-only ----------------------------------

    public function test_non_admin_cannot_write_collections(): void
    {
        $user = User::factory()->create();
        $collection = ProductCollection::factory()->create();

        $this->actingAs($user)->postJson('/api/collections', ['name' => 'x'])->assertForbidden();
        $this->actingAs($user)->putJson("/api/collections/{$collection->id}", ['name' => 'y'])->assertForbidden();
        $this->actingAs($user)->deleteJson("/api/collections/{$collection->id}")->assertForbidden();

        $this->assertNotNull($collection->fresh());
    }

    public function test_admin_can_delete_a_collection(): void
    {
        $collection = ProductCollection::factory()->create();

        $this->actingAs($this->admin())->deleteJson("/api/collections/{$collection->id}")->assertOk();
    }

    // ---- 3. Order confirmation is not an open IDOR ------------------------

    public function test_guest_cannot_view_an_arbitrary_order_confirmation(): void
    {
        $order = $this->order(User::factory()->create()->id);

        $this->get(route('checkout.confirmation', $order))->assertForbidden();
    }

    public function test_user_cannot_view_another_users_order_confirmation(): void
    {
        $order = $this->order(User::factory()->create()->id);

        $this->actingAs(User::factory()->create())
            ->get(route('checkout.confirmation', $order))
            ->assertForbidden();
    }

    public function test_user_can_view_their_own_order_confirmation(): void
    {
        $user = User::factory()->create();
        $order = $this->order($user->id);

        $this->actingAs($user)->get(route('checkout.confirmation', $order))->assertOk();
    }

    public function test_guest_can_view_the_order_they_just_placed(): void
    {
        $order = $this->order(null);

        $this->withSession(['recent_order_ids' => [$order->id]])
            ->get(route('checkout.confirmation', $order))
            ->assertOk();
    }

    // ---- 4. Invoices are scoped to their owner ----------------------------

    private function invoiceForUser(User $user): Invoice
    {
        $order = $this->order($user->id);
        $customer = Customer::forceCreate([
            'first_name' => 'Test', 'last_name' => 'Buyer', 'email' => 'inv@example.com',
            'phone_number' => 1234567890, 'address' => '1 St', 'city' => 'Town',
            'state' => 'CA', 'postal_code' => '90001',
        ]);

        return Invoice::forceCreate([
            'order_id' => $order->id, 'customer_id' => $customer->id,
            'invoice_date' => now(), 'total_amount' => 42, 'payment_status' => 'paid',
        ]);
    }

    public function test_user_cannot_view_another_users_invoice(): void
    {
        $victim = $this->invoiceForUser(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->get(route('invoices.show', $victim->id))
            ->assertNotFound();
    }

    public function test_user_can_view_their_own_invoice(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoiceForUser($user);

        $this->actingAs($user)->get(route('invoices.show', $invoice->id))->assertOk();
    }

    public function test_invoice_index_lists_only_the_users_own_invoices(): void
    {
        $user = User::factory()->create();
        $this->invoiceForUser($user);
        $this->invoiceForUser(User::factory()->create()); // someone else's

        $response = $this->actingAs($user)->get(route('invoices.index'))->assertOk();
        $this->assertCount(1, $response->viewData('invoices'));
    }

    public function test_admin_can_view_any_invoice(): void
    {
        $invoice = $this->invoiceForUser(User::factory()->create());

        $this->actingAs($this->admin())->get(route('invoices.show', $invoice->id))->assertOk();
    }

    // ---- 5. PayPal subscription mutations require auth --------------------

    public function test_guest_cannot_update_or_cancel_a_paypal_subscription(): void
    {
        // Unauthenticated → redirected to login, never a 200 success.
        $this->patch(route('paypal.subscription.update'), ['subscriptionId' => 'I-VICTIM', 'planId' => 'P-2'])
            ->assertRedirect(route('login'));
        $this->delete(route('paypal.subscription.cancel'), ['subscriptionId' => 'I-VICTIM'])
            ->assertRedirect(route('login'));
    }
}
