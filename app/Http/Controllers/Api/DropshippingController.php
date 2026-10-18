<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DropshippingService;
use App\Support\StoreAccess;
use App\Support\TeamPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DropshippingController extends Controller
{
    protected $dropshippingService;

    public function __construct(DropshippingService $dropshippingService)
    {
        $this->dropshippingService = $dropshippingService;
    }

    /**
     * Get all available suppliers
     *
     * @return JsonResponse
     */
    public function suppliers(Request $request)
    {
        abort_unless(StoreAccess::allows($request->user(), TeamPermission::ORDERS_MANAGE, null, 'update_order'), 403);

        $suppliers = $this->dropshippingService->getSuppliers();

        // Remove sensitive information like API keys
        $sanitizedSuppliers = [];
        foreach ($suppliers as $id => $supplier) {
            $sanitizedSuppliers[$id] = [
                'name' => $supplier['name'],
                'description' => $supplier['description'],
            ];
        }

        return response()->json(['suppliers' => $sanitizedSuppliers]);
    }

    /**
     * Check product availability
     *
     * @return JsonResponse
     */
    public function checkAvailability(Request $request)
    {
        abort_unless(StoreAccess::allows($request->user(), TeamPermission::ORDERS_MANAGE, null, 'update_order'), 403);

        $validator = Validator::make($request->all(), [
            'supplier_id' => 'required|string',
            'sku' => 'required|string',
            'quantity' => 'sometimes|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $result = $this->dropshippingService->checkAvailability(
            $request->supplier_id,
            $request->sku,
            $request->quantity ?? 1
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Place an order with a supplier
     *
     * @return JsonResponse
     */
    public function placeOrder(Request $request)
    {
        abort_unless(StoreAccess::allows($request->user(), TeamPermission::ORDERS_MANAGE, null, 'update_order'), 403);

        $validator = Validator::make($request->all(), [
            'supplier_id' => 'required|string',
            'order_data' => 'required|array',
            'order_data.items' => 'required|array',
            'order_data.shipping_address' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $result = $this->dropshippingService->placeOrder(
            $request->supplier_id,
            $request->order_data
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Track an order
     *
     * @return JsonResponse
     */
    public function trackOrder(Request $request)
    {
        abort_unless(StoreAccess::allows($request->user(), TeamPermission::ORDERS_MANAGE, null, 'update_order'), 403);

        $validator = Validator::make($request->all(), [
            'supplier_id' => 'required|string',
            'order_reference' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $result = $this->dropshippingService->trackOrder(
            $request->supplier_id,
            $request->order_reference
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }
}
