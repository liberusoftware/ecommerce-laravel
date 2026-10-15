@props(['product'])

@php
    $stock = (int) $product->inventory_count;
    $threshold = (int) ($product->low_stock_threshold ?? 0);

    // Product::isLowStock() reports true for sold-out stock too (0 <= threshold),
    // which is why the old card badged sold-out items "Low Stock". Split explicitly.
    $isOut = $stock <= 0;
    $isLow = ! $isOut && $stock <= $threshold;

    // review_count / rating_avg_rating come from the controller's eager loads;
    // the method calls are the fallback for any caller that didn't eager-load.
    $reviewCount = $product->review_count ?? $product->getTotalReviews();
    // An unrated product's eager-loaded average is null, which still counts as loaded.
    $rating = (float) (array_key_exists('rating_avg_rating', $product->getAttributes())
        ? $product->rating_avg_rating
        : $product->getAverageRating());

    $price = \Illuminate\Support\Number::currency($product->price, $product->currency_code ?: 'USD');
@endphp

<article class="group flex flex-col">
    {{-- One link over image + title: one tab stop, and the accessible name comes
         from the title text, so the image carries alt="" rather than repeating it. --}}
    <a href="{{ route('products.show', $product) }}" class="flex flex-col rounded-sm">
        <span class="relative block aspect-square overflow-hidden border border-hairline bg-surface">
            <img src="{{ $product->image_url }}"
                 alt=""
                 aria-hidden="true"
                 loading="lazy"
                 class="size-full object-cover transition-transform duration-300 ease-out group-hover:scale-[1.03]">

            @if ($isOut)
                <span class="product-badge">Out of stock</span>
            @elseif ($isLow)
                <span class="product-badge">Low stock</span>
            @endif
        </span>

        <h3 class="mt-4 text-body font-medium text-ink transition-colors group-hover:text-primary-700">
            {{ $product->name }}
        </h3>
    </a>

    @if (filled($product->short_description ?? $product->description))
        <p class="mt-1 line-clamp-2 text-label text-muted">
            {{ Str::limit(strip_tags($product->short_description ?: $product->description), 80) }}
        </p>
    @endif

    {{-- Ratings render only when reviews exist. Five empty stars on a shop with no
         reviews is decoration pretending to be data. --}}
    @if ($reviewCount > 0)
        <p class="mt-2 flex items-center gap-1.5 text-label">
            <span class="text-primary-700" aria-hidden="true">
                @for ($i = 1; $i <= 5; $i++)<span class="{{ $i <= round($rating) ? '' : 'text-hairline' }}">&#9733;</span>@endfor
            </span>
            <span class="data text-caption text-muted">
                {{ number_format($rating, 1) }}
            </span>
            <span class="sr-only">
                {{ number_format($rating, 1) }} out of 5, {{ $reviewCount }} {{ Str::plural('review', $reviewCount) }}
            </span>
            <span class="data text-caption text-muted" aria-hidden="true">({{ $reviewCount }})</span>
        </p>
    @endif

    {{-- mt-auto pins the price/action row to the bottom so cards of different
         title lengths still align along a common baseline. --}}
    <div class="mt-auto flex items-end justify-between gap-3 pt-4">
        <div class="min-w-0">
            <p class="data text-title font-semibold text-ink">{{ $price }}</p>

            @if ($isOut)
                <p class="text-caption text-muted">Unavailable</p>
            @elseif ($isLow)
                <p class="text-caption text-warning-700">
                    <span class="data">{{ $stock }}</span> left
                </p>
            @else
                <p class="text-caption text-muted">In stock</p>
            @endif
        </div>

        <form action="{{ route('cart.add', $product) }}" method="POST" class="shrink-0">
            @csrf
            <button type="submit" class="btn btn-primary btn-sm" @disabled($isOut)>
                {{ $isOut ? 'Sold out' : 'Add to cart' }}
                <span class="sr-only">: {{ $product->name }}</span>
            </button>
        </form>
    </div>
</article>
