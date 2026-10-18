@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Order #{{ $order->id }}</h1>
    <p>Date: {{ $order->created_at->format('Y-m-d H:i') }}</p>
    <p>Status: {{ ucfirst($order->status) }}</p>
    <p>Payment: {{ $order->payment_status ? ucfirst($order->payment_status) : '—' }}</p>
    <p>Shipping: {{ $order->shipping_status ? ucfirst($order->shipping_status) : '—' }}</p>
    <p>Total: {{ \App\Support\Money::format($order->total_amount, $order->currency_code) }}</p>

    <a href="{{ route('orders.index') }}" class="btn btn-secondary">Back to orders</a>
</div>
@endsection
