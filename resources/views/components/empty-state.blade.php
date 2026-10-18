@props([
    'title',
    'message' => null,
    'actionUrl' => null,
    'actionLabel' => null,
])

<div {{ $attributes->merge(['class' => 'rounded-xl border border-dashed border-stroke px-6 py-16 text-center']) }}>
    <p class="text-lead font-semibold text-ink">{{ $title }}</p>

    @if ($message)
        <p class="mx-auto mt-2 max-w-md text-label text-muted">{{ $message }}</p>
    @endif

    @if ($actionUrl && $actionLabel)
        <a href="{{ $actionUrl }}" class="btn btn-primary mt-6">{{ $actionLabel }}</a>
    @endif

    {{ $slot }}
</div>
