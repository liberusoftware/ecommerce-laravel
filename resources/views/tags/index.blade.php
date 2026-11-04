@extends('layouts.app')

@section('title', 'Tags — '.$storeName)

@section('content')
<div class="page-container py-10">
    <header class="border-b border-hairline pb-8">
        <h1 class="heading-2">Tags</h1>
    </header>

    @if ($tags->isEmpty())
        <x-empty-state class="mt-10" title="No tags yet" />
    @else
        <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($tags as $tag)
                <a href="{{ route('tags.show', $tag) }}" class="card card-hover block p-6 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600">
                    <h2 class="text-lead font-semibold text-ink">{{ $tag->name }}</h2>
                    @if ($tag->description)
                        <p class="mt-2 text-label text-muted">{{ $tag->description }}</p>
                    @endif
                    <p class="mt-4 text-caption text-muted">
                        <span class="data">{{ $tag->products_count }}</span> {{ Str::plural('product', $tag->products_count) }}
                    </p>
                </a>
            @endforeach
        </div>

        @if ($tags->hasPages())
            <div class="mt-12">{{ $tags->links() }}</div>
        @endif
    @endif
</div>
@endsection
