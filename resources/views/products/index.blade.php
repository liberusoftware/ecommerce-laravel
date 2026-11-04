@extends('layouts.app')

@section('title', 'All products — '.$storeName)

@section('content')
    <div class="page-container py-10">
        <header class="flex flex-col gap-6 border-b border-hairline pb-8 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h1 class="heading-2">All products</h1>
                <p class="mt-2 text-label text-muted">
                    <span class="data">{{ $products->total() }}</span> {{ Str::plural('product', $products->total()) }}
                </p>
            </div>

            <form method="GET" action="{{ route('products.index') }}" class="flex flex-wrap items-end gap-3" role="search">
                <div class="w-full sm:w-56">
                    <label for="keyword" class="sr-only">Search products</label>
                    <input id="keyword" type="search" name="keyword" value="{{ request('keyword') }}"
                           placeholder="Search products" class="form-input">
                </div>
                <div class="w-32">
                    <label for="price_min" class="sr-only">Minimum price</label>
                    <input id="price_min" type="number" min="0" step="0.01" name="filter[price_min]"
                           value="{{ request('filter.price_min') }}" placeholder="Min price" class="form-input">
                </div>
                <div class="w-32">
                    <label for="price_max" class="sr-only">Maximum price</label>
                    <input id="price_max" type="number" min="0" step="0.01" name="filter[price_max]"
                           value="{{ request('filter.price_max') }}" placeholder="Max price" class="form-input">
                </div>
                <div class="w-48">
                    <label for="sort" class="sr-only">Sort by</label>
                    <select id="sort" name="sort" class="form-input">
                        <option value="">Sort by</option>
                        <option value="price" @selected(request('sort') == 'price')>Price: low to high</option>
                        <option value="-price" @selected(request('sort') == '-price')>Price: high to low</option>
                        <option value="name" @selected(request('sort') == 'name')>Name: A to Z</option>
                        <option value="-name" @selected(request('sort') == '-name')>Name: Z to A</option>
                        <option value="-created_at" @selected(request('sort') == '-created_at')>Newest</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Apply</button>
                @if (request()->hasAny(['keyword', 'filter', 'sort']))
                    <a href="{{ route('products.index') }}" class="btn btn-secondary">Clear</a>
                @endif
            </form>
        </header>

        <div class="mt-10">
            <x-product-grid
                :products="$products"
                empty-title="No products found"
                :empty-message="request('keyword') ? 'Nothing matches “'.request('keyword').'”. Try a different search or clear the filters.' : 'Try clearing the filters.'" />
        </div>
    </div>
@endsection
