@props([
    'products',
    'columns' => 4,
    'emptyTitle' => 'No products found',
    'emptyMessage' => 'Try another category or search term.',
])

@php
    $grid = $columns === 3 ? 'sm:grid-cols-2 lg:grid-cols-3' : 'sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4';
@endphp

@if ($products->isEmpty())
    <x-empty-state :title="$emptyTitle" :message="$emptyMessage" />
@else
    <div {{ $attributes->merge(['class' => "grid gap-x-8 gap-y-12 {$grid}"]) }}>
        @foreach ($products as $product)
            <x-product-card :product="$product" />
        @endforeach
    </div>

    @if ($products instanceof \Illuminate\Contracts\Pagination\Paginator && $products->hasPages())
        <div class="mt-12">
            {{ $products->links() }}
        </div>
    @endif
@endif
