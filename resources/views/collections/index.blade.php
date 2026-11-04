@extends('layouts.app')

@section('title', 'Collections — '.$storeName)

@section('content')
<div class="page-container py-10">
    <header class="border-b border-hairline pb-8">
        <h1 class="heading-2">Collections</h1>
        <p class="mt-2 text-label text-muted">Curated selections from across the catalogue.</p>
    </header>

    @if ($collections->isEmpty())
        <x-empty-state class="mt-10" title="No collections yet" />
    @else
        <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($collections as $collection)
                <a href="{{ route('collections.show', $collection) }}" class="card card-hover block p-6 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600">
                    <h2 class="text-lead font-semibold text-ink">{{ $collection->name }}</h2>
                    @if ($collection->description)
                        <p class="mt-2 text-label text-muted">{{ $collection->description }}</p>
                    @endif
                    <p class="mt-4 text-caption text-muted">
                        <span class="data">{{ $collection->products_count }}</span> {{ Str::plural('product', $collection->products_count) }}
                    </p>
                </a>
            @endforeach
        </div>

        @if ($collections->hasPages())
            <div class="mt-12">{{ $collections->links() }}</div>
        @endif
    @endif
</div>
@endsection
