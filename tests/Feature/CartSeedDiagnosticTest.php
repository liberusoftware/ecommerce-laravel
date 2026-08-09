<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\Store;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Temporary: works out why a seeded guest cart is invisible inside a request. */
class CartSeedDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnose(): void
    {
        $product = Product::factory()->create(['price' => 10, 'inventory_count' => 5]);

        $this->withStoredCart([$product->id => ['quantity' => 1, 'price' => 10]]);

        $row = CartItem::withoutGlobalScope('store')->first();

        $response = $this->get(route('cart.index'));

        $this->fail(sprintf(
            'rows=%d store_id=%s stores=%d token_before=%s token_after=%s visible_after=%d status=%d',
            CartItem::withoutGlobalScope('store')->count(),
            var_export($row?->store_id, true),
            Store::count(),
            var_export(session('cart_token'), true),
            var_export($response->getRequest()?->session()?->get('cart_token'), true),
            app(CartService::class)->count(),
            $response->getStatusCode(),
        ));
    }
}
