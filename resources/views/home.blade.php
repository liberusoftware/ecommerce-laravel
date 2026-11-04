@extends('layouts.app')

@section('title', 'Home')

@section('content')
    {{-- Hero. Type-led on the white ground: no gradient text, no floating blobs,
         and no "Free Shipping / 30-Day Returns" trust badges — those were claims
         made on behalf of a merchant whose policies we don't know. --}}
    <section class="border-b border-hairline">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <h1 class="heading-1 max-w-4xl">{{ $storeName }}</h1>

            <p class="prose-measure mt-6 text-lead text-muted">
                Everything in the catalogue, listed plainly. Prices and stock are live.
            </p>

            <div class="mt-10 flex flex-wrap gap-3">
                <a href="{{ route('products.index') }}" class="btn btn-primary btn-lg">
                    Browse all products
                </a>
                @if ($categories->isNotEmpty())
                    <a href="#categories" class="btn btn-secondary btn-lg">
                        Shop by category
                    </a>
                @endif
            </div>
        </div>
    </section>

    {{-- The category index. A catalogue's contents page, not four identical cards:
         real categories from the database, each with its real product count set in
         mono so the numbers form a column you can read down. --}}
    @if ($categories->isNotEmpty())
        <section id="categories" class="border-b border-hairline">
            <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
                <h2 class="heading-2">Shop by category</h2>

                <ul class="mt-8 grid gap-x-12 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($categories as $category)
                        <li>
                            <a href="{{ route('categories.show', $category) }}"
                               class="group flex items-baseline justify-between gap-4 border-b border-hairline py-4 transition-colors hover:border-primary-700">
                                <span class="font-medium text-ink transition-colors group-hover:text-primary-700">
                                    {{ $category->name }}
                                </span>
                                <span class="data text-label text-muted transition-colors group-hover:text-primary-700">
                                    {{ $category->products_count }}<span class="sr-only"> {{ Str::plural('product', $category->products_count) }}</span>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    {{-- Featured. Real is_featured data — the controller always fetched this and the
         old view simply never rendered it. Wider cards than the Latest grid, so the
         two product rails don't read as the same block twice. --}}
    @if ($featuredProducts->isNotEmpty())
        <section class="border-b border-hairline">
            <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
                <h2 class="heading-2">Featured</h2>

                <div class="mt-8 grid gap-x-8 gap-y-12 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($featuredProducts as $product)
                        <x-product-card :product="$product" />
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- Latest arrivals. --}}
    <section>
        <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <h2 class="heading-2">Latest arrivals</h2>
                <a href="{{ route('products.index') }}" class="btn btn-secondary">
                    View all products
                </a>
            </div>

            @if ($latestProducts->isEmpty())
                {{-- A real empty state. The shop genuinely has nothing yet; say so
                     rather than rendering an empty grid, which is what shipped before. --}}
                <div class="mt-8 border border-dashed border-stroke px-6 py-16 text-center">
                    <p class="text-lead font-medium text-ink">No products yet</p>
                    <p class="prose-measure mx-auto mt-2 text-muted">
                        Once products are added in the admin panel, the newest ones appear here.
                    </p>
                </div>
            @else
                <div class="mt-8 grid gap-x-8 gap-y-12 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach ($latestProducts as $product)
                        <x-product-card :product="$product" />
                    @endforeach
                </div>
            @endif
        </div>
    </section>
@endsection
