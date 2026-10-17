@extends('layouts.app')

@section('title', 'Order Confirmation')

@section('content')
<div class="container mx-auto px-4 py-12">
    <div class="max-w-2xl mx-auto">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-primary-100 rounded-full mb-4">
                <svg class="w-8 h-8 text-primary-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
            <h1 class="text-3xl font-bold text-ink mb-2">Order Confirmed!</h1>
            <p class="text-muted">Thank you for your purchase. Your order has been received.</p>
        </div>

        <div class="bg-white border border-hairline rounded-lg p-6 mb-6">
            <h2 class="text-lg font-semibold text-ink mb-4">Order Details</h2>
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <span class="text-muted">Order Number:</span>
                    <span class="font-medium text-ink ml-1">#{{ $order->id }}</span>
                </div>
                <div>
                    <span class="text-muted">Date:</span>
                    <span class="font-medium text-ink ml-1">{{ $order->created_at->format('M d, Y') }}</span>
                </div>
                <div>
                    <span class="text-muted">Email:</span>
                    <span class="font-medium text-ink ml-1">{{ $order->customer_email }}</span>
                </div>
                <div>
                    <span class="text-muted">Status:</span>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary-100 text-primary-800 ml-1">
                        {{ ucfirst($order->status) }}
                    </span>
                </div>
            </div>
        </div>

        <div class="bg-white border border-hairline rounded-lg p-6 mb-6">
            <h2 class="text-lg font-semibold text-ink mb-4">Order Summary</h2>
            <div class="divide-y divide-hairline">
                @foreach($order->items as $item)
                    <div class="py-3 flex justify-between items-center">
                        <div>
                            <span class="font-medium text-ink">{{ $item->product->name ?? 'Product #' . $item->product_id }}</span>
                            <span class="text-muted text-sm ml-2">× {{ $item->quantity }}</span>
                            @if($item->is_downloadable ?? false)
                                <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-primary-100 text-primary-800">Digital</span>
                            @endif
                        </div>
                        <span class="font-medium text-ink">${{ number_format($item->price * $item->quantity, 2) }}</span>
                    </div>
                @endforeach
            </div>
            <div class="pt-4 mt-2 space-y-2 text-sm">
                <div class="flex justify-between text-muted">
                    <span>Subtotal</span>
                    <span>${{ number_format($order->total_amount - $order->shipping_cost - $order->tax_amount + ($order->discount_amount ?? 0), 2) }}</span>
                </div>
                @if($order->discount_amount > 0)
                    <div class="flex justify-between text-primary-700">
                        <span>Discount {{ $order->coupon_code ? '(' . $order->coupon_code . ')' : '' }}</span>
                        <span>-${{ number_format($order->discount_amount, 2) }}</span>
                    </div>
                @endif
                @if($order->shipping_cost > 0)
                    <div class="flex justify-between text-muted">
                        <span>Shipping</span>
                        <span>${{ number_format($order->shipping_cost, 2) }}</span>
                    </div>
                @endif
                @if($order->tax_amount > 0)
                    <div class="flex justify-between text-muted">
                        <span>Tax</span>
                        <span>${{ number_format($order->tax_amount, 2) }}</span>
                    </div>
                @endif
                <div class="flex justify-between font-bold text-ink text-base pt-2 border-t border-hairline">
                    <span>Total</span>
                    <span>${{ number_format($order->total_amount, 2) }}</span>
                </div>
            </div>
        </div>

        @if($order->shipping_address)
            <div class="bg-white border border-hairline rounded-lg p-6 mb-6">
                <h2 class="text-lg font-semibold text-ink mb-2">Shipping Address</h2>
                <p class="text-muted">{{ $order->shipping_address }}</p>
            </div>
        @endif

        <div class="flex flex-col sm:flex-row gap-4 justify-center">
            <a href="{{ route('orders.show', $order->id) }}" class="text-white bg-primary-700 hover:bg-primary-600 font-medium rounded-lg px-6 py-3 text-center">
                View Order
            </a>
            <a href="{{ route('products.index') }}" class="text-ink bg-white border border-stroke hover:bg-surface font-medium rounded-lg px-6 py-3 text-center">
                Continue Shopping
            </a>
        </div>
    </div>
</div>
@endsection
