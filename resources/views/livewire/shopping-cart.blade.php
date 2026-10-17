@php
    $currency = collect($items)->first()['currency'] ?? 'USD';
    $money = fn ($amount) => \Illuminate\Support\Number::currency($amount, $currency);
@endphp

<div class="page-container py-10">
    <h1 class="heading-2">Shopping cart</h1>

    {{-- Livewire actions don't re-render the layout's flash area, so the cart reports its own outcomes. --}}
    <div class="mt-6 space-y-3" aria-live="polite">
        @if (session()->has('error'))
            <div class="alert alert-error" role="alert">{{ session('error') }}</div>
        @endif
        @if (session()->has('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error" role="alert">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>

    @if (count($items) > 0)
        <div class="mt-6 grid gap-10 lg:grid-cols-3">
            <section class="lg:col-span-2" aria-labelledby="cart-items-heading">
                <h2 id="cart-items-heading" class="sr-only">Items in your cart</h2>

                <ul class="divide-y divide-hairline border-y border-hairline">
                    @foreach ($items as $id => $item)
                        <li class="flex gap-4 py-6" wire:key="cart-item-{{ $id }}">
                            <a href="{{ $item['url'] ?? '#' }}" class="block size-20 shrink-0 overflow-hidden rounded-lg border border-hairline bg-surface sm:size-24">
                                <img src="{{ $item['image'] ?: asset('images/placeholder-product.svg') }}" alt="" class="size-full object-cover">
                            </a>

                            <div class="flex min-w-0 grow flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <a href="{{ $item['url'] ?? '#' }}" class="font-medium text-ink hover:text-primary-700">{{ $item['name'] }}</a>
                                    <p class="data mt-1 text-label text-muted">{{ $money($item['price']) }} each</p>
                                    @if ($item['is_downloadable'])
                                        <span class="badge badge-primary mt-2">Digital</span>
                                    @endif
                                </div>

                                <div class="flex items-center gap-4 sm:flex-col sm:items-end">
                                    <div class="inline-flex items-center rounded-lg border border-stroke">
                                        <button type="button" class="btn btn-ghost btn-sm rounded-r-none px-3"
                                                wire:click="updateQuantity('{{ $id }}', {{ max(1, $item['quantity'] - 1) }})"
                                                @disabled($item['quantity'] <= 1)
                                                aria-label="Decrease quantity of {{ $item['name'] }}">&minus;</button>
                                        <label for="qty-{{ $id }}" class="sr-only">Quantity of {{ $item['name'] }}</label>
                                        <input id="qty-{{ $id }}" type="number" min="1" inputmode="numeric"
                                               class="data w-14 border-x border-stroke py-1.5 text-center text-label text-ink focus:outline-none focus:ring-2 focus:ring-primary-600"
                                               wire:model.lazy="items.{{ $id }}.quantity"
                                               wire:change="updateQuantity('{{ $id }}', $event.target.value)">
                                        <button type="button" class="btn btn-ghost btn-sm rounded-l-none px-3"
                                                wire:click="updateQuantity('{{ $id }}', {{ $item['quantity'] + 1 }})"
                                                aria-label="Increase quantity of {{ $item['name'] }}">+</button>
                                    </div>

                                    <p class="data font-semibold text-ink">{{ $money($item['price'] * $item['quantity']) }}</p>

                                    <button type="button" class="btn btn-ghost btn-sm" wire:click="removeItem('{{ $id }}')">
                                        Remove<span class="sr-only"> {{ $item['name'] }}</span>
                                    </button>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-6 flex flex-wrap items-center justify-between gap-4">
                    <a href="{{ route('products.index') }}" class="text-label font-medium text-primary-700 hover:underline">Continue shopping</a>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="clearCart" wire:confirm="Remove every item from your cart?">
                        Clear cart
                    </button>
                </div>
            </section>

            <aside class="lg:sticky lg:top-6 lg:self-start" aria-labelledby="summary-heading">
                <div class="card p-6">
                    <h2 id="summary-heading" class="text-lead font-semibold text-ink">Order summary</h2>
                    <dl class="mt-6 space-y-3 text-label">
                        <div class="flex justify-between">
                            <dt class="text-muted">Subtotal</dt>
                            <dd class="data text-ink">{{ $money($this->calculateTotal()) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted">Shipping</dt>
                            <dd class="text-muted">Calculated at checkout</dd>
                        </div>
                        <div class="flex justify-between border-t border-hairline pt-3 text-body">
                            <dt class="font-semibold text-ink">Total</dt>
                            <dd class="data font-semibold text-ink">{{ $money($this->calculateTotal()) }}</dd>
                        </div>
                    </dl>
                    <a href="{{ route('checkout.initiate') }}" class="btn btn-primary btn-lg mt-6 w-full">Proceed to checkout</a>
                </div>
            </aside>
        </div>
    @else
        <x-empty-state class="mt-6"
                       title="Your cart is empty"
                       message="Looks like you haven't added any products yet."
                       :action-url="route('products.index')"
                       action-label="Continue shopping" />
    @endif
</div>
