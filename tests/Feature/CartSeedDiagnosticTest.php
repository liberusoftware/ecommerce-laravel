<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Temporary: works out what the cart looks like inside a request. */
class CartSeedDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnose(): void
    {
        Route::middleware('web')->get('/__diag', fn () => response()->json([
            'contents' => app(CartService::class)->contents(),
            'products_scoped' => Product::count(),
            'products_all' => Product::withoutGlobalScope('store')->count(),
        ]));

        $product = Product::factory()->create(['price' => 50, 'inventory_count' => 5, 'is_downloadable' => true]);

        $body = $this->withStoredCart([
            $product->id => ['quantity' => 1, 'price' => 50.0],
        ])->get('/__diag')->getContent();

        $this->fail('diag='.$body.' product_downloadable='.var_export($product->fresh()->is_downloadable, true));
    }
}
