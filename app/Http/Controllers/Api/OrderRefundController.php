<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Refund;
use App\Support\StoreAccess;
use App\Support\TeamPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderRefundController extends Controller
{
    /**
     * Staff-initiated refund on an order. Creates a Refund (+ RefundItems when
     * restocking) and runs it through the refund engine (gateway void → restock →
     * order state transition + customer notification).
     */
    public function store(Request $request, Order $order): JsonResponse
    {
        abort_unless(StoreAccess::allows($request->user(), TeamPermission::ORDERS_MANAGE, $order, 'update_order'), 403);

        $remaining = round((float) $order->total_amount - (float) $order->refund_total, 2);
        if ($remaining <= 0) {
            return response()->json(['message' => 'This order has no refundable balance remaining.'], 422);
        }

        $validated = $request->validate([
            'amount' => 'nullable|numeric|min:0.01|max:'.$remaining,
            'reason' => 'required|string|max:255',
            'restock' => 'sometimes|boolean',
        ]);

        $restock = $validated['restock'] ?? false;

        $refund = Refund::create([
            'order_id' => $order->id,
            'amount' => $validated['amount'] ?? $remaining,
            'reason' => $validated['reason'],
            'status' => 'pending',
            'refund_method' => 'original_payment',
            'restock_items' => $restock,
        ]);

        if ($restock) {
            foreach ($order->items as $item) {
                $refund->items()->create([
                    'order_item_id' => $item->id,
                    'quantity' => $item->quantity,
                    'amount' => (float) $item->price * $item->quantity,
                    'restock' => true,
                ]);
            }
        }

        if (! $refund->process($request->user()->id)) {
            // process() is a no-op on failure (gateway declined) — nothing moved.
            $refund->delete();

            return response()->json(['message' => 'The refund could not be processed (the payment gateway declined).'], 422);
        }

        return response()->json(['message' => 'Refund processed.', 'refund' => $refund->fresh()], 201);
    }
}
