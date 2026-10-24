@extends('layouts.app')

@php
    $currency = $invoice->currency();
    $money = fn ($amount) => \App\Support\Money::format($amount, $currency);
    $order = $invoice->order;
    $subtotal = $invoice->lines->sum(fn ($line) => $line->price * $line->quantity);
@endphp

@section('content')
<div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
    <h1 class="text-2xl font-semibold text-ink">Invoice #{{ $invoice->id }}</h1>

    <dl class="mt-6 grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
        <div>
            <dt class="text-muted">Invoice date</dt>
            <dd class="text-ink">{{ $invoice->invoice_date->format('Y-m-d') }}</dd>
        </div>
        <div>
            <dt class="text-muted">Order</dt>
            <dd class="text-ink">#{{ $invoice->order_id }}</dd>
        </div>
        <div>
            <dt class="text-muted">Billed to</dt>
            <dd class="text-ink">
                {{ trim(($invoice->customer?->first_name ?? '').' '.($invoice->customer?->last_name ?? '')) }}
                <span class="block text-muted">{{ $invoice->customer?->email }}</span>
            </dd>
        </div>
    </dl>

    <table class="mt-8 min-w-full divide-y divide-hairline text-sm">
        <thead>
            <tr class="text-left text-xs font-medium uppercase tracking-wider text-muted">
                <th class="py-3 pr-4">Item</th>
                <th class="py-3 pr-4 text-right">Quantity</th>
                <th class="py-3 pr-4 text-right">Unit price</th>
                <th class="py-3 text-right">Total</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-hairline">
            @foreach ($invoice->lines as $line)
                <tr>
                    <td class="py-3 pr-4 text-ink">
                        {{ $line->displayName() }}
                        @foreach ($line->optionLabels($currency) as $label)
                            <span class="block text-muted">{{ $label }}</span>
                        @endforeach
                    </td>
                    <td class="py-3 pr-4 text-right">{{ $line->quantity }}</td>
                    <td class="py-3 pr-4 text-right">{{ $money($line->price) }}</td>
                    <td class="py-3 text-right">{{ $money($line->price * $line->quantity) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot class="text-ink">
            <tr>
                <th colspan="3" class="pt-4 pr-4 text-right font-normal text-muted">Subtotal</th>
                <td class="pt-4 text-right">{{ $money($subtotal) }}</td>
            </tr>
            @if ((float) $order?->discount_amount > 0)
                <tr>
                    <th colspan="3" class="pr-4 text-right font-normal text-muted">Discount{{ $order->coupon_code ? ' ('.$order->coupon_code.')' : '' }}</th>
                    <td class="text-right">−{{ $money($order->discount_amount) }}</td>
                </tr>
            @endif
            @if ((float) $order?->shipping_cost > 0)
                <tr>
                    <th colspan="3" class="pr-4 text-right font-normal text-muted">Shipping</th>
                    <td class="text-right">{{ $money($order->shipping_cost) }}</td>
                </tr>
            @endif
            @if ($order?->reverse_charge)
                <tr>
                    <th colspan="3" class="pr-4 text-right font-normal text-muted">VAT (reverse charge{{ $order->vat_number ? ', '.$order->vat_number : '' }})</th>
                    <td class="text-right">{{ $money(0) }}</td>
                </tr>
            @elseif ((float) $order?->tax_amount > 0)
                <tr>
                    <th colspan="3" class="pr-4 text-right font-normal text-muted">Tax</th>
                    <td class="text-right">{{ $money($order->tax_amount) }}</td>
                </tr>
            @endif
            <tr>
                <th colspan="3" class="pt-2 pr-4 text-right font-semibold">Total</th>
                <td class="pt-2 text-right font-semibold">{{ $money($invoice->total_amount) }}</td>
            </tr>
            @if ((float) $order?->refund_total > 0)
                <tr>
                    <th colspan="3" class="pr-4 text-right font-normal text-muted">Refunded</th>
                    <td class="text-right">−{{ $money($order->refund_total) }}</td>
                </tr>
            @endif
        </tfoot>
    </table>

    <a href="{{ route('invoices.index') }}" class="btn btn-primary mt-8">Back to invoices</a>
</div>
@endsection
