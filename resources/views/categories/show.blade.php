@extends('layouts.app')

@section('title', $category->meta_title ?: $category->name.' — '.$storeName)
@section('meta_description', $category->meta_description ?: Str::limit((string) $category->description, 160))
@if ($category->imageUrl())
    @section('og_image', $category->imageUrl())
@endif

@section('content')
<div class="page-container py-10">
    <nav aria-label="Breadcrumb" class="breadcrumb">
        <a href="{{ route('home') }}" class="breadcrumb-item">Home</a>
        <span class="breadcrumb-separator" aria-hidden="true">/</span>
        <a href="{{ route('categories.index') }}" class="breadcrumb-item">Categories</a>
        @if ($category->parent)
            <span class="breadcrumb-separator" aria-hidden="true">/</span>
            <a href="{{ route('categories.show', $category->parent) }}" class="breadcrumb-item">{{ $category->parent->name }}</a>
        @endif
        <span class="breadcrumb-separator" aria-hidden="true">/</span>
        <span class="text-ink" aria-current="page">{{ $category->name }}</span>
    </nav>

    <header class="mt-6 flex flex-col gap-6 border-b border-hairline pb-8 md:flex-row md:items-end md:justify-between">
        @if ($category->imageUrl())
            <img src="{{ $category->imageUrl() }}" alt="" class="aspect-square w-32 shrink-0 rounded-lg border border-hairline object-cover md:order-first">
        @endif
        <div class="grow">
            <h1 class="heading-2">{{ $category->name }}</h1>
            @if ($category->description)
                <p class="prose-measure mt-3 text-body text-muted">{{ $category->description }}</p>
            @endif
            <p class="mt-3 text-label text-muted">
                <span class="data">{{ $productCount }}</span> {{ Str::plural('product', $productCount) }}
            </p>
        </div>
        @if ($productCount > 0)
            <a href="{{ route('categories.products', $category) }}" class="btn btn-primary self-start md:self-auto">
                Shop all {{ $category->name }}
            </a>
        @endif
    </header>

    @if ($subcategories->isNotEmpty())
        <section class="mt-8" aria-labelledby="subcategories-heading">
            <h2 id="subcategories-heading" class="sr-only">Subcategories</h2>
            <ul class="flex flex-wrap gap-3">
                @foreach ($subcategories as $subcategory)
                    <li>
                        <a href="{{ route('categories.show', $subcategory) }}" class="btn btn-secondary">
                            {{ $subcategory->name }}
                            <span class="data text-caption text-muted">{{ $subcategory->products_count }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="mt-10" aria-labelledby="new-in-heading">
        <div class="flex items-baseline justify-between gap-4">
            <h2 id="new-in-heading" class="heading-3">New in {{ $category->name }}</h2>
            @if ($productCount > $featuredProducts->count())
                <a href="{{ route('categories.products', $category) }}" class="text-label font-medium text-primary-700 hover:underline">View all</a>
            @endif
        </div>

        <div class="mt-6">
            <x-product-grid
                :products="$featuredProducts"
                empty-title="No products in this category yet"
                empty-message="Check back soon, or browse the full catalogue." />
        </div>
    </section>
</div>
@endsection
