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
            'quantity_change' => 'required|integer',
            'reason' => 'required|string|max:255',
        ]);

        $product = Product::findOrFail($validatedData['product_id']);

        // Stock is store-wide config that the oversell guard depends on — staff only.
        abort_unless(StoreAccess::allows($request->user(), TeamPermission::CATALOGUE_MANAGE, $product, 'update_product'), 403);

        if (! $product->adjustInventory((int) $validatedData['quantity_change'], $validatedData['reason'])) {
            return response()->json(['message' => 'Invalid quantity change. Inventory count cannot be negative.'], Response::HTTP_BAD_REQUEST);
        }

        return response()->json(['message' => 'Inventory adjusted successfully', 'product' => $product->fresh()], Response::HTTP_OK);
    }
}
