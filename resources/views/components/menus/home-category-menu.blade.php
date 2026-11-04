@if(isset($menuItems) && $menuItems->isNotEmpty())
    <ul class="flex gap-6 overflow-x-auto pb-1 hide-scrollbar">
        @foreach($menuItems as $item)
            @php
                $itemPath = trim(parse_url($item->link, PHP_URL_PATH) ?? '', '/');
                $isActive = $item->route
                    ? request()->routeIs($item->route)
                    : ($itemPath === '' ? request()->path() === '/' : request()->is($itemPath));
            @endphp
            <li class="{{ $item->wrapper_class }}">
                <a
                    href="{{ $item->link }}"
                    target="{{ $item->target }}"
                    @class([
                        $item->link_class => filled($item->link_class),
                        'text-label whitespace-nowrap transition-colors' => true,
                        'font-medium text-ink' => $isActive,
                        'text-muted hover:text-primary-700' => ! $isActive,
                    ])
                    @if ($isActive) aria-current="page" @endif
                >
                    {{ $item->name }}
                </a>
            </li>
        @endforeach
    </ul>
@endif
