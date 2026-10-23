<?php

use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CollectionController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DropshippingController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OrderRefundController;
use App\Http\Controllers\Api\OrderStatusController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReturnRequestController;
use App\Http\Controllers\Api\WebhookEndpointController;
use App\Http\Controllers\GraphQLController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Storefront GraphQL endpoint. Public route with optional Sanctum auth resolved in the
// controller — the catalog is open, me/orders + mutations require a token.
Route::post('/graphql', GraphQLController::class);

// Dropshipping API routes (staff-only: these spend the merchant's supplier API key,
// so every method role-checks in the controller — there is no `role:` middleware alias here).
Route::middleware('auth:sanctum')->prefix('dropshipping')->group(function () {
    Route::get('/suppliers', [DropshippingController::class, 'suppliers']);
    Route::post('/check-availability', [DropshippingController::class, 'checkAvailability']);
    Route::post('/place-order', [DropshippingController::class, 'placeOrder']);
    Route::post('/track-order', [DropshippingController::class, 'trackOrder']);
});

// Product Management API routes
Route::middleware('auth:sanctum')->prefix('products')->group(function () {
    Route::get('/', [ProductController::class, 'index']);
    Route::post('/', [ProductController::class, 'store']);
    Route::get('/{identifier}', [ProductController::class, 'show']);
    Route::put('/{id}', [ProductController::class, 'update']);
    Route::patch('/{id}', [ProductController::class, 'update']);
    Route::delete('/{id}', [ProductController::class, 'destroy']);
});
// Order API routes (customer-facing: read-only, scoped to the authenticated user)
Route::middleware('auth:sanctum')->prefix('orders')->group(function () {
    Route::get('/', [OrderController::class, 'index']);
    Route::get('/{id}', [OrderController::class, 'show']);
    // Staff-only (role checked in the controller): initiate a refund on an order.
    Route::post('/{order}/refund', [OrderRefundController::class, 'store']);
    // Customer requests a return for their own order.
    Route::post('/{order}/returns', [ReturnRequestController::class, 'store']);
    // Staff-only (role checked in the controller): advance the order's fulfilment status.
    Route::post('/{order}/status', [OrderStatusController::class, 'update']);
});

// Outbound webhook endpoints — staff-managed integration config (role checked in the controller).
Route::middleware('auth:sanctum')->prefix('webhook-endpoints')->group(function () {
    Route::get('/', [WebhookEndpointController::class, 'index']);
    Route::post('/', [WebhookEndpointController::class, 'store']);
    Route::put('/{webhookEndpoint}', [WebhookEndpointController::class, 'update']);
    Route::delete('/{webhookEndpoint}', [WebhookEndpointController::class, 'destroy']);
    Route::get('/{webhookEndpoint}/deliveries', [WebhookEndpointController::class, 'deliveries']);
});

// Returns: customers read their own, staff read/act on all (roles checked in the controller).
Route::middleware('auth:sanctum')->prefix('returns')->group(function () {
    Route::get('/', [ReturnRequestController::class, 'index']);
    Route::get('/{returnRequest}', [ReturnRequestController::class, 'show']);
    Route::post('/{returnRequest}/approve', [ReturnRequestController::class, 'approve']);
    Route::post('/{returnRequest}/received', [ReturnRequestController::class, 'markReceived']);
});
// Customer profile API (the authenticated user's own customer record).
Route::middleware('auth:sanctum')->prefix('customer')->group(function () {
    Route::get('/', [CustomerController::class, 'show']);
    Route::put('/', [CustomerController::class, 'update']);
});

// Cart API routes (headless: read/manage the authenticated user's persistent cart).
Route::middleware('auth:sanctum')->prefix('cart')->group(function () {
    Route::get('/', [CartController::class, 'index']);
    Route::post('/', [CartController::class, 'store']);
    Route::put('/{item}', [CartController::class, 'update'])->whereNumber('item');
    Route::delete('/{item}', [CartController::class, 'destroy'])->whereNumber('item');
    Route::delete('/', [CartController::class, 'clear']);
});

// Collection API routes
Route::middleware('auth:sanctum')->prefix('collections')->group(function () {
    Route::get('/', [CollectionController::class, 'index']);
    Route::post('/', [CollectionController::class, 'store']);
    Route::get('/{idOrSlug}', [CollectionController::class, 'show']);
    Route::put('/{id}', [CollectionController::class, 'update']);
    Route::post('/{id}/products', [CollectionController::class, 'addProducts']);
    Route::delete('/{id}/products', [CollectionController::class, 'removeProducts']);
    Route::delete('/{id}', [CollectionController::class, 'destroy']);
});
