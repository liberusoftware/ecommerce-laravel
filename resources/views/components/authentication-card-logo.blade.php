{{-- The store's own wordmark, matching the navbar. This was Jetstream's stock
     indigo (#6875F5) tetrahedron — a framework's logo standing in for the
     merchant's, on the one screen where a shopper is checking they're in the
     right place before typing a password. --}}
<a href="{{ route('home') }}" class="rounded">
    <span class="block text-2xl font-bold text-primary-700">{{ $storeName }}</span>
</a>
