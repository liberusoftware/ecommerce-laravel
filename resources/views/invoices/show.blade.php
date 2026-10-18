@extends('layouts.app')

@section('content')
<div class="container">
    <h2 class="mb-4">Invoice Details</h2>
    <div class="invoice-info mb-4">
        <p><strong>Invoice ID:</strong> {{ $invoice->id }}</p>
        <p><strong>Invoice Date:</strong> {{ $invoice->invoice_date->format('Y-m-d') }}</p>
        <p><strong>Total Amount:</strong> {{ \App\Support\Money::format($invoice->total_amount) }}</p>
        <h3 class="mt-4">Customer Information</h3>
        <p><strong>Name:</strong> {{ $invoice->customer->name }}</p>
        <p><strong>Email:</strong> {{ $invoice->customer->email }}</p>
    </div>
    <h3 class="mb-4">Products</h3>
    <table class="table table-bordered">
        <thead class="thead-light">
            <tr>
                <th>Product Name</th>
                <th>Quantity</th>
                <th>Price</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->products as $product)
            <tr>
                <td>{{ $product->name }}</td>
                <td>{{ $product->pivot->quantity }}</td>
                <td>{{ \App\Support\Money::format($product->pivot->price) }}</td>
                <td>{{ \App\Support\Money::format($product->pivot->quantity * $product->pivot->price) }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th colspan="3">Total Amount</th>
                <th>{{ \App\Support\Money::format($invoice->total_amount) }}</th>
            </tr>
        </tfoot>
    </table>
    <a href="{{ route('invoices.index') }}" class="btn btn-primary mt-4">Back to Invoices</a>
</div>
@endsection
