<?php

namespace Tests\Feature;

use App\Interfaces\PaymentGatewayInterface;
use App\Models\Order;
use App\Models\Product;
use App\Services\PaymentGateways\StripeGateway;
use Database\Seeders\EuVatRatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The buyer country is the member state of consumption for VAT, and the OSS report
 * reads it off the order — so checkout must persist it.
 */
class CheckoutBillingCountryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->app->instance(StripeGateway::class, new class implements PaymentGatewayInterface
        {
            public function processPayment(float $amount, array $paymentDetails): array
            {
                return ['success' => true, 'transaction_id' => 'ch_ok'];
            }

            public function processSubscription(string $planId, array $subscriptionDetails): array
            {
                return ['success' => true];
            }

            public function refundPayment(string $transactionId, float $amount): array
            {
                return ['success' => true];
            }
        });
    }

    public function test_checkout_persists_the_billing_country_uppercased(): void
    {
        $product = Product::factory()->create(['price' => 50, 'inventory_count' => 5, 'is_downloadable' => true]);

        $this->withStoredCart([
            $product->id => [
                'quantity' => 1,
                'price' => (float) $product->price,
                'is_downloadable' => true,
                'name' => $product->name,
            ],
        ])->post(route('checkout.process'), [
            'email' => 'buyer@example.com',
            'has_physical_products' => 0,
            'country' => 'de',
            'payment_method' => 'stripe',
            'stripeToken' => 'tok_test',
        ]);

        $this->assertSame('DE', Order::first()->billing_country);
    }

    public function test_reverse_charge_zero_rates_a_valid_eu_b2b_order(): void
    {
        config(['ecommerce.store_country' => 'DE']);   // EU-established store
        $this->seed(EuVatRatesSeeder::class);          // an FR order would otherwise be taxed
        Http::fake(['ec.europa.eu/*' => Http::response(['isValid' => true])]);
        $product = Product::factory()->create(['price' => 100, 'inventory_count' => 5, 'is_downloadable' => true]);

        $this->withStoredCart([
            $product->id => ['quantity' => 1, 'price' => 100.0, 'is_downloadable' => true, 'name' => $product->name],
        ])->post(route('checkout.process'), [
            'email' => 'biz@example.com',
            'has_physical_products' => 0,
            'country' => 'FR',
            'vat_number' => 'FR12345678',
            'payment_method' => 'stripe',
            'stripeToken' => 'tok_test',
        ]);

        $order = Order::first();
        $this->assertTrue((bool) $order->reverse_charge);
        $this->assertEqualsWithDelta(0.0, (float) $order->tax_amount, 0.001);
        $this->assertSame('FR12345678', $order->vat_number);
    }
}
