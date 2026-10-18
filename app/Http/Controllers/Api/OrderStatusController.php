<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidOrderTransitionException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\StoreAccess;
use App\Support\TeamPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderStatusController extends Controller
{
    /**
     * Staff moves an order through its FULFILMENT lifecycle. Payment outcomes
     * (paid/failed) and money states (refunded/partially_refunded) are excluded —
     * those are driven by checkout and the refund flow, not a manual status set.
     * The state machine enforces which moves are legal from the current status.
     */
    public function update(Request $request, Order $order): JsonResponse
    {
        abort_unless(StoreAccess::allows($request->user(), TeamPermission::ORDERS_MANAGE, $order, 'update_order'), 403);

        $validated = $request->validate([
            'status' => 'required|in:processing,supplier_queued,supplier_failed,completed,cancelled',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $order->transitionTo($validated['status'], $request->user()->id, $validated['notes'] ?? null);
        } catch (InvalidOrderTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Order status updated.', 'order' => $order->fresh()]);
    }
}
