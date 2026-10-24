<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Paying an order generates its invoice: the customer is resolved via the
 * User<->Customer identity link (or created from the guest email), and the order's
 * line items are copied onto the invoice. Idempotent per order.
 */
class InvoiceGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function order(?int $userId, float $total = 100): Order
    {
        return Order::create([
            'user_id' => $userId,
            'customer_email' => 'buyer@example.com',
            'total_amount' => $total,
            'status' => 'pending',
            'payment_status' => 'pending',
        ]);
    }

    public function test_paying_an_order_generates_an_invoice_for_the_users_customer(): void
    {
        $user = User::factory()->create(['name' => 'Jane Doe']);
        $order = $this->order($user->id, 100);
        $order->items()->create(['product_id' => Product::factory()->create()->id, 'quantity' => 2, 'price' => 50]);

        $order->transitionTo(Order::STATUS_PAID);

        $invoice = Invoice::where('order_id', $order->id)->first();
        $this->assertNotNull($invoice, 'Invoice should be generated on the paid transition');
        $this->assertEquals(100, (float) $invoice->total_amount);
        $this->assertEquals('paid', $invoice->payment_status);
        $this->assertEquals($user->getOrCreateCustomer()->id, $invoice->customer_id);
        $this->assertCount(1, $invoice->lines);
        $this->assertEquals(2, $invoice->lines->first()->quantity);
    }

    public function test_guest_order_invoice_creates_a_customer_from_the_email(): void
    {
        $order = $this->order(null, 50);

        $order->transitionTo(Order::STATUS_PAID);

        $invoice = Invoice::where('order_id', $order->id)->first();
        $this->assertNotNull($invoice->customer_id);
        $this->assertEquals('buyer@example.com', $invoice->customer->email);
    }

    public function test_invoice_generation_is_idempotent(): void
    {
        $order = $this->order(User::factory()->create()->id, 10);

        Invoice::generateForOrder($order);
        Invoice::generateForOrder($order->fresh());

        $this->assertDatabaseCount('invoices', 1);
    }
}
