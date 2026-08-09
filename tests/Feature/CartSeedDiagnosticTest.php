<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Temporary: works out why a seeded guest cart fails at checkout. */
class CartSeedDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnose(): void
    {
        $product = Product::factory()->create(['price' => 50, 'inventory_count' => 5, 'is_downloadable' => true]);

        $response = $this->withStoredCart([
            $product->id => ['quantity' => 1, 'price' => 50.0],
        ])->post(route('checkout.process'), [
            'email' => 'buyer@example.com',
            'has_physical_products' => 0,
            'country' => 'de',
            'payment_method' => 'stripe',
            'stripeToken' => 'tok_test',
        ]);

        $this->fail(sprintf(
            'status=%d location=%s errors=%s error=%s orders=%d',
            $response->getStatusCode(),
            var_export($response->headers->get('Location'), true),
            json_encode(session('errors')?->getBag('default')?->all() ?? []),
            var_export(session('error'), true),
            Order::count(),
        ));
    }
}
