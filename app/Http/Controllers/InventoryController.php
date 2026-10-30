<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\StoreAccess;
use App\Support\TeamPermission;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class InventoryController extends Controller
{
    public function adjustInventory(Request $request)
    {
        $validatedData = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'variant_id' => 'nullable|integer',
            'quantity_change' => 'required|integer',
            'reason' => 'required|string|max:255',
        ]);

        $product = Product::findOrFail($validatedData['product_id']);

        // Stock is store-wide config that the oversell guard depends on — staff only.
        abort_unless(StoreAccess::allows($request->user(), TeamPermission::CATALOGUE_MANAGE, $product, 'update_product'), 403);

        // A product with variants holds no stock of its own: the adjustment names a variant.
        if ($product->hasVariants()) {
            $holder = isset($validatedData['variant_id']) ? $product->variants()->find($validatedData['variant_id']) : null;
            if ($holder === null) {
                return response()->json(['message' => 'This product has variants: name the variant whose stock to adjust.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        } elseif (isset($validatedData['variant_id'])) {
            return response()->json(['message' => 'This product has no variants.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } else {
            $holder = $product;
        }

        if (! $holder->tracksInventory()) {
            return response()->json(['message' => 'This product\'s stock is not tracked.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $holder->adjustInventory((int) $validatedData['quantity_change'], $validatedData['reason'])) {
            return response()->json(['message' => 'Invalid quantity change. Inventory count cannot be negative.'], Response::HTTP_BAD_REQUEST);
        }

        return response()->json([
            'message' => 'Inventory adjusted successfully',
            'product' => $product->fresh(),
            'variant' => $holder === $product ? null : $holder->fresh(),
        ], Response::HTTP_OK);
    }
}
