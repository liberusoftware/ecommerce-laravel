@extends('layouts.app')

@section('title', 'Contact')
@section('meta_description', 'Get in touch with '.$storeName.'.')

@php
    // Only what the merchant has actually configured. The page this replaced listed
    // "Knowledgebase", "Developer APIs" and "Contact sales" — Preline's SaaS template
    // copy, on a shop, with all four links pointing at "#".
    $channels = array_filter([
        'Email' => $currentStore?->email,
        'Phone' => $currentStore?->phone,
        'Address' => $currentStore ? implode(', ', $currentStore->addressLines()) : null,
    ], fn ($value) => filled($value));
@endphp

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
        <h1 class="heading-2">Contact us</h1>
        <p class="prose-measure mt-2 text-lead text-muted">
            Questions about an order, a return, or something you're thinking of buying — send it here and a person will read it.
        </p>

        @session('success')
            {{-- role=status, not alert: this is a confirmation, not a problem. --}}
            <p class="mt-8 rounded-lg border border-primary-300 bg-primary-50 px-4 py-3 font-medium text-primary-700" role="status">
                {{ $value }}
            </p>
        @endsession

        <div class="mt-12 grid gap-12 lg:grid-cols-[minmax(0,1fr)_18rem] lg:gap-16">
            <div>
                <x-validation-errors class="mb-6" />

                <form method="POST" action="{{ route('contact.send') }}" class="space-y-5">
                    @csrf

                    {{-- Honeypot. Hidden from sight and from assistive tech, and skipped in
                         the tab order, so no human can reach it — anything in it is a bot.
                         Not `type="hidden"`: bots skip those and fill visible-in-DOM ones. --}}
                    <div class="sr-only" aria-hidden="true">
                        <label for="website">Leave this field empty</label>
                        <input type="text" name="website" id="website" tabindex="-1" autocomplete="off" value="{{ old('website') }}">
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <x-label for="name" value="Your name" />
                            <x-input id="name" class="mt-1 block w-full" type="text" name="name"
                                     :value="old('name')" required autocomplete="name" />
                            <x-input-error for="name" class="mt-1" />
                        </div>

                        <div>
                            <x-label for="email" value="Email" />
                            <x-input id="email" class="mt-1 block w-full" type="email" name="email"
                                     :value="old('email')" required autocomplete="email" />
                            <x-input-error for="email" class="mt-1" />
                        </div>
                    </div>

                    <div>
                        <x-label for="subject" value="Subject" />
                        <x-input id="subject" class="mt-1 block w-full" type="text" name="subject"
                                 :value="old('subject')" autocomplete="off" />
                        <p class="mt-1 text-caption text-muted">
                            Optional. An order number here gets you a faster answer.
                        </p>
                        <x-input-error for="subject" class="mt-1" />
                    </div>

                    <div>
                        <x-label for="message" value="Message" />
                        <textarea id="message" name="message" rows="8" required
                                  class="form-input mt-1 block w-full">{{ old('message') }}</textarea>
                        <x-input-error for="message" class="mt-1" />
                    </div>

                    <div class="flex flex-wrap items-center gap-4 pt-1">
                        <x-button>Send message</x-button>
                    </div>
                </form>
            </div>

            {{-- A definition list, not four identical cards: this is a short set of
                 facts, and cards would be scaffolding around three lines of text. --}}
            @if (filled($channels))
                <aside class="lg:border-l lg:border-hairline lg:pl-10">
                    <h2 class="heading-3">Other ways to reach us</h2>

                    <dl class="mt-6 space-y-6">
                        @foreach ($channels as $label => $value)
                            <div>
                                <dt class="text-label font-medium text-ink">{{ $label }}</dt>
                                <dd class="mt-1 text-label text-muted">
                                    @if ($label === 'Email')
                                        <a href="mailto:{{ $value }}" class="break-words text-primary-700 underline underline-offset-4 hover:text-primary-600">
                                            {{ $value }}
                                        </a>
                                    @elseif ($label === 'Phone')
                                        {{-- .data: a phone number is read digit by digit and
                                             transcribed, so it gets tabular mono. --}}
                                        <a href="tel:{{ preg_replace('/[^\d+]/', '', $value) }}" class="data text-primary-700 underline underline-offset-4 hover:text-primary-600">
                                            {{ $value }}
                                        </a>
                                    @else
                                        <span class="whitespace-pre-line">{{ $value }}</span>
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </aside>
            @endif
        </div>
    </div>
@endsection
