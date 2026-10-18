<?php

namespace App\Http\Controllers;

use App\Models\InventoryLog;
use App\Models\Product;
use App\Support\StoreAccess;
use App\Support\TeamPermission;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    /** Sentinel thrown inside the transaction to reject a negative result. */
    private const NEGATIVE = 'NEGATIVE_INVENTORY';

    public function adjustInventory(Request $request)
    {
        // Stock is store-wide config that the oversell guard depends on — staff only.
        abort_unless(StoreAccess::allows($request->user(), TeamPermission::CATALOGUE_MANAGE, null, 'update_product'), 403);

        $validatedData = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'quantity_change' => 'required|integer',
            'reason' => 'required|string|max:255',
        ]);

        try {
            // Lock the row so concurrent adjustments (and the checkout decrement)
            // can't lose an update; log + stock write commit together.
            $product = DB::transaction(function () use ($validatedData) {
                $product = Product::lockForUpdate()->findOrFail($validatedData['product_id']);
                $old = $product->inventory_count;
                $new = $old + $validatedData['quantity_change'];

                if ($new < 0) {
                    throw new \RuntimeException(self::NEGATIVE);
                }

                $product->update(['inventory_count' => $new]);

                InventoryLog::create([
                    'product_id' => $product->id,
                    'quantity_change' => $validatedData['quantity_change'],
                    'old_quantity' => $old,
                    'new_quantity' => $new,
                    'reason' => $validatedData['reason'],
                ]);

                return $product;
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === self::NEGATIVE) {
                return response()->json(['message' => 'Invalid quantity change. Inventory count cannot be negative.'], Response::HTTP_BAD_REQUEST);
            }
            throw $e;
        }

        return response()->json(['message' => 'Inventory adjusted successfully', 'product' => $product], Response::HTTP_OK);
    }
}
