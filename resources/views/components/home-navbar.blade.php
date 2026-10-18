
<header class="bg-white border-b border-hairline">
    <!-- Top Bar -->
    <div class="bg-primary-950 text-white py-2">
        <div class="page-container flex justify-between items-center gap-4">
            {{-- Real contact details from settings only. The hardcoded
                 support@example.com / +1234567890 shipped as a merchant's live
                 support contact; an unset field now renders nothing instead.

                 Hidden below sm: at 390px the email and phone were squeezed until the
                 number wrapped down four lines ("+44 / 208 / 050 / 5865"). Both are
                 merchant-supplied and unbounded in length, so they get a viewport with
                 room for them; the footer and /contact carry the same details. --}}
            <div class="hidden items-center gap-4 sm:flex">
                @if (filled($currentStore?->email))
                    <a href="mailto:{{ $currentStore?->email }}" class="text-label text-ink-inverse hover:text-white flex items-center">
                        <svg class="h-4 w-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                        </svg>
                        {{ $currentStore?->email }}
                    </a>
                @endif
                @if (filled($currentStore?->phone))
                    <a href="tel:{{ preg_replace('/[^\d+]/', '', $currentStore?->phone) }}" class="text-label text-ink-inverse hover:text-white flex items-center">
                        <svg class="h-4 w-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                        </svg>
                        <span class="data">{{ $currentStore?->phone }}</span>
                    </a>
                @endif
            </div>
            <div class="flex items-center gap-4">
                @guest
                    <a href="{{ route('login') }}" class="inline-block py-1 text-label text-ink-inverse hover:text-white">Login</a>
                    <a href="{{ route('register') }}" class="inline-block py-1 text-label text-ink-inverse hover:text-white">Register</a>
                @else
                    <div class="relative group">
                        <button class="text-label text-ink-inverse hover:text-white flex items-center">
                            {{ Auth::user()->name }}
                            <svg class="ml-1 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                        <div class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-10 hidden group-hover:block">
                            <a href="{{ route('profile.show') }}" class="block px-4 py-2 text-label text-ink hover:bg-primary-50">Profile</a>
                            <a href="{{ route('orders.index') }}" class="block px-4 py-2 text-label text-ink hover:bg-primary-50">My Orders</a>
                            <a href="{{ route('wishlist.index') }}" class="block px-4 py-2 text-label text-ink hover:bg-primary-50">Wishlist</a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="block w-full text-left px-4 py-2 text-label text-ink hover:bg-primary-50">
                                    Logout
                                </button>
                            </form>
                        </div>
                    </div>
                @endguest
            </div>
        </div>
    </div>

    <!-- Main Navigation -->
    <div class="page-container py-4">
        <div class="flex items-center justify-between">
            <!-- Logo -->
            {{-- A logo is not the page heading: this was a second <h1> on every page,
                 competing with the real one and duplicating across the whole site. --}}
            {{-- Shrinkable and smaller below sm. A long store name at text-2xl plus the
                 icon row overflowed a 390px viewport; the name is merchant-supplied and
                 can be any length, so it must be allowed to truncate rather than push. --}}
            <a href="{{ route('home') }}" class="min-w-0 shrink">
                @if ($logo = $currentStore?->logoUrl())
                    <img src="{{ $logo }}" alt="{{ $storeName }}" class="h-10 w-auto max-w-48 object-contain">
                @else
                    <span class="block truncate text-lg font-bold text-primary-700 sm:text-2xl">{{ $storeName }}</span>
                @endif
            </a>

            <!-- Enhanced Search Bar -->
            <div class="hidden md:block grow mx-8">
                <div class="search-container">
                    <form action="{{ route('products.index') }}" method="GET" class="relative">
                        <div class="search-icon">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                        <label for="search-input" class="sr-only">Search products</label>
                        <input type="search"
                               name="search"
                               placeholder="Search products"
                               class="search-input"
                               autocomplete="off"
                               id="search-input">
                        <button type="submit"
                                class="absolute right-2 top-1/2 transform -translate-y-1/2 btn btn-primary btn-sm">
                            Search
                        </button>
                    </form>
                </div>
            </div>

            <!-- Enhanced Navigation Icons -->
            {{-- Tighter gaps below sm: logo + icons + auth + hamburger overflowed a
                 390px viewport by 71px at space-x-4. --}}
            <div class="flex items-center gap-1 sm:gap-3">
                <!-- Wishlist -->
                <a href="{{ route('wishlist.index') }}" 
                   class="relative p-2 text-muted hover:text-primary-700 hover:bg-primary-50 rounded-lg transition-all duration-200 group"
                   data-tooltip="Wishlist">
                    <svg class="h-6 w-6 transition-transform group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                    </svg>
                    <span class="sr-only">Wishlist</span>
                    {{-- No badge here: the count was hardcoded to 0, so it read "empty"
                         even with items saved. There is no WishlistCount component to
                         drive it (unlike the cart), and a wrong number is worse than
                         no number. --}}
                </a>

                <!-- Shopping Cart -->
                <a href="{{ route('cart.index') }}" 
                    class="relative p-2 text-muted hover:text-primary-700 hover:bg-primary-50 rounded-lg transition-all duration-200 group"
                    data-tooltip="Shopping Cart">
                    <svg class="h-6 w-6 transition-transform group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4m0 0L7 13m0 0l-2.5 5M7 13l2.5 5m0 0h8"></path>
                    </svg>
                    <span class="sr-only">Shopping cart</span>
                    {{-- Moss, not red: this is a count of things you chose, not an
                         error. Red is reserved for danger in this system. --}}
                    <span class="data absolute -top-1 -right-1 bg-primary-700 text-white text-caption rounded-full h-5 min-w-5 px-1 flex items-center justify-center">
                        <livewire:cart-count />
                    </span>
                </a>
                

                <!-- User Account -->
                @auth
                    <div class="relative dropdown">
                        <button class="flex items-center p-2 text-muted hover:text-primary-700 hover:bg-primary-50 rounded-lg transition-all duration-200"
                                data-tooltip="Account">
                            <div class="h-8 w-8 bg-primary-100 rounded-full flex items-center justify-center">
                                <span class="text-label font-medium text-primary-700">
                                    {{ substr(Auth::user()->name, 0, 1) }}
                                </span>
                            </div>
                        </button>
                        <div class="dropdown-menu hidden">
                            <div class="py-1">
                                <a href="{{ route('profile.show') }}" class="dropdown-item">
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                    </svg>
                                    Profile
                                </a>
                                <a href="{{ route('orders.index') }}" class="dropdown-item">
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                                    </svg>
                                    My Orders
                                </a>
                                <a href="{{ route('wishlist.index') }}" class="dropdown-item">
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                                    </svg>
                                    Wishlist
                                </a>
                                <hr class="my-1">
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="dropdown-item w-full text-left text-danger-600">
                                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                                        </svg>
                                        Logout
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                @else
                    {{-- Hidden below sm: the top bar already carries Login/Register,
                         so on mobile this was a duplicate control paying for itself
                         in horizontal overflow. --}}
                    <a href="{{ route('login') }}"
                       class="btn btn-secondary btn-sm hidden sm:inline-flex">
                        Login
                    </a>
                @endauth

                <!-- Mobile Menu Button -->
                <button class="md:hidden p-2 text-muted hover:text-primary-700 hover:bg-primary-50 rounded-lg transition-all duration-200" 
                        id="mobile-menu-button"
                        data-tooltip="Menu">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Enhanced Mobile Search -->
        <div class="mt-4 md:hidden">
            <div class="search-container">
                <form action="{{ route('products.index') }}" method="GET" class="relative">
                    <div class="search-icon">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </div>
                    <label for="mobile-search-input" class="sr-only">Search products</label>
                    <input type="search"
                           name="search"
                           placeholder="Search products"
                           class="search-input pr-20"
                           autocomplete="off"
                           id="mobile-search-input">
                    <button type="submit" 
                            class="absolute right-2 top-1/2 transform -translate-y-1/2 btn btn-primary btn-sm">
                        Search
                    </button>
                </form>
            </div>
        </div>

        <!-- Mobile Menu -->
        <div id="mobile-menu" class="hidden md:hidden mt-4 bg-white border-t border-hairline py-4">
            <div class="space-y-2">
                @guest
                    <a href="{{ route('login') }}" class="block px-4 py-2 text-ink hover:bg-surface rounded-md">Login</a>
                    <a href="{{ route('register') }}" class="block px-4 py-2 text-ink hover:bg-surface rounded-md">Register</a>
                @else
                    <a href="{{ route('profile.show') }}" class="block px-4 py-2 text-ink hover:bg-surface rounded-md">Profile</a>
                    <a href="{{ route('orders.index') }}" class="block px-4 py-2 text-ink hover:bg-surface rounded-md">My Orders</a>
                    <a href="{{ route('wishlist.index') }}" class="block px-4 py-2 text-ink hover:bg-surface rounded-md">Wishlist</a>
                    <form method="POST" action="{{ route('logout') }}" class="mt-2">
                        @csrf
                        <button type="submit" class="block w-full text-left px-4 py-2 text-danger-600 hover:bg-danger-50 rounded-md">
                            Logout
                        </button>
                    </form>
                @endguest
            </div>
        </div>
    </div>

    <!-- Category Navigation -->
    {{-- Surface + a hairline rather than bg-surface and an inner shadow: this system
         separates with rules, not shadows. Renders empty until the "main" menu has
         items, which is a seed/config concern rather than a layout one. --}}
    <nav class="border-t border-hairline bg-surface py-3">
        <div class="page-container">
            <x-filament-menu-builder::menu slug="main" view="components.menus.home-category-menu" />
        </div>
    </nav>
</header>

<style>
    .hide-scrollbar::-webkit-scrollbar {
        display: none;
    }
    .hide-scrollbar {
        -ms-overflow-style: none;
        scrollbar-width: none;
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Enhanced Mobile Menu Toggle
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const mobileMenu = document.getElementById('mobile-menu');

        if (mobileMenuButton && mobileMenu) {
            mobileMenuButton.addEventListener('click', function() {
                mobileMenu.classList.toggle('hidden');

                // Animate menu icon
                const icon = this.querySelector('svg');
                if (mobileMenu.classList.contains('hidden')) {
                    icon.style.transform = 'rotate(0deg)';
                } else {
                    icon.style.transform = 'rotate(90deg)';
                }
            });

            // Close mobile menu when clicking outside
            document.addEventListener('click', function(event) {
                if (!mobileMenuButton.contains(event.target) && !mobileMenu.contains(event.target)) {
                    mobileMenu.classList.add('hidden');
                    mobileMenuButton.querySelector('svg').style.transform = 'rotate(0deg)';
                }
            });
        }

        // Enhanced Dropdown Functionality
        const dropdowns = document.querySelectorAll('.dropdown');
        dropdowns.forEach(function(dropdown) {
            const button = dropdown.querySelector('button');
            const menu = dropdown.querySelector('.dropdown-menu');

            if (button && menu) {
                button.addEventListener('click', function(e) {
                    e.stopPropagation();

                    // Close other dropdowns
                    dropdowns.forEach(function(otherDropdown) {
                        if (otherDropdown !== dropdown) {
                            otherDropdown.querySelector('.dropdown-menu').classList.add('hidden');
                        }
                    });

                    // Toggle current dropdown
                    menu.classList.toggle('hidden');
                });

                // Close dropdown when clicking outside
                document.addEventListener('click', function() {
                    menu.classList.add('hidden');
                });
            }
        });

    });
</script>

{{-- The search input submits its form to /products?search=, which returns real
     results. Four simulated features used to live in the script above and are
     deliberately gone, not restyled: fetchSearchSuggestions hardcoded
     'iPhone 15 Pro' / 'Apple (45)' regardless of the catalogue and linked every
     result to '#'; addToWishlist, addToCartAnimation and quickView each faked a
     network call and reported a success that never happened. This note is a Blade
     comment so it stays in source without shipping to the browser. --}}
