{{-- The auth shell. This component did not exist, so every view referencing
     <x-guest-layout> threw "Unable to locate a class or view for component
     [guest-layout]" — a hard 500 on password reset, email verification, the 2FA
     challenge and password confirmation.

     Deliberately not layouts.app: someone resetting a password or typing a 2FA
     code is trying to get IN, and a search field, cart and chat widget are an
     invitation to wander. The only way out of here is back to the shop.

     @livewireScripts stays because it ships Alpine, which the 2FA challenge's
     recovery-code toggle depends on. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    <title>@yield('title', $title ?? __('Account')) &middot; {{ $storeName }}</title>

    @vite(app(\App\Support\StorefrontTheme::class)->entries())
    @livewireStyles
    @stack('styles')
</head>
<body class="min-h-screen bg-white text-ink antialiased">
    <div class="flex min-h-screen flex-col items-center justify-center px-4 py-12">
        {{ $slot }}
    </div>

    @livewireScripts
    @stack('scripts')
</body>
</html>
