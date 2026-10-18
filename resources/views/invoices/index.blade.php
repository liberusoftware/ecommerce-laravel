@extends('layouts.app')

@section('title', 'Invoices')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <h1 class="text-2xl font-bold text-ink mb-6">Invoices</h1>

    <div class="bg-white rounded-lg overflow-hidden border border-hairline">
        <table class="min-w-full divide-y divide-hairline">
            <thead class="bg-surface">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-muted uppercase tracking-wider">Invoice #</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-muted uppercase tracking-wider">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-muted uppercase tracking-wider">Total</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-muted uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-muted uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-hairline">
                @forelse($invoices as $invoice)
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-ink">#{{ $invoice->id }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-muted">{{ $invoice->invoice_date?->format('Y-m-d') }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-ink">{{ \App\Support\Money::format($invoice->total_amount) }}</td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 py-1 text-xs rounded-full {{ $invoice->payment_status === 'paid' ? 'bg-primary-100 text-primary-800' : 'bg-warning-50 text-warning-700' }}">
                                {{ ucfirst($invoice->payment_status ?? 'pending') }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <a href="{{ route('invoices.show', $invoice->id) }}" class="text-primary-700 hover:text-primary-800">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-4 text-center text-muted">No invoices found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="px-6 py-4">
            {{ $invoices->links() }}
        </div>
    </div>
</div>
@endsection
