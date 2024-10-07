@extends('layouts.app')

@section('content')
<div class="container py-8">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white">
                    <h2 class="text-2xl font-bold text-center">
                        {{ $product->name }}
                    </h2>
                </div>
                <div class="card-body p-5">
                    <!-- Product Image -->
                    <div class="text-center mb-4">
                        <img src="{{ $product->imageUrl }}" alt="{{ $product->name }}" class="img-fluid rounded shadow-lg" style="max-height: 300px;">
                    </div>

                    <!-- Product Information -->
                    <div class="mb-4">
                        <h4 class="font-bold text-lg">Description:</h4>
                        <p class="text-gray-700">{{ $product->long_description }}</p>
                    </div>

                    <!-- Price and Category -->
                    <div class="mb-4">
                        <p><strong>Price:</strong> ${{ number_format($product->price, 2) }}</p>
                        <p><strong>Category:</strong> {{ $product->category->name ?? "Uncategorized" }}</p>
                        <p><strong>Inventory Count:</strong> {{ $product->inventory_count }}</p>
                    </div>

                    <!-- Stock Status -->
                    <div class="mb-4">
                        @if($product->inventory_count > 0)
                            <p class="text-success font-bold">In Stock</p>
                        @else
                            <p class="text-danger font-bold">Out of Stock</p>
                        @endif
                    </div>

                    <!-- Wishlist and Cart Buttons -->
                    <div class="mb-4">
                        @auth
                            @if(auth()->user()->wishlist()->where('product_id', $product->id)->exists())
                                <form action="{{ route('wishlist.remove', $product) }}" method="POST">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-block">Remove from Wishlist</button>
                                </form>
                            @else
                                <form action="{{ route('wishlist.add', $product) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="btn btn-primary btn-block">Add to Wishlist</button>
                                </form>
                            @endif
                        @endauth
                    </div>

                    @if($product->inventory_count > 0)
                        <form action="{{ route('cart.add', $product) }}" method="POST" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-success btn-block mt-2">Add to Cart</button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="text-center mt-4">
                <a href="{{ route('products.index') }}" class="btn btn-secondary">Back to Products</a>
            </div>

            @if(isset($recommendations) && count($recommendations) > 0)
                <div class="card mt-5 shadow-sm">
                    <div class="card-header bg-dark text-white">Recommended Products</div>
                    <div class="card-body">
                        <div class="row">
                            @foreach($recommendations as $recommendedProduct)
                                <div class="col-md-4 mb-3">
                                    <div class="card h-100">
                                        <img src="/images/placeholder.png" alt="{{ $recommendedProduct->name }}" class="card-img-top">
                                        <div class="card-body">
                                            <h5 class="card-title">{{ $recommendedProduct->name }}</h5>
                                            <p class="card-text">${{ number_format($recommendedProduct->price, 2) }}</p>
                                            <a href="{{ route('products.show', $recommendedProduct->id) }}" class="btn btn-primary btn-sm">View Product</a>
                                            @if($recommendedProduct->inventory_count == 0)
                                                <p class="text-danger mt-2">Out of Stock</p>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

@if($product->downloadable->count() > 0 && auth()->user() && auth()->user()->hasPurchased($product))
    <a href="{{ route('download.generate-link', $product->id) }}" class="btn btn-success mt-4">Download</a>
@endif

@isset($product->inventoryLogs)
<div class="card mt-5">
    <div class="card-header">Inventory Logs</div>
    <div class="card-body">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Quantity Change</th>
                    <th>Reason</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($product->inventoryLogs as $log)
                    <tr>
                        <td>{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                        <td>{{ $log->quantity_change }}</td>
                        <td>{{ $log->reason }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endisset
@endsection

@section('meta')
    <meta name="description" content="{{ $product->meta_description ?? $product->short_description }}">
    <meta name="keywords" content="{{ $product->meta_keywords }}">
    <meta property="og:title" content="{{ $product->meta_title ?? $product->name }}">
    <meta property="og:description" content="{{ $product->meta_description ?? $product->short_description }}">
    <meta property="og:image" content="{{ asset('/images/placeholder.png') }}">
    <meta property="og:url" content="{{ route('products.show', $product->id) }}">
    <meta name="twitter:card" content="summary_large_image">
@endsection
