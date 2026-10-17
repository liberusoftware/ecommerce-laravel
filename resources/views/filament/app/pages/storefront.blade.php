<x-filament-panels::page>
    @forelse ($this->getStores() as $store)
        <x-filament::section :heading="$store->name">
            @php($domains = $store->channels->flatMap->domains)

            @if ($domains->isEmpty())
                <p>Not public yet. The platform administrator maps a domain to this store.</p>
            @else
                @foreach ($store->channels as $channel)
                    <p class="font-medium">{{ $channel->name }}</p>
                    <ul>
                        @foreach ($channel->domains as $domain)
                            <li>
                                <a href="{{ $this->getScheme() }}://{{ $domain->host }}" target="_blank" rel="noopener" class="underline">{{ $domain->host }}</a>
                                @if ($domain->is_primary)
                                    (primary)
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            @endif
        </x-filament::section>
    @empty
        <p>This team has no store yet.</p>
    @endforelse
</x-filament-panels::page>
