@extends('layouts.app')

@section('title', $collection->name.' — '.$storeName)

@section('content')
<div class="page-container py-10">
    <nav aria-label="Breadcrumb" class="breadcrumb">
        <a href="{{ route('collections.index') }}" class="breadcrumb-item">Collections</a>
        <span class="breadcrumb-separator" aria-hidden="true">/</span>
        <span class="text-ink" aria-current="page">{{ $collection->name }}</span>
    </nav>

    <header class="mt-6 border-b border-hairline pb-8">
        <h1 class="heading-2">{{ $collection->name }}</h1>
        @if ($collection->description)
            <p class="prose-measure mt-3 text-body text-muted">{{ $collection->description }}</p>
        @endif
        <p class="mt-3 text-label text-muted">
            <span class="data">{{ $products->total() }}</span> {{ Str::plural('product', $products->total()) }}
        </p>
    </header>

    <div class="mt-10">
        <x-product-grid :products="$products" empty-title="This collection is empty" empty-message="Products appear here once they are added to the collection." />
    </div>
</div>
@endsection
