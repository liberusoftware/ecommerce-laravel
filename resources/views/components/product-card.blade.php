@props(['product'])

@php
    // Prices, stock state and ratings come from the presenter; this view only lays them out.
    $card = app(\App\View\Storefront\ProductPresenter::class)->card($product);
    $isOut = $card['stock_state'] === 'out';
    $isLow = $card['stock_state'] === 'low';
@endphp

<article class="group flex flex-col">
    {{-- One link over image + title: one tab stop, and the accessible name comes
         from the title text, so the image carries alt="" rather than repeating it. --}}
    <a href="{{ $card['url'] }}" class="flex flex-col rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2">
        <span class="relative block aspect-square overflow-hidden rounded-lg border border-hairline bg-surface">
            <img src="{{ $card['image'] }}"
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
            {{ $card['name'] }}
        </h3>
    </a>

    @if ($card['summary'])
        <p class="mt-1 line-clamp-2 text-label text-muted">
            {{ $card['summary'] }}
        </p>
    @endif

    {{-- Ratings render only when reviews exist. Five empty stars on a shop with no
         reviews is decoration pretending to be data. --}}
    @if ($card['review_count'] > 0)
        <p class="mt-2 flex items-center gap-1.5 text-label">
            <span class="text-primary-700" aria-hidden="true">
                @for ($i = 1; $i <= 5; $i++)<span class="{{ $i <= round($card['rating']) ? '' : 'text-hairline' }}">&#9733;</span>@endfor
            </span>
            <span class="data text-caption text-muted">
                {{ number_format($card['rating'], 1) }}
            </span>
            <span class="sr-only">
                {{ number_format($card['rating'], 1) }} out of 5, {{ $card['review_count'] }} {{ Str::plural('review', $card['review_count']) }}
            </span>
            <span class="data text-caption text-muted" aria-hidden="true">({{ $card['review_count'] }})</span>
        </p>
    @endif

    {{-- mt-auto pins the price/action row to the bottom so cards of different
         title lengths still align along a common baseline. --}}
    <div class="mt-auto flex flex-wrap items-end justify-between gap-3 pt-4">
        <div class="min-w-0">
            <p class="data text-title font-semibold text-ink">
                @if ($card['from_price'])<span class="text-label font-normal text-muted">From </span>@endif{{ $card['price'] }}
                @if ($card['was_price'])
                    <s class="ml-1 text-label font-normal text-muted"><span class="sr-only">Was </span>{{ $card['was_price'] }}</s>
                @endif
            </p>

            @if ($isOut)
                <p class="text-caption text-muted">Unavailable</p>
            @elseif ($isLow)
                <p class="text-caption text-warning-700">
                    <span class="data">{{ $card['stock'] }}</span> left
                </p>
            @else
                <p class="text-caption text-muted">In stock</p>
            @endif
        </div>

        @if ($card['requires_choice'] && ! $isOut)
            {{-- A variant or a required option has to be chosen first, which happens on the product page. --}}
            <a href="{{ $card['url'] }}" class="btn btn-primary btn-sm shrink-0">
                Choose options
                <span class="sr-only">: {{ $card['name'] }}</span>
            </a>
        @else
            <form action="{{ $card['add_url'] }}" method="POST" class="shrink-0">
                @csrf
                <button type="submit" class="btn btn-primary btn-sm" @disabled($isOut)>
                    {{ $isOut ? 'Sold out' : 'Add to cart' }}
                    <span class="sr-only">: {{ $card['name'] }}</span>
                </button>
            </form>
        @endif
    </div>
</article>
