@extends('layouts.app')

@section('title', 'Order Summary')

@section('content')
<div class="min-h-screen bg-surface py-10">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Page Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-ink">Order Summary</h1>
            <p class="text-muted mt-1">Order #{{ $order->id }}</p>
        </div>

        <div class="lg:grid lg:grid-cols-12 lg:gap-8">

            <!-- Items & Costs (Left Column) -->
            <div class="lg:col-span-7 space-y-6">

                <!-- Order Items -->
                <div class="bg-white rounded-xl border border-hairline">
                    <div class="px-6 py-4 border-b border-hairline">
                        <h2 class="text-lg font-semibold text-ink">Items Ordered</h2>
                    </div>
                    <div class="divide-y divide-hairline">
                        @foreach($order->items as $item)
                            <div class="px-6 py-4 flex items-start justify-between gap-4">
                                <div class="flex items-start gap-3">
                                    <div class="w-10 h-10 rounded-lg bg-surface flex items-center justify-center flex-shrink-0">
                                        <svg class="w-5 h-5 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-ink">{{ $item->displayName() }}</p>
                                        @foreach($item->optionLabels($order->currency_code) as $label)
                                            <p class="text-xs text-muted mt-0.5">{{ $label }}</p>
                                        @endforeach
                                        <p class="text-xs text-muted mt-0.5">Qty: {{ $item->quantity }}</p>
                                    </div>
                                </div>
                                <span class="text-sm font-semibold text-ink flex-shrink-0">{{ \App\Support\Money::format($item->price, $order->currency_code) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Costs Breakdown -->
                <div class="bg-white rounded-xl border border-hairline">
                    <div class="px-6 py-4 border-b border-hairline">
                        <h2 class="text-lg font-semibold text-ink">Cost Breakdown</h2>
                    </div>
                    <div class="px-6 py-4 space-y-3">
                        <div class="flex justify-between text-sm text-muted">
                            <span>Subtotal</span>
                            <span>{{ \App\Support\Money::format($order->items->sum('price'), $order->currency_code) }}</span>
                        </div>
                        <div class="flex justify-between text-sm text-muted">
                            <span>Shipping ({{ $order->shippingMethod->name }})</span>
                            <span>{{ \App\Support\Money::format($order->shippingMethod->price, $order->currency_code) }}</span>
                        </div>
                        <div class="flex justify-between text-base font-bold text-ink pt-3 border-t border-hairline">
                            <span>Total (USD)</span>
                            <span>{{ \App\Support\Money::format($order->total_amount, $order->currency_code) }}</span>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Order Details (Right Column) -->
            <div class="lg:col-span-5 mt-6 lg:mt-0 space-y-6">

                <div class="bg-white rounded-xl border border-hairline">
                    <div class="px-6 py-4 border-b border-hairline">
                        <h2 class="text-lg font-semibold text-ink">Order Details</h2>
                    </div>
                    <div class="px-6 py-4 space-y-4">

                        <div>
                            <p class="text-xs font-medium text-muted uppercase tracking-wide mb-1">Shipping Address</p>
                            <p class="text-sm text-ink">{{ $order->shipping_address }}</p>
                        </div>

                        <div class="pt-4 border-t border-hairline">
                            <p class="text-xs font-medium text-muted uppercase tracking-wide mb-1">Shipping Method</p>
                            <p class="text-sm text-ink font-medium">{{ $order->shippingMethod->name }}</p>
                            <p class="text-xs text-muted mt-0.5">{{ $order->shippingMethod->estimated_delivery_time }}</p>
                        </div>

                        <div class="pt-4 border-t border-hairline">
                            <p class="text-xs font-medium text-muted uppercase tracking-wide mb-1">Payment Method</p>
                            <div class="flex items-center gap-2">
                                <div class="w-6 h-6 rounded bg-surface flex items-center justify-center">
                                    <svg class="w-3.5 h-3.5 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                                    </svg>
                                </div>
                                <p class="text-sm text-ink font-medium">{{ ucfirst($order->payment_method) }}</p>
                            </div>
                        </div>

                        <div class="pt-4 border-t border-hairline">
                            <p class="text-xs font-medium text-muted uppercase tracking-wide mb-1">Order Status</p>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium @if($order->status === 'completed') bg-primary-100 text-primary-800 @elseif($order->status === 'pending') bg-warning-50 text-warning-700 @elseif($order->status === 'processing') bg-primary-100 text-primary-800 @else bg-surface text-ink @endif">
                                {{ ucfirst($order->status) }}
                            </span>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Action Buttons -->
        <div class="mt-8 flex flex-col sm:flex-row gap-4">
            <a
                href="{{ route('home') }}"
                class="flex-1 sm:flex-none inline-flex items-center justify-center gap-2 px-6 py-3 bg-primary-700 hover:bg-primary-600 text-white font-medium rounded-lg transition-colors duration-200"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                </svg>
                Back to Home
            </a>
            <a
                href="{{ route('orders.track', $order->id) }}"
                class="flex-1 sm:flex-none inline-flex items-center justify-center gap-2 px-6 py-3 bg-white border border-stroke hover:bg-surface text-ink font-medium rounded-lg transition-colors duration-200"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"></path>
                </svg>
                Track Order
            </a>
        </div>
    </div>
</div>
@endsection
