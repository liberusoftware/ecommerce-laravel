@extends('layouts.app')

@section('title', 'Wishlist — '.$storeName)

@section('content')
<div class="page-container py-10">
    <header class="flex flex-col gap-4 border-b border-hairline pb-8 sm:flex-row sm:items-end sm:justify-between">
        <h1 class="heading-2">My wishlist</h1>
        @if ($wishlist->isNotEmpty())
            <form action="{{ route('wishlist.share') }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-secondary">Share wishlist</button>
            </form>
        @endif
    </header>

    @if (session('share_url'))
        <div class="alert alert-success mt-6">
            <label for="share-url" class="font-medium">Your share link</label>
            <input id="share-url" type="text" readonly value="{{ session('share_url') }}" class="form-input mt-2" onfocus="this.select()">
        </div>
    @endif

    @if ($wishlist->isEmpty())
        <x-empty-state class="mt-10"
                       title="Your wishlist is empty"
                       message="Save products you like and come back to them later."
                       :action-url="route('products.index')"
                       action-label="Browse products" />
    @else
        <div class="mt-10 grid gap-x-8 gap-y-12 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @foreach ($wishlist as $item)
                <div class="flex flex-col">
                    <x-product-card :product="$item->product" />
                    <form action="{{ route('wishlist.remove', $item->product) }}" method="POST" class="mt-3">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-ghost btn-sm">
                            Remove<span class="sr-only"> {{ $item->product->name }} from wishlist</span>
                        </button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
