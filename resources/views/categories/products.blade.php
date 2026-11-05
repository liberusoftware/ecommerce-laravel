@extends('layouts.app')

@section('title', $category->name.' — '.$storeName)

@section('content')
<div class="page-container py-10">
    <nav aria-label="Breadcrumb" class="breadcrumb">
        <a href="{{ route('categories.index') }}" class="breadcrumb-item">Categories</a>
        <span class="breadcrumb-separator" aria-hidden="true">/</span>
        <a href="{{ route('categories.show', $category) }}" class="breadcrumb-item">{{ $category->name }}</a>
        <span class="breadcrumb-separator" aria-hidden="true">/</span>
        <span class="text-ink" aria-current="page">All products</span>
    </nav>

    <header class="mt-6 flex flex-col gap-4 border-b border-hairline pb-8 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="heading-2">{{ $category->name }}</h1>
            <p class="mt-2 text-label text-muted">
                <span class="data">{{ $products->total() }}</span> {{ Str::plural('product', $products->total()) }}
            </p>
        </div>

        <form method="GET" action="{{ route('categories.products', $category) }}" class="flex items-end gap-3">
            <div class="w-52">
                <label for="category-sort" class="sr-only">Sort by</label>
                <select id="category-sort" name="sort" class="form-input">
                    <option value="">Sort by</option>
                    <option value="price" @selected(request('sort') == 'price')>Price: low to high</option>
                    <option value="-price" @selected(request('sort') == '-price')>Price: high to low</option>
                    <option value="name" @selected(request('sort') == 'name')>Name: A to Z</option>
                    <option value="-name" @selected(request('sort') == '-name')>Name: Z to A</option>
                    <option value="-created_at" @selected(request('sort') == '-created_at')>Newest</option>
                </select>
            </div>
            <button type="submit" class="btn btn-secondary">Sort</button>
        </form>
    </header>

    <div class="mt-10">
        <x-product-grid
            :products="$products"
            empty-title="No products in this category yet"
            empty-message="Check back soon, or browse the full catalogue." />
    </div>
</div>
@endsection
