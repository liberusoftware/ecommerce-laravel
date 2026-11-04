<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', $storeName)</title>
    <meta name="description" content="@yield('meta_description', $currentStore?->tagline ?? $storeName)">

    <!-- Open Graph / Social Media Meta Tags -->
    <meta property="og:site_name" content="{{ $storeName }}">
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:title" content="@yield('og_title', $storeName)">
    <meta property="og:description" content="@yield('og_description', $currentStore?->tagline ?? $storeName)">
    <meta property="og:url" content="{{ url()->current() }}">
    {{-- Emitted only when a view supplies an image: a 404 og:image is worse than none.
         asset() cannot be used here — config('app.asset_url') defaults to '/', so it
         returns a root-relative path and OpenGraph requires an absolute URL. --}}
    @hasSection('og_image')
        <meta property="og:image" content="{{ url(trim($__env->yieldContent('og_image'))) }}">
    @endif

    <!-- Styles -->
    @vite(app(\App\Support\StorefrontTheme::class)->entries())
    @livewireStyles
    @stack('styles')
</head>
{{-- Pure white ground, per The White Ground Rule: merchant product photography
     is the subject, and the page it sits on is apparatus. Not gray-50, and
     explicitly not a warm near-white. --}}
<body class="bg-white text-ink min-h-screen flex flex-col">
    <a href="#main-content"
       class="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-3 focus:font-medium focus:text-primary-700 focus:shadow-lg focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-primary-600">
        Skip to main content
    </a>

    <x-home-navbar />

    @php
        // Icon + label per level. The label is a visually-hidden text channel so the
        // severity is never carried by colour alone.
        // Success uses the brand moss, not a second green — see the palette note
        // at the top of app.css. Colours come from the token scales only.
        $flashLevels = [
            'success' => [
                'label' => 'Success',
                'icon' => 'text-primary-600',
                'button' => 'text-primary-700 hover:bg-primary-100',
                'path' => 'M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z',
            ],
            'error' => [
                'label' => 'Error',
                'icon' => 'text-danger-600',
                'button' => 'text-danger-700 hover:bg-danger-50',
                'path' => 'M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z',
            ],
            'warning' => [
                'label' => 'Warning',
                'icon' => 'text-warning-600',
                'button' => 'text-warning-700 hover:bg-warning-50',
                'path' => 'M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z',
            ],
        ];
    @endphp

    {{-- Flash messages are never auto-dismissed (WCAG 2.2.1 Timing Adjustable): a failed
         payment or stock warning must not delete itself while the user is still reading. --}}
    <div id="flash-messages" class="fixed inset-x-4 top-4 z-50 space-y-2 sm:left-auto sm:right-4 sm:max-w-md" aria-live="polite">
        @foreach ($flashLevels as $level => $style)
            @if (session($level))
                <div class="alert alert-{{ $level }} shadow-lg animate-slide-in-right" role="alert">
                    <div class="flex items-start gap-2">
                        <svg class="mt-0.5 size-5 shrink-0 {{ $style['icon'] }}" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                            <path fill-rule="evenodd" d="{{ $style['path'] }}" clip-rule="evenodd"></path>
                        </svg>
                        <p class="min-w-0 grow font-medium break-words">
                            <span class="sr-only">{{ $style['label'] }}:</span>
                            {{ session($level) }}
                        </p>
                        <button type="button"
                                data-dismiss-flash
                                aria-label="Dismiss {{ strtolower($style['label']) }} message"
                                class="-me-1 -mt-1 inline-flex size-8 shrink-0 items-center justify-center rounded {{ $style['button'] }}">
                            <svg class="size-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            @endif
        @endforeach
    </div>

    <main id="main-content" tabindex="-1" class="grow">
        @yield('content')
    </main>

    <!-- Chat Widget -->
    @livewire('chat-widget')

    @php
        $icons = ['Facebook' => 'fa-facebook', 'Instagram' => 'fa-instagram', 'X (Twitter)' => 'fa-twitter', 'YouTube' => 'fa-youtube', 'TikTok' => 'fa-tiktok'];
        $socialLinks = collect($currentStore?->socialLinks() ?? [])
            ->map(fn ($url, $name) => ['url' => $url, 'icon' => $icons[$name] ?? 'fa-link'])
            ->all();
    @endphp

    {{-- The footer is the one dark surface: a moss-tinted near-black, so it reads
         as the same material as the brand rather than as generic gray chrome. --}}
    <footer class="bg-primary-950 text-white py-8 mt-8">
        <div class="page-container">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                <div>
                    <h2 class="text-lg font-semibold mb-4">{{ $storeName }}</h2>
                    @if (filled($currentStore?->tagline))
                        <p class="text-ink-inverse">{{ $currentStore->tagline }}</p>
                    @endif
                    @if ($currentStore && $currentStore->addressLines() !== [])
                        <address class="mt-3 not-italic text-ink-inverse">{!! collect($currentStore->addressLines())->map(fn ($line) => e($line))->implode('<br>') !!}</address>
                    @endif
                </div>
                <div>
                    <h2 class="text-lg font-semibold mb-4">Quick Links</h2>
                    <ul class="space-y-2">
                        <li><a href="{{ route('home') }}" class="inline-block py-1 text-ink-inverse hover:text-white">Home</a></li>
                        <li><a href="{{ route('products.index') }}" class="inline-block py-1 text-ink-inverse hover:text-white">Products</a></li>
                        <li><a href="{{ route('contact') }}" class="inline-block py-1 text-ink-inverse hover:text-white">Contact</a></li>
                    </ul>
                </div>
                <div>
                    <h2 class="text-lg font-semibold mb-4">Customer Service</h2>
                    <ul class="space-y-2">
                        <li><a href="#" class="inline-block py-1 text-ink-inverse hover:text-white">FAQ</a></li>
                        <li><a href="#" class="inline-block py-1 text-ink-inverse hover:text-white">Shipping Policy</a></li>
                        <li><a href="#" class="inline-block py-1 text-ink-inverse hover:text-white">Return Policy</a></li>
                    </ul>
                </div>
                @if (filled($socialLinks))
                    <div>
                        <h2 class="text-lg font-semibold mb-4">Connect With Us</h2>
                        <div class="flex flex-wrap gap-1">
                            @foreach ($socialLinks as $name => $link)
                                <a href="{{ $link['url'] }}"
                                   class="inline-flex size-10 items-center justify-center rounded text-ink-inverse hover:bg-primary-900 hover:text-white">
                                    <i class="fab {{ $link['icon'] }}" aria-hidden="true"></i>
                                    <span class="sr-only">{{ $name }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
            <div class="border-t border-primary-900 mt-8 pt-4 text-center text-muted-inverse">
                <p>{{ $currentStore?->footer_text ?: '© '.date('Y').' '.$storeName }}</p>
            </div>
        </div>
    </footer>

    @livewireScripts
    @stack('scripts')

    <!-- Enhanced JavaScript for UI/UX -->
    <script>
        // Flash messages are dismissed by the user, never on a timer (WCAG 2.2.1).
        document.addEventListener('click', function (event) {
            const dismiss = event.target.closest('[data-dismiss-flash]');
            if (dismiss) {
                dismiss.closest('.alert').remove();
            }
        });

        // Guard against double submission.
        document.addEventListener('submit', function (event) {
            const form = event.target;

            // Livewire and other in-place handlers never navigate away, so disabling
            // their submit button would strand it disabled forever.
            const handledInPlace = event.defaultPrevented
                || Array.from(form.attributes).some(function (attr) {
                    return attr.name.startsWith('wire:submit');
                });

            if (handledInPlace) return;

            const submitButton = form.querySelector('button[type="submit"]');
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.setAttribute('aria-busy', 'true');
                submitButton.classList.add('loading');
            }
        });

        // Back-navigation restores the DOM verbatim, disabled button included, leaving
        // the user staring at a dead button. Re-enable whatever we locked on the way out.
        window.addEventListener('pageshow', function (event) {
            if (!event.persisted) return;
            document.querySelectorAll('button[aria-busy="true"]').forEach(function (button) {
                button.disabled = false;
                button.removeAttribute('aria-busy');
                button.classList.remove('loading');
            });
        });

        // Enhanced tooltips
        function initTooltips() {
            const tooltipElements = document.querySelectorAll('[data-tooltip]');
            tooltipElements.forEach(function(element) {
                element.addEventListener('mouseenter', function() {
                    const tooltipText = this.getAttribute('data-tooltip');
                    const tooltip = document.createElement('div');
                    tooltip.className = 'tooltip';
                    tooltip.textContent = tooltipText;
                    tooltip.id = 'tooltip-' + Math.random().toString(36).substr(2, 9);

                    document.body.appendChild(tooltip);

                    const rect = this.getBoundingClientRect();
                    tooltip.style.left = rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
                    tooltip.style.top = rect.top - tooltip.offsetHeight - 5 + 'px';

                    this.tooltipId = tooltip.id;
                });

                element.addEventListener('mouseleave', function() {
                    if (this.tooltipId) {
                        const tooltip = document.getElementById(this.tooltipId);
                        if (tooltip) {
                            tooltip.remove();
                        }
                    }
                });
            });
        }

        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', initTooltips);

        // Smooth scrolling is handled natively via CSS `scroll-behavior` below. The old
        // JS intercepted every a[href^="#"], called preventDefault(), then threw on
        // querySelector('#') for bare hash links — and it swallowed the focus move that
        // makes the skip link work.

        // Enhanced image lazy loading with fade-in effect
        function initLazyLoading() {
            const images = document.querySelectorAll('img[data-src]');
            const imageObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        img.src = img.dataset.src;
                        img.classList.remove('opacity-0');
                        img.classList.add('opacity-100');
                        observer.unobserve(img);
                    }
                });
            });

            images.forEach(img => {
                img.classList.add('opacity-0', 'transition-opacity', 'duration-300');
                imageObserver.observe(img);
            });
        }

        // Initialize lazy loading
        document.addEventListener('DOMContentLoaded', initLazyLoading);

        // Enhanced search with debouncing
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        // Add search suggestions functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInputs = document.querySelectorAll('input[name="search"]');
            searchInputs.forEach(function(input) {
                const debouncedSearch = debounce(function(query) {
                    if (query.length > 2) {
                        // Add search suggestions logic here
                        console.log('Searching for:', query);
                    }
                }, 300);

                input.addEventListener('input', function() {
                    debouncedSearch(this.value);
                });
            });
        });
    </script>

    <!-- Custom CSS for animations -->
    <style>
        @keyframes slide-in-right {
            from {
                opacity: 0;
                transform: translateX(100%);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .animate-slide-in-right {
            animation: slide-in-right 0.3s ease-out;
        }

        @keyframes fade-in-up {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fade-in-up {
            animation: fade-in-up 0.6s ease-out;
        }

        @keyframes pulse-scale {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }

        .animate-pulse-scale {
            animation: pulse-scale 2s infinite;
        }

        /* Focus ring in the brand's own colour rather than a hardcoded blue.
           :focus-visible, not :focus, so the ring answers keyboard navigation
           instead of firing on every mouse click. */
        *:focus-visible {
            outline: 2px solid var(--color-primary-600);
            outline-offset: 2px;
        }

        /* Enhanced button hover effects */
        .btn:hover {
            transform: translateY(-1px);
        }

        .btn:active {
            transform: translateY(0);
        }

        /* Enhanced card hover effects */
        .card:hover {
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }

        /* Enhanced loading spinner */
        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .spinner {
            animation: spin 1s linear infinite;
        }

        /* Enhanced skeleton loading */
        @keyframes skeleton-loading {
            0% {
                background-position: -200px 0;
            }
            100% {
                background-position: calc(200px + 100%) 0;
            }
        }

        /* Tinted toward the brand hue rather than the old neutral #f0f0f0/#e0e0e0,
           so loading states belong to the same material as everything else. */
        .skeleton {
            background: linear-gradient(
                90deg,
                var(--color-surface) 25%,
                var(--color-hairline) 50%,
                var(--color-surface) 75%
            );
            background-size: 200px 100%;
            animation: skeleton-loading 1.5s infinite;
        }

        /* Native smooth scrolling, replacing the old JS handler. Keeping it in CSS
           preserves the browser's own focus management, which is what makes the
           skip link actually land on <main> rather than merely scroll to it. */
        @media (prefers-reduced-motion: no-preference) {
            html {
                scroll-behavior: smooth;
            }
        }

        /* Motion is opt-out everywhere. Deliberately blunt: app.css declares a global
           `* { transition: all }`, so nothing here can be scoped narrowly until that
           goes. Every animation in this file is decorative or a loading affordance —
           none carry meaning that exists only in the motion. */
        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
                scroll-behavior: auto !important;
            }
        }
    </style>
</body>
</html>