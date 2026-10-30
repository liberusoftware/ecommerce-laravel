<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidProductConfigurationException;
use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Headless cart for API clients, the same cart the storefront writes. Every
 * line is reached through the token's own cart — that scoping is the IDOR
 * guard. Lines are addressed by id: one product can be several lines.
 */
class CartController extends Controller
{
    public function __construct(private CartService $cart) {}

    public function index(Request $request): JsonResponse
    {
        $items = $this->cart->for($request->user())->items();

        return response()->json([
            'data' => $items,
            'subtotal' => (float) $items->sum(fn (CartItem $i) => $i->price * $i->quantity),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'options' => ['nullable', 'array'],
            'options.*' => ['integer'],
            'variant_id' => ['nullable', 'integer'],
        ]);

        $product = Product::active()->findOrFail($data['product_id']);

        try {
            $item = $this->cart->for($request->user())->add($product, $data['quantity'], $data['options'] ?? [], null, $data['variant_id'] ?? null);
        } catch (InsufficientStockException) {
            $variant = isset($data['variant_id']) ? $product->variants()->find($data['variant_id']) : null;

            return $this->outOfStock((int) ($variant ?? $product)->inventory_count);
        } catch (InvalidProductConfigurationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $item->load(['product', 'variant'])], 201);
    }

    public function update(Request $request, int $item): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $cart = $this->cart->for($request->user());
        $line = $cart->line($item) ?? abort(404);

        try {
            $cart->setQuantity($line, $data['quantity']);
        } catch (InsufficientStockException) {
            return $this->outOfStock((int) ($line->product_variant_id !== null ? $line->variant : $line->product)?->inventory_count);
        }

        return response()->json(['data' => $line->load(['product', 'variant'])]);
    }

    public function destroy(Request $request, int $item): JsonResponse
    {
        $this->cart->for($request->user())->remove($item);

        return response()->json(['message' => 'Item removed from cart.']);
    }

    public function clear(Request $request): JsonResponse
    {
        $this->cart->for($request->user())->clear();

        return response()->json(['message' => 'Cart cleared.']);
    }

    private function outOfStock(int $available): JsonResponse
    {
        return response()->json([
            'message' => 'Requested quantity exceeds available stock.',
            'available' => $available,
        ], 422);
    }
}
