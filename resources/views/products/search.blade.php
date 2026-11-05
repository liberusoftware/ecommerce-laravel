@extends('layouts.app')

@section('title', 'Search — '.$storeName)

@section('content')
<div class="page-container py-10">
    <header class="border-b border-hairline pb-8">
        <h1 class="heading-2">
            @if (filled($keyword ?? null))
                Results for “{{ $keyword }}”
            @else
                Search
            @endif
        </h1>

        <form action="{{ route('products.search') }}" method="GET" class="mt-6 flex flex-wrap items-end gap-3" role="search">
            <div class="w-full sm:w-64">
                <label for="search-keyword" class="sr-only">Keyword</label>
                <input id="search-keyword" type="search" name="keyword" value="{{ request('keyword') }}"
                       placeholder="Search by keyword" class="form-input">
            </div>
            <div class="w-full sm:w-52">
                <label for="search-category" class="sr-only">Category</label>
                <select id="search-category" name="category" class="form-input">
                    <option value="">All categories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected(request('category') == $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="w-32">
                <label for="search-min" class="sr-only">Minimum price</label>
                <input id="search-min" type="number" min="0" step="0.01" name="min_price" value="{{ request('min_price') }}"
                       placeholder="Min price" class="form-input">
            </div>
            <div class="w-32">
                <label for="search-max" class="sr-only">Maximum price</label>
                <input id="search-max" type="number" min="0" step="0.01" name="max_price" value="{{ request('max_price') }}"
                       placeholder="Max price" class="form-input">
            </div>
            <button type="submit" class="btn btn-primary">Search</button>
        </form>
    </header>

    <div class="mt-10">
        <x-product-grid
            :products="$products"
            empty-title="No matching products"
            empty-message="Try a broader keyword, another category or a wider price range." />
    </div>
</div>
@endsection
