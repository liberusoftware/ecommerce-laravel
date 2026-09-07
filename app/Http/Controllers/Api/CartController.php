<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Headless cart for API clients, backed by the persistent cart_items store.
 * Every query is scoped by user_id — that scoping is the IDOR guard, so a
 * client can only ever see or mutate its own cart.
 */
class CartController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = CartItem::where('user_id', $request->user()->id)
            ->with('product')
            ->get();

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
        ]);

        $product = Product::findOrFail($data['product_id']);

        $item = CartItem::firstOrNew([
            'user_id' => $request->user()->id,
            'product_id' => $product->id,
        ]);
        $quantity = ($item->quantity ?? 0) + $data['quantity'];

        if ($product->inventory_count < $quantity) {
            return $this->outOfStock($product->inventory_count);
        }

        $item->fill([
            'quantity' => $quantity,
            'price' => $product->price,
        ])->save();

        return response()->json(['data' => $item->load('product')], 201);
    }

    public function update(Request $request, int $product): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $product = Product::findOrFail($product);

        if ($product->inventory_count < $data['quantity']) {
            return $this->outOfStock($product->inventory_count);
        }

        $item = CartItem::where('user_id', $request->user()->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $item->update(['quantity' => $data['quantity']]);

        return response()->json(['data' => $item->load('product')]);
    }

    public function destroy(Request $request, int $product): JsonResponse
    {
        CartItem::where('user_id', $request->user()->id)
            ->where('product_id', $product)
            ->delete();

        return response()->json(['message' => 'Item removed from cart.']);
    }

    public function clear(Request $request): JsonResponse
    {
        CartItem::where('user_id', $request->user()->id)->delete();

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
