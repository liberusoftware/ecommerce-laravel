@extends('layouts.app')

@section('title', 'Categories — '.$storeName)

@section('content')
<div class="page-container py-10">
    <header class="border-b border-hairline pb-8">
        <h1 class="heading-2">Shop by category</h1>
        <p class="mt-2 text-label text-muted">Browse the catalogue by department.</p>
    </header>

    @if ($categories->isEmpty())
        <x-empty-state class="mt-10" title="No categories yet" message="Categories appear here once products are organised." />
    @else
        <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($categories as $category)
                @php($total = $category->products_count + $category->children->sum('products_count'))
                <article class="card card-hover flex flex-col p-6">
                    @if ($category->imageUrl())
                        <img src="{{ $category->imageUrl() }}" alt="" loading="lazy" class="mb-4 aspect-video w-full rounded object-cover">
                    @endif
                    <h2 class="text-lead font-semibold text-ink">
                        <a href="{{ route('categories.show', $category) }}" class="rounded hover:text-primary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600">
                            {{ $category->name }}
                        </a>
                    </h2>
                    @if ($category->description)
                        <p class="mt-2 text-label text-muted">{{ $category->description }}</p>
                    @endif

                    @if ($category->children->isNotEmpty())
                        <ul class="mt-4 flex flex-wrap gap-2">
                            @foreach ($category->children as $child)
                                <li>
                                    <a href="{{ route('categories.show', $child) }}" class="badge badge-primary hover:bg-primary-200">{{ $child->name }}</a>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <p class="mt-auto pt-6 text-caption text-muted">
                        <span class="data">{{ $total }}</span> {{ Str::plural('product', $total) }}
                    </p>
                </article>
            @endforeach
        </div>

        @if ($categories->hasPages())
            <div class="mt-12">{{ $categories->links() }}</div>
        @endif
    @endif
</div>
@endsection
