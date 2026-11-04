@extends('layouts.app')

@section('title', $tag->name.' — '.$storeName)

@section('content')
<div class="page-container py-10">
    <nav aria-label="Breadcrumb" class="breadcrumb">
        <a href="{{ route('tags.index') }}" class="breadcrumb-item">Tags</a>
        <span class="breadcrumb-separator" aria-hidden="true">/</span>
        <span class="text-ink" aria-current="page">{{ $tag->name }}</span>
    </nav>

    <header class="mt-6 border-b border-hairline pb-8">
        <h1 class="heading-2">{{ $tag->name }}</h1>
        <p class="mt-2 text-label text-muted">
            <span class="data">{{ $products->total() }}</span> {{ Str::plural('product', $products->total()) }}
        </p>
    </header>

    <div class="mt-10">
        <x-product-grid :products="$products" empty-title="No products with this tag yet" />
    </div>
</div>
@endsection
