<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Support\StoreAccess;
use App\Support\TeamPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReturnRequestController extends Controller
{
    /** States an order can be returned from. */
    private const RETURNABLE = [Order::STATUS_PAID, Order::STATUS_COMPLETED];

    /**
     * List returns. Customers see only their own; staff see all (with an optional
     * ?status filter for working the queue).
     */
    public function index(Request $request): JsonResponse
    {
        $query = ReturnRequest::with(['items', 'order'])->latest();

        if (StoreAccess::allows($request->user(), TeamPermission::ORDERS_VIEW, null, 'view_any_order')) {
            // Returns carry no store of their own; the order's store scope narrows them.
            $query->whereHas('order')->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));
        } else {
            $query->where('customer_id', $request->user()->id);
        }

        return response()->json($query->paginate(15));
    }

    /**
     * A single return — the owner's own, or any for staff. A foreign return is
     * not found (404), so there is no existence leak.
     */
    public function show(Request $request, ReturnRequest $returnRequest): JsonResponse
    {
        $isStaff = StoreAccess::allows($request->user(), TeamPermission::ORDERS_VIEW, $returnRequest->order, 'view_any_order');
        abort_unless($isStaff || (int) $returnRequest->customer_id === (int) $request->user()->id, 404);

        return response()->json($returnRequest->load(['items', 'order']));
    }

    /**
     * Staff records that the returned goods physically arrived.
     */
    public function markReceived(Request $request, ReturnRequest $returnRequest): JsonResponse
    {
        abort_unless(StoreAccess::allows($request->user(), TeamPermission::ORDERS_MANAGE, $returnRequest->order, 'update_order'), 403);

        $returnRequest->markAsReceived();

        return response()->json(['message' => 'Return marked as received.', 'return' => $returnRequest->fresh()]);
    }

    /**
     * A customer requests a return for their own order. Scoped to the
     * authenticated user's orders — a foreign order is not found (404).
     */
    public function store(Request $request, $orderId): JsonResponse
    {
        $order = $request->user()->orders()->with('items')->findOrFail($orderId);

        if (! in_array($order->status, self::RETURNABLE, true)) {
            return response()->json(['message' => 'This order is not eligible for a return.'], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.order_item_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.condition' => 'nullable|in:unopened,opened,damaged',
        ]);

        foreach ($validated['items'] as $line) {
            $orderItem = $order->items->firstWhere('id', $line['order_item_id']);
            if (! $orderItem) {
                return response()->json(['message' => 'A returned item does not belong to this order.'], 422);
            }
            if ($line['quantity'] > $orderItem->quantity) {
                return response()->json(['message' => 'A return quantity exceeds the quantity purchased.'], 422);
            }
        }

        $return = ReturnRequest::create([
            'order_id' => $order->id,
            'customer_id' => $request->user()->id,
            'reason' => $validated['reason'],
            'description' => $validated['description'] ?? null,
            'status' => 'pending',
        ]);

        foreach ($validated['items'] as $line) {
            $return->items()->create([
                'order_item_id' => $line['order_item_id'],
                'quantity' => $line['quantity'],
                'condition' => $line['condition'] ?? null,
            ]);
        }

        return response()->json([
            'message' => 'Return request submitted.',
            'return' => $return->fresh()->load('items'),
        ], 201);
    }

    /**
     * Staff approves a return, which spawns and processes the refund (gateway
     * void → restock → order state transition → customer notification).
     */
    public function approve(Request $request, ReturnRequest $returnRequest): JsonResponse
    {
        abort_unless(StoreAccess::allows($request->user(), TeamPermission::ORDERS_MANAGE, $returnRequest->order, 'update_order'), 403);

        $refund = $returnRequest->approve($request->user()->id);

        return response()->json([
            'message' => 'Return approved.',
            'return' => $returnRequest->fresh(),
            'refund' => $refund, // null when the return was already approved / nothing itemized
        ]);
    }
}
