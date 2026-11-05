{{-- The auth block. Flat on the white ground with a hairline border rather than the
     old bg-gray-100 page + shadowed card: this system separates with rules, not
     shadows, and The White Ground Rule owns the body. The dark: variants are gone
     because the dark theme they referenced never applied to anything. --}}
<div class="w-full max-w-md">
    <div class="mb-8 flex justify-center">
        {{ $logo }}
    </div>

    <div class="rounded-lg border border-hairline bg-white px-6 py-8 sm:px-8">
        {{ $slot }}
    </div>

    <p class="mt-6 text-center text-label">
        {{-- inline-block + py-1: a standalone link, so it owes WCAG 2.5.8's 24px
             and can't claim the inline-in-a-sentence exception. --}}
        <a href="{{ route('home') }}" class="inline-block rounded py-1 text-muted underline underline-offset-4 hover:text-primary-700">
            {{ __('Back to :store', ['store' => $storeName]) }}
        </a>
    </p>
</div>
