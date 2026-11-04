@extends('layouts.app')

@section('title', 'Shared wishlist — '.$storeName)

@section('content')
<div class="page-container py-10">
    <header class="border-b border-hairline pb-8">
        <h1 class="heading-2">Shared wishlist</h1>
    </header>

    <div class="mt-10">
        <x-product-grid :products="$wishlist->pluck('product')->filter()"
                        empty-title="This wishlist is empty"
                        empty-message="Nothing has been saved to it yet." />
    </div>
</div>
@endsection
