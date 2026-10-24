@extends('layouts.app')

@section('title', $product->meta_title ?: $product->name)
@section('meta_description', $product->meta_description ?: Str::limit(strip_tags($product->short_description ?: $product->description), 160))
@section('og_title', $product->meta_title ?: $product->name)
@section('og_description', $product->meta_description ?: Str::limit(strip_tags($product->short_description ?: $product->description), 160))
@section('og_type', 'product')

{{-- The layout only emits og:image when a view supplies one, so a product with a
     real image finally gets a real social preview. --}}
@if (filled($product->image_url))
    @section('og_image', $product->image_url)
@endif

@php
    $stock = (int) $product->inventory_count;
    $isOut = ! $product->isInStock();
    $currency = \App\Support\Money::currency();
    $gallery = collect([$product->image_url])->merge($product->images->map(fn ($image) => $image->url))->filter()->unique()->values();
@endphp

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <nav aria-label="Breadcrumb" class="mb-8">
            <ol class="flex flex-wrap items-center gap-2 text-label text-muted">
                <li><a href="{{ route('home') }}" class="hover:text-primary-700">Home</a></li>
                <li aria-hidden="true" class="text-hairline">/</li>
                <li><a href="{{ route('products.index') }}" class="hover:text-primary-700">Products</a></li>
                @if ($product->category)
                    <li aria-hidden="true" class="text-hairline">/</li>
                    <li>
                        <a href="{{ route('categories.show', $product->category) }}" class="hover:text-primary-700">
                            {{ $product->category->name }}
                        </a>
                    </li>
                @endif
                <li aria-hidden="true" class="text-hairline">/</li>
                <li><span class="text-ink" aria-current="page">{{ $product->name }}</span></li>
            </ol>
        </nav>

        <div class="grid gap-10 lg:grid-cols-2 lg:gap-16">
            {{-- Image. No placeholder.png fallback: that file does not exist, so the
                 old markup guaranteed a broken image for any product without one. --}}
            <div>
                @if ($gallery->isNotEmpty())
                    <div class="aspect-square overflow-hidden border border-hairline bg-surface">
                        <img src="{{ $gallery->first() }}"
                             alt="{{ $product->name }}"
                             id="product-main-image"
                             class="size-full object-cover">
                    </div>
                    @if ($gallery->count() > 1)
                        <ul class="mt-4 grid grid-cols-5 gap-2" aria-label="Product images">
                            @foreach ($gallery as $url)
                                <li>
                                    <button type="button"
                                            class="block aspect-square w-full overflow-hidden border border-hairline bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600"
                                            onclick="document.getElementById('product-main-image').src = this.querySelector('img').src">
                                        <img src="{{ $url }}" alt="{{ $product->name }} — image {{ $loop->iteration }}" loading="lazy" class="size-full object-cover">
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @else
                    <div class="flex aspect-square items-center justify-center border border-dashed border-stroke bg-surface">
                        <p class="text-label text-muted">No image available</p>
                    </div>
                @endif
            </div>

            <div>
                <h1 class="heading-2">{{ $product->name }}</h1>

                @if ($product->category)
                    <p class="mt-2 text-label text-muted">{{ $product->category->name }}</p>
                @endif
                @if (filled($product->sku))
                    <p class="mt-1 text-caption text-muted">SKU <span class="data">{{ $product->sku }}</span></p>
                @endif

                {{-- Price. Number::currency respects the product's own currency_code
                     instead of assuming dollars, and .data sets it in tabular mono. --}}
                <div class="mt-6">
                    @if ($product->isFree())
                        <p class="data text-headline font-semibold text-ink">Free</p>
                    @else
                        <p class="data text-headline font-semibold text-ink">
                            {{ \App\Support\Money::format($product->displayPrice()) }}
                            @if ($product->isOnSale())
                                <s class="ml-2 text-title font-normal text-muted"><span class="sr-only">Was </span>{{ \App\Support\Money::format($product->price) }}</s>
                            @endif
                        </p>
                        @if (app(\App\Services\TaxCalculator::class)->shouldDisplayPricesWithTax())
                            <p class="mt-1 text-caption text-muted">(inc. tax)</p>
                        @endif
                    @endif
                </div>

                {{-- Stock. The old markup used Bootstrap's text-success/text-danger,
                     which this project has no styles for, so status rendered as plain
                     black text. Status now carries a word as well as a colour. --}}
                <p class="mt-4 flex items-center gap-2 text-label">
                    <span class="inline-block size-2 rounded-full {{ $isOut ? 'bg-danger-600' : 'bg-primary-700' }}" aria-hidden="true"></span>
                    @if ($isOut)
                        <span class="font-medium text-danger-700">Out of stock</span>
                    @else
                        <span class="font-medium text-primary-700">In stock</span>
                        @if ($product->tracksInventory())
                            <span class="text-muted">&middot;</span>
                            <span class="text-muted"><span class="data">{{ $stock }}</span> available</span>
                        @endif
                    @endif
                </p>

                <div class="mt-8 border-t border-hairline pt-8">
                    @if ($product->isFree())
                        <a href="{{ route('download.serve-file', $product->id) }}" class="btn btn-primary btn-lg">
                            Download now
                        </a>
                    @elseif ($product->isDonationBased())
                        <form action="{{ route('cart.add', $product) }}" method="POST" class="max-w-sm">
                            @csrf
                            <label for="donation_amount" class="form-label">
                                Support this product
                                <span class="text-muted">
                                    (suggested <span class="data">{{ \App\Support\Money::format($product->suggested_price) }}</span>)
                                </span>
                            </label>
                            <input type="number"
                                   name="price"
                                   id="donation_amount"
                                   class="form-input data"
                                   value="{{ $product->suggested_price }}"
                                   min="{{ $product->minimum_price }}"
                                   step="0.01">
                            <button type="submit" class="btn btn-primary btn-lg mt-4">Support &amp; download</button>
                        </form>
                        @if ($product->minimum_price <= 0)
                            <a href="{{ route('download.serve-file', $product->id) }}" class="mt-4 inline-block text-label text-primary-700 underline underline-offset-4 hover:text-primary-600">
                                Download without donating
                            </a>
                        @endif
                    @elseif (! $isOut)
                        <form action="{{ route('cart.add', $product) }}" method="POST">
                            @csrf
                            {{-- Prices shown here are a guide; the server prices the choices. --}}
                            @foreach ($product->optionGroups as $group)
                                <fieldset class="mb-6">
                                    <legend class="form-label">
                                        {{ $group->name }}
                                        @if ($group->isRequired())
                                            <span class="text-muted">(required)</span>
                                        @elseif ($group->max_select !== null && ! $group->allowsOne())
                                            <span class="text-muted">(up to {{ $group->max_select }})</span>
                                        @endif
                                    </legend>
                                    <div class="mt-2 flex flex-col gap-2">
                                        @foreach ($group->choices as $choice)
                                            <label class="inline-flex items-center gap-2 @unless ($choice->is_available) text-muted @endunless">
                                                <input type="{{ $group->allowsOne() ? 'radio' : 'checkbox' }}"
                                                       name="options[]"
                                                       value="{{ $choice->id }}"
                                                       @if ($group->allowsOne() && $group->isRequired()) required @endif
                                                       @checked(in_array($choice->id, old('options', $product->optionGroups->flatMap->choices->where('is_default', true)->where('is_available', true)->pluck('id')->all())))
                                                       @disabled(! $choice->is_available)>
                                                <span>{{ $choice->name }}</span>
                                                @if ((float) $choice->price_adjustment != 0)
                                                    <span class="data text-muted">{{ (float) $choice->price_adjustment > 0 ? '+' : '−' }}{{ \App\Support\Money::format(abs((float) $choice->price_adjustment)) }}</span>
                                                @endif
                                                @unless ($choice->is_available)
                                                    <span class="text-muted">(unavailable)</span>
                                                @endunless
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>
                            @endforeach
                            <div class="flex flex-wrap items-end gap-4">
                                <div>
                                    <label for="quantity" class="form-label">Quantity</label>
                                    <input type="number"
                                           name="quantity"
                                           id="quantity"
                                           class="form-input data w-24"
                                           value="1"
                                           min="1"
                                           @if ($product->tracksInventory()) max="{{ $stock }}" @endif>
                                </div>
                                <button type="submit" class="btn btn-primary btn-lg">Add to cart</button>
                            </div>
                        </form>
                    @else
                        <button type="button" class="btn btn-primary btn-lg" disabled>Sold out</button>
                        <p class="mt-3 text-label text-muted">This product is currently unavailable.</p>
                    @endif

                    @auth
                        @if (Route::has('wishlist.add') && Route::has('wishlist.remove'))
                            <div class="mt-4">
                                @if (auth()->user()->wishlist()->where('product_id', $product->id)->exists())
                                    <form action="{{ route('wishlist.remove', $product) }}" method="POST">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-secondary btn-sm">Remove from wishlist</button>
                                    </form>
                                @else
                                    <form action="{{ route('wishlist.add', $product) }}" method="POST">
                                        @csrf
                                        <button type="submit" class="btn btn-secondary btn-sm">Add to wishlist</button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    @endauth
                </div>

                @if (filled($product->description))
                    <div class="mt-10 border-t border-hairline pt-8">
                        <h2 class="heading-3">Description</h2>
                        <p class="prose-measure mt-3 text-muted">
                            {{ $product->description }}
                        </p>
                    </div>
                @endif
            </div>
        </div>

        <p class="mt-12 border-t border-hairline pt-8">
            <a href="{{ route('products.index') }}" class="btn btn-secondary">Back to products</a>
        </p>
    </div>

    {{-- Structured data. Currency and image now come from the product rather than
         being hardcoded to USD and a placeholder that 404s. sku/mpn are omitted:
         products carry no SKU column, and echoing the primary key as an MPN is
         inventing an identifier that means nothing to anyone.

         The JSON_HEX_* flags are load-bearing, not formatting. This block is echoed
         raw inside a <script>, so without JSON_HEX_TAG a product named
         `</script><script>alert(1)</script>` closes the tag and executes.
         ProductShowXssTest covers exactly that — do not "tidy" these away. --}}
    @php
        $jsonLd = [
            '@context' => 'https://schema.org/',
            '@type' => 'Product',
            'name' => $product->name,
            'description' => strip_tags($product->short_description ?: $product->description ?: ''),
            'brand' => [
                '@type' => 'Brand',
                'name' => $product->store?->name ?? config('app.name'),
            ],
            'offers' => [
                '@type' => 'Offer',
                'url' => route('products.show', $product),
                'priceCurrency' => $currency,
                'price' => $product->sellingPrice(),
                'availability' => $isOut
                    ? 'https://schema.org/OutOfStock'
                    : 'https://schema.org/InStock',
                'seller' => [
                    '@type' => 'Organization',
                    'name' => $product->store?->name ?? config('app.name'),
                ],
            ],
        ];

        if ($gallery->isNotEmpty()) {
            $jsonLd['image'] = $gallery->map(fn ($url) => url($url))->all();
        }
        if (filled($product->sku)) {
            $jsonLd['sku'] = $product->sku;
        }
    @endphp
    <script type="application/ld+json">
    {!! json_encode($jsonLd, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRETTY_PRINT) !!}
    </script>
@endsection
