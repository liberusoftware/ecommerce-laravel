@extends('layouts.app')

@section('title', 'Checkout')

@section('content')
<div class="min-h-screen bg-surface py-8">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Page Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-ink">Checkout</h1>
            <p class="text-muted mt-1">Complete your order securely</p>
        </div>

        <!-- Progress Steps -->
        <div class="flex items-center justify-center mb-8">
            <div class="flex items-center space-x-4 text-sm">
                <div class="flex items-center text-muted">
                    <div class="w-8 h-8 rounded-full bg-primary-700 flex items-center justify-center mr-2">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                    <span class="text-primary-700 font-medium">Cart</span>
                </div>
                <div class="w-12 h-0.5 bg-primary-700"></div>
                <div class="flex items-center text-primary-700">
                    <div class="w-8 h-8 rounded-full bg-primary-700 flex items-center justify-center mr-2">
                        <span class="text-white font-bold text-xs">2</span>
                    </div>
                    <span class="font-semibold">Checkout</span>
                </div>
                <div class="w-12 h-0.5 bg-hairline"></div>
                <div class="flex items-center text-muted">
                    <div class="w-8 h-8 rounded-full bg-hairline flex items-center justify-center mr-2">
                        <span class="text-muted font-bold text-xs">3</span>
                    </div>
                    <span>Confirmation</span>
                </div>
            </div>
        </div>

        <!-- Validation Errors -->
        @if ($errors->any())
            <div class="mb-6 bg-danger-50 border border-danger-600 rounded-lg p-4">
                <div class="flex items-start">
                    <svg class="w-5 h-5 text-danger-600 mt-0.5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                    </svg>
                    <div>
                        <h3 class="text-sm font-semibold text-danger-700 mb-1">Please fix the following errors:</h3>
                        <ul class="list-disc list-inside space-y-1">
                            @foreach ($errors->all() as $error)
                                <li class="text-sm text-danger-700">{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif

        <div class="lg:grid lg:grid-cols-12 lg:gap-8">

            <!-- Main Form (Left Column) -->
            <div class="lg:col-span-7">
                <form action="{{ route('checkout.process') }}" method="POST" id="checkout-form">
                    @csrf
                    <input type="hidden" name="has_physical_products" value="{{ $hasPhysicalProducts ? 1 : 0 }}">
                    <input type="hidden" name="shipping_cost" id="shipping_cost_input" value="0.00">

                    <!-- Contact Information -->
                    <div class="bg-white rounded-xl border border-hairline mb-6">
                        <div class="px-6 py-4 border-b border-hairline">
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-full bg-primary-100 flex items-center justify-center mr-3">
                                    <svg class="w-4 h-4 text-primary-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                    </svg>
                                </div>
                                <h2 class="text-lg font-semibold text-ink">Contact Information</h2>
                            </div>
                        </div>
                        <div class="px-6 py-5">
                            <div>
                                <label for="email" class="block text-sm font-medium text-ink mb-1.5">
                                    Email Address <span class="text-danger-600">*</span>
                                </label>
                                <input
                                    type="email"
                                    id="email"
                                    name="email"
                                    value="{{ old('email') }}"
                                    required
                                    placeholder="you@example.com"
                                    class="w-full px-4 py-2.5 border border-stroke rounded-lg text-ink placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary-600 focus:border-transparent transition-colors @error('email') border-danger-600 @enderror"
                                >
                                @error('email')
                                    <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </div>

                    @if($hasPhysicalProducts)
                    <!-- Shipping Information -->
                    <div class="bg-white rounded-xl border border-hairline mb-6">
                        <div class="px-6 py-4 border-b border-hairline">
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-full bg-primary-100 flex items-center justify-center mr-3">
                                    <svg class="w-4 h-4 text-primary-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    </svg>
                                </div>
                                <h2 class="text-lg font-semibold text-ink">Shipping Information</h2>
                            </div>
                        </div>
                        <div class="px-6 py-5 space-y-5">
                            <div>
                                <label for="shipping_name" class="block text-sm font-medium text-ink mb-1.5">Full name</label>
                                <input type="text" id="shipping_name" name="shipping_name" value="{{ old('shipping_name', auth()->user()?->name) }}" autocomplete="name"
                                    class="w-full px-4 py-2.5 border border-stroke rounded-lg text-ink focus:outline-none focus:ring-2 focus:ring-primary-600 @error('shipping_name') border-danger-600 @enderror">
                                @error('shipping_name')
                                    <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="shipping_address" class="block text-sm font-medium text-ink mb-1.5">
                                    Shipping Address <span class="text-danger-600">*</span>
                                </label>
                                <textarea
                                    id="shipping_address"
                                    name="shipping_address"
                                    required
                                    rows="3"
                                    placeholder="House number and street"
                                    class="w-full px-4 py-2.5 border border-stroke rounded-lg text-ink placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary-600 focus:border-transparent transition-colors resize-none @error('shipping_address') border-danger-600 @enderror"
                                >{{ old('shipping_address') }}</textarea>
                                @error('shipping_address')
                                    <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label for="country" class="block text-sm font-medium text-ink mb-1.5">
                                        Country <span class="text-danger-600">*</span>
                                    </label>
                                    <input type="text" id="country" name="country" maxlength="2"
                                        placeholder="ISO code, e.g. US, GB"
                                        value="{{ old('country') }}"
                                        class="w-full px-4 py-2.5 border border-stroke rounded-lg text-ink uppercase focus:outline-none focus:ring-2 focus:ring-primary-600 @error('country') border-danger-600 @enderror">
                                    @error('country')
                                        <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div>
                                    <label for="state" class="block text-sm font-medium text-ink mb-1.5">State / Province</label>
                                    <input type="text" id="state" name="state" value="{{ old('state') }}"
                                        class="w-full px-4 py-2.5 border border-stroke rounded-lg text-ink focus:outline-none focus:ring-2 focus:ring-primary-600">
                                </div>
                                <div>
                                    <label for="city" class="block text-sm font-medium text-ink mb-1.5">City</label>
                                    <input type="text" id="city" name="city" value="{{ old('city') }}"
                                        class="w-full px-4 py-2.5 border border-stroke rounded-lg text-ink focus:outline-none focus:ring-2 focus:ring-primary-600 @error('city') border-danger-600 @enderror">
                                    @error('city')
                                        <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div>
                                    <label for="postal_code" class="block text-sm font-medium text-ink mb-1.5">Postal / ZIP code</label>
                                    <input type="text" id="postal_code" name="postal_code" value="{{ old('postal_code') }}"
                                        class="w-full px-4 py-2.5 border border-stroke rounded-lg text-ink focus:outline-none focus:ring-2 focus:ring-primary-600 @error('postal_code') border-danger-600 @enderror">
                                    @error('postal_code')
                                        <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div>
                                <label for="shipping_method" class="block text-sm font-medium text-ink mb-1.5">
                                    Shipping Method <span class="text-danger-600">*</span>
                                </label>
                                <div class="space-y-2">
                                    @foreach($shippingMethods as $method)
                                        <label class="flex items-center justify-between p-4 border border-hairline rounded-lg cursor-pointer hover:border-primary-300 hover:bg-primary-50 transition-colors has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50">
                                            <div class="flex items-center">
                                                <input
                                                    type="radio"
                                                    name="shipping_method_id"
                                                    id="shipping_method_{{ $method->id }}"
                                                    value="{{ $method->id }}"
                                                    data-base-rate="{{ $method->base_rate }}"
                                                    class="h-4 w-4 text-primary-700 border-stroke focus:ring-primary-600"
                                                    {{ $loop->first ? 'checked' : '' }}
                                                >
                                                <div class="ml-3">
                                                    <span class="text-sm font-medium text-ink">{{ $method->name }}</span>
                                                    @if(isset($method->estimated_delivery_time))
                                                        <span class="text-xs text-muted ml-2">{{ $method->estimated_delivery_time }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                            <span class="text-sm font-semibold text-ink">{{ \App\Support\Money::format($method->base_rate) }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('shipping_method_id')
                                    <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Live carrier rates (progressive enhancement; falls back to the flat methods above) -->
                            <div>
                                <button type="button" id="get-live-rates"
                                    class="text-sm font-medium text-primary-700 hover:text-primary-800">
                                    Get live carrier rates
                                </button>
                                <p id="live-rates-status" class="mt-1 text-xs text-muted hidden"></p>
                                <div id="live-rates-container" class="space-y-2 mt-2"></div>
                            </div>

                            <!-- Drop Shipping Option -->
                            <div class="pt-2 border-t border-hairline">
                                <label class="flex items-start cursor-pointer group">
                                    <div class="flex items-center h-5 mt-0.5">
                                        <input
                                            type="checkbox"
                                            id="dropship"
                                            name="dropship"
                                            class="h-4 w-4 rounded border-stroke text-primary-700 focus:ring-primary-600"
                                        >
                                    </div>
                                    <div class="ml-3">
                                        <span class="text-sm font-medium text-ink group-hover:text-ink">Ship directly to recipient</span>
                                        <p class="text-xs text-muted mt-0.5">Enable drop shipping to send directly to a third party</p>
                                    </div>
                                </label>
                            </div>

                            <!-- Dropship Supplier (hidden by default) -->
                            <div id="dropship-supplier" class="hidden pt-2">
                                <label for="supplier_id" class="block text-sm font-medium text-ink mb-1.5">Select Supplier</label>
                                <select
                                    id="supplier_id"
                                    name="supplier_id"
                                    class="w-full px-4 py-2.5 border border-stroke rounded-lg text-ink focus:outline-none focus:ring-2 focus:ring-primary-600 focus:border-transparent transition-colors"
                                >
                                    @foreach(config('dropshipping.suppliers') as $supplierId => $supplier)
                                        <option value="{{ $supplierId }}">{{ $supplier['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <!-- Recipient Info (hidden by default) -->
                            <div id="recipient-info" class="hidden space-y-4 pt-2 border-t border-hairline">
                                <h3 class="text-sm font-semibold text-ink">Recipient Details</h3>
                                <div>
                                    <label for="recipient_name" class="block text-sm font-medium text-ink mb-1.5">Recipient Name</label>
                                    <input
                                        type="text"
                                        id="recipient_name"
                                        name="recipient_name"
                                        value="{{ old('recipient_name') }}"
                                        placeholder="Full name"
                                        class="w-full px-4 py-2.5 border border-stroke rounded-lg text-ink placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary-600 focus:border-transparent transition-colors"
                                    >
                                </div>
                                <div>
                                    <label for="recipient_email" class="block text-sm font-medium text-ink mb-1.5">Recipient Email</label>
                                    <input
                                        type="email"
                                        id="recipient_email"
                                        name="recipient_email"
                                        value="{{ old('recipient_email') }}"
                                        placeholder="recipient@example.com"
                                        class="w-full px-4 py-2.5 border border-stroke rounded-lg text-ink placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary-600 focus:border-transparent transition-colors"
                                    >
                                </div>
                                <div>
                                    <label for="gift_message" class="block text-sm font-medium text-ink mb-1.5">Gift Message <span class="text-muted font-normal">(Optional)</span></label>
                                    <textarea
                                        id="gift_message"
                                        name="gift_message"
                                        rows="2"
                                        placeholder="Add a personal message..."
                                        class="w-full px-4 py-2.5 border border-stroke rounded-lg text-ink placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-primary-600 focus:border-transparent transition-colors resize-none"
                                    >{{ old('gift_message') }}</textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    @else
                    <!-- Billing Location — a digital-only order still needs a country so it can be taxed. -->
                    <div class="bg-white rounded-2xl border border-hairline overflow-hidden">
                        <div class="px-6 py-4 border-b border-hairline">
                            <h2 class="text-lg font-semibold text-ink">Billing Location</h2>
                            <p class="text-sm text-muted mt-0.5">Used to calculate tax on your order.</p>
                        </div>
                        <div class="px-6 py-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="country" class="block text-sm font-medium text-ink mb-1.5">Country <span class="text-danger-600">*</span></label>
                                <input type="text" id="country" name="country" maxlength="2" required placeholder="ISO code, e.g. US, GB" value="{{ old('country') }}"
                                    class="w-full px-4 py-2.5 border border-stroke rounded-lg text-ink uppercase focus:outline-none focus:ring-2 focus:ring-primary-600 @error('country') border-danger-600 @enderror">
                                @error('country')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="state" class="block text-sm font-medium text-ink mb-1.5">State / Province</label>
                                <input type="text" id="state" name="state" value="{{ old('state') }}"
                                    class="w-full px-4 py-2.5 border border-stroke rounded-lg text-ink focus:outline-none focus:ring-2 focus:ring-primary-600">
                            </div>
                            <div>
                                <label for="postal_code" class="block text-sm font-medium text-ink mb-1.5">Postal / ZIP code</label>
                                <input type="text" id="postal_code" name="postal_code" value="{{ old('postal_code') }}"
                                    class="w-full px-4 py-2.5 border border-stroke rounded-lg text-ink focus:outline-none focus:ring-2 focus:ring-primary-600">
                            </div>
                        </div>
                    </div>
                    @endif

                    @if($total > 0)
                    <!-- Payment Method -->
                    <div class="bg-white rounded-xl border border-hairline mb-6">
                        <div class="px-6 py-4 border-b border-hairline">
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-full bg-primary-100 flex items-center justify-center mr-3">
                                    <svg class="w-4 h-4 text-primary-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                                    </svg>
                                </div>
                                <h2 class="text-lg font-semibold text-ink">Payment Method</h2>
                            </div>
                        </div>
                        <div class="px-6 py-5 space-y-4">
                            @php($stripeEnabled = in_array('stripe', $paymentGateways, true))
                            @php($paypalEnabled = in_array('paypal', $paymentGateways, true))
                            <!-- Payment Method Tabs -->
                            <div class="flex rounded-lg border border-hairline p-1 bg-surface gap-1">
                                @if ($stripeEnabled)
                                <button
                                    type="button"
                                    id="tab-stripe"
                                    data-method="stripe"
                                    onclick="selectPaymentMethod('stripe')"
                                    class="flex-1 flex items-center justify-center gap-2 py-2.5 px-4 rounded-md text-sm font-medium transition-all"
                                >
                                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none">
                                        <rect width="24" height="24" rx="4" fill="#635BFF"/>
                                        <path d="M11.5 9.5c0-.83.67-1.5 1.5-1.5.69 0 1.27.47 1.45 1.1l1.55-.45C15.6 7.5 14.65 7 13.5 7c-1.66 0-3 1.34-3 3 0 2.21 2.55 3 3.5 3.5v-1.52c-.75-.37-2.5-1-2.5-2.48z" fill="white"/>
                                    </svg>
                                    Credit Card
                                </button>
                                @endif
                                @if ($paypalEnabled)
                                <button
                                    type="button"
                                    id="tab-paypal"
                                    data-method="paypal"
                                    onclick="selectPaymentMethod('paypal')"
                                    class="flex-1 flex items-center justify-center gap-2 py-2.5 px-4 rounded-md text-sm font-medium transition-all"
                                >
                                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M7.076 21.337H2.47a.641.641 0 0 1-.633-.74L4.944.901C5.026.382 5.474 0 5.998 0h7.46c2.57 0 4.578.543 5.69 1.81 1.01 1.15 1.304 2.42 1.012 4.287-.023.143-.047.288-.077.437-.983 5.05-4.349 6.797-8.647 6.797h-2.19c-.524 0-.968.382-1.05.9l-1.12 7.106zm14.146-14.42a3.35 3.35 0 0 0-.607-.541c-.013.076-.026.175-.041.254-.93 4.778-4.005 7.201-9.138 7.201h-2.19a.563.563 0 0 0-.556.479l-1.187 7.527h-.506l-.24 1.516a.56.56 0 0 0 .554.647h3.882c.46 0 .85-.334.922-.788.06-.26.76-4.852.816-5.09a.932.932 0 0 1 .923-.788h.58c3.76 0 6.705-1.528 7.565-5.946.36-1.847.174-3.388-.777-4.471z"/>
                                    </svg>
                                    PayPal
                                </button>
                                @endif
                            </div>
                            <input type="hidden" id="payment_method" name="payment_method" value="{{ $paymentGateways[0] ?? '' }}">
                            @if ($paymentGateways === [])
                                <p class="text-sm text-danger-600">This store is not taking payments online at the moment.</p>
                            @endif

                            @if ($stripeEnabled)
                            <!-- Stripe Payment Form -->
                            <div id="stripe-payment" class="space-y-3">
                                <div>
                                    <label class="block text-sm font-medium text-ink mb-1.5">Card Details</label>
                                    <div
                                        id="card-element"
                                        class="w-full px-4 py-3 border border-stroke rounded-lg bg-white focus-within:ring-2 focus-within:ring-primary-600 focus-within:border-transparent transition-colors"
                                    >
                                        <!-- Stripe Elements Placeholder -->
                                    </div>
                                    <div id="card-errors" role="alert" class="mt-2 text-sm text-danger-600 flex items-center gap-1 min-h-5">
                                        <svg id="card-error-icon" class="w-4 h-4 hidden flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                                        </svg>
                                        <span id="card-error-message"></span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 text-xs text-muted">
                                    <svg class="w-4 h-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                    </svg>
                                    Your payment information is encrypted and secure
                                </div>
                            </div>
                            @endif

                            @if ($paypalEnabled)
                            <!-- PayPal Payment -->
                            <div id="paypal-payment" class="hidden">
                                <div id="paypal-button-container" class="min-h-12"></div>
                                <input type="hidden" name="paypal_payment_id" id="paypal_payment_id">
                            </div>
                            @endif
                        </div>
                    </div>
                    @endif

                    <!-- Submit Button -->
                    <button
                        type="submit"
                        id="submit-button"
                        class="w-full bg-primary-700 hover:bg-primary-600 active:bg-primary-600 text-white font-semibold py-4 px-6 rounded-xl transition-colors duration-200 flex items-center justify-center gap-2 text-base disabled:opacity-60 disabled:cursor-not-allowed"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        {{ $total > 0 ? 'Complete Purchase' : 'Complete Order' }}
                    </button>

                    <p class="text-center text-xs text-muted mt-3">
                        By placing your order, you agree to our
                        <a href="#" class="text-primary-700 hover:underline">Terms of Service</a> and
                        <a href="#" class="text-primary-700 hover:underline">Privacy Policy</a>.
                    </p>
                </form>
            </div>

            <!-- Order Summary (Right Column) -->
            <div class="lg:col-span-5 mt-8 lg:mt-0">
                <div class="bg-white rounded-xl border border-hairline sticky top-6">
                    <div class="px-6 py-4 border-b border-hairline">
                        <div class="flex items-center">
                            <div class="w-8 h-8 rounded-full bg-primary-100 flex items-center justify-center mr-3">
                                <svg class="w-4 h-4 text-primary-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                                </svg>
                            </div>
                            <h2 class="text-lg font-semibold text-ink">Order Summary</h2>
                        </div>
                    </div>

                    <!-- Cart Items -->
                    <div class="px-6 py-4 divide-y divide-hairline max-h-72 overflow-y-auto">
                        @foreach($cart as $productId => $item)
                            <div class="py-3 flex items-start gap-3">
                                <div class="w-10 h-10 rounded-lg bg-surface flex items-center justify-center flex-shrink-0">
                                    <svg class="w-5 h-5 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                    </svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-ink truncate">{{ $item['name'] }}</p>
                                    @if (! empty($item['variant']))
                                        <p class="text-xs text-muted mt-0.5">{{ $item['variant'] }}</p>
                                    @endif
                                    <p class="text-xs text-muted mt-0.5">Qty: {{ $item['quantity'] }}</p>
                                </div>
                                <span class="text-sm font-semibold text-ink flex-shrink-0">
                                    {{ \App\Support\Money::format($item['price'] * $item['quantity']) }}
                                </span>
                            </div>
                        @endforeach
                    </div>

                    <!-- Totals -->
                    <div class="px-6 py-4 border-t border-hairline space-y-3">
                        <div class="flex justify-between text-sm text-muted">
                            <span>Subtotal</span>
                            <span>{{ \App\Support\Money::format($total) }}</span>
                        </div>

                        @if($hasPhysicalProducts)
                            <div class="flex justify-between text-sm text-muted" id="shipping-cost">
                                <span>Shipping</span>
                                <span>{{ \App\Support\Money::format(0) }}</span>
                            </div>
                        @endif

                        <div class="flex justify-between text-base font-bold text-ink pt-3 border-t border-hairline">
                            <span>Total</span>
                            <span id="total-amount" data-amount="{{ number_format($total, 2, '.', '') }}">{{ \App\Support\Money::format($total) }}</span>
                        </div>
                    </div>

                    <!-- Trust Badges -->
                    <div class="px-6 py-4 border-t border-hairline bg-surface rounded-b-xl">
                        <div class="flex items-center justify-center gap-6 text-xs text-muted">
                            <div class="flex items-center gap-1.5">
                                <svg class="w-4 h-4 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                </svg>
                                Secure Checkout
                            </div>
                            <div class="flex items-center gap-1.5">
                                <svg class="w-4 h-4 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                                </svg>
                                Buyer Protection
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Toggle drop shipping recipient info
        const dropshipCheckbox = document.getElementById('dropship');
        const recipientInfo = document.getElementById('recipient-info');
        const dropshipSupplier = document.getElementById('dropship-supplier');

        if (dropshipCheckbox) {
            dropshipCheckbox.addEventListener('change', function() {
                const isChecked = this.checked;
                recipientInfo.classList.toggle('hidden', !isChecked);
                dropshipSupplier.classList.toggle('hidden', !isChecked);
            });
        }

        // Update shipping cost when shipping method changes
        const shippingMethodRadios = document.querySelectorAll('input[name="shipping_method_id"]');
        const shippingCostElement = document.querySelector('#shipping-cost span');
        const totalAmountElement = document.getElementById('total-amount');
        const shippingCostInput = document.getElementById('shipping_cost_input');
        let subtotal = {{ $total }};
        const money = new Intl.NumberFormat(@json(str_replace('_', '-', app()->getLocale())), { style: 'currency', currency: @json($currency) });

        function updateShippingDisplay(rate) {
            if (shippingCostElement) {
                shippingCostElement.textContent = money.format(rate);
            }
            if (shippingCostInput) {
                shippingCostInput.value = rate.toFixed(2);
            }
            if (totalAmountElement) {
                const total = subtotal + rate;
                totalAmountElement.textContent = money.format(total);
                totalAmountElement.dataset.amount = total.toFixed(2);
            }
        }

        shippingMethodRadios.forEach(function(radio) {
            radio.addEventListener('change', function() {
                const shippingRate = parseFloat(this.dataset.baseRate) || 0;
                updateShippingDisplay(shippingRate);
            });
        });

        // Set initial shipping cost from checked radio
        const checkedRadio = document.querySelector('input[name="shipping_method_id"]:checked');
        if (checkedRadio) {
            updateShippingDisplay(parseFloat(checkedRadio.dataset.baseRate) || 0);
        }

        // Live carrier rates: fetch server-persisted quotes; the buyer selects one by id
        // (posted as shipping_quote_id, the server bills the stored amount).
        const liveRatesBtn = document.getElementById('get-live-rates');
        const liveRatesContainer = document.getElementById('live-rates-container');
        const liveRatesStatus = document.getElementById('live-rates-status');
        const csrfToken = document.querySelector('input[name="_token"]')?.value;

        function setStatus(text) {
            liveRatesStatus.textContent = text;
            liveRatesStatus.classList.toggle('hidden', !text);
        }

        // Choosing a flat method clears any live selection — an order ships on one source.
        shippingMethodRadios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                liveRatesContainer.querySelectorAll('input[name="shipping_quote_id"]').forEach(function (r) { r.checked = false; });
            });
        });

        if (liveRatesBtn) {
            liveRatesBtn.addEventListener('click', function () {
                const country = document.getElementById('country')?.value;
                if (!country) { setStatus('Enter your country first.'); return; }
                setStatus('Fetching rates…');

                fetch('{{ route('checkout.shipping-rates') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({
                        country: country,
                        state: document.getElementById('state')?.value || null,
                        city: document.getElementById('city')?.value || null,
                        postal_code: document.getElementById('postal_code')?.value || null,
                    }),
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    liveRatesContainer.innerHTML = '';
                    const rates = (data && data.rates) || [];
                    if (!rates.length) { setStatus('No live rates available — using the methods above.'); return; }
                    setStatus('');
                    rates.forEach(function (rate) {
                        const label = document.createElement('label');
                        label.className = 'flex items-center justify-between p-4 border border-hairline rounded-lg cursor-pointer hover:border-primary-300 has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50';
                        const days = rate.delivery_days ? (' · ' + rate.delivery_days + ' day(s)') : '';
                        const input = document.createElement('input');
                        input.type = 'radio';
                        input.name = 'shipping_quote_id';
                        input.value = rate.id;
                        input.className = 'h-4 w-4 text-primary-700';
                        input.dataset.amount = rate.amount;
                        input.addEventListener('change', function () {
                            shippingMethodRadios.forEach(function (fr) { fr.checked = false; });
                            updateShippingDisplay(parseFloat(this.dataset.amount) || 0);
                        });
                        const left = document.createElement('span');
                        left.className = 'flex items-center';
                        const name = document.createElement('span');
                        name.className = 'ml-3 text-sm font-medium text-ink';
                        name.textContent = rate.carrier + ' ' + rate.service + days;
                        left.appendChild(input);
                        left.appendChild(name);
                        const price = document.createElement('span');
                        price.className = 'text-sm font-semibold text-ink';
                        price.textContent = money.format(Number(rate.amount));
                        label.appendChild(left);
                        label.appendChild(price);
                        liveRatesContainer.appendChild(label);
                    });
                })
                .catch(function () { setStatus('Could not fetch live rates — using the methods above.'); });
            });
        }
    });

    // Payment method tab switching
    const TAB_ACTIVE_CLASSES = ['bg-white', 'shadow-sm', 'text-ink', 'border', 'border-hairline'];
    const TAB_INACTIVE_CLASSES = ['text-muted', 'hover:text-ink'];
    const paymentPanels = { stripe: 'stripe-payment', paypal: 'paypal-payment' };

    function selectPaymentMethod(method) {
        document.getElementById('payment_method').value = method;

        Object.keys(paymentPanels).forEach(function(key) {
            const panel = document.getElementById(paymentPanels[key]);
            const tab = document.getElementById('tab-' + key);
            const isActive = key === method;

            if (panel) panel.classList.toggle('hidden', !isActive);
            if (tab) {
                TAB_ACTIVE_CLASSES.forEach(cls => tab.classList.toggle(cls, isActive));
                TAB_INACTIVE_CLASSES.forEach(cls => tab.classList.toggle(cls, !isActive));
            }
        });
    }

    // Initialize payment method tabs
    selectPaymentMethod(@json($paymentGateways[0] ?? ''));
</script>

@if($total > 0)
@if (in_array('stripe', $paymentGateways, true))
<script src="https://js.stripe.com/v3/"></script>
@endif
@if (in_array('paypal', $paymentGateways, true))
<script src="https://www.paypal.com/sdk/js?client-id={{ urlencode((string) config('services.paypal.client_id')) }}&currency={{ $currency }}"></script>
@endif
<script>
@if (in_array('stripe', $paymentGateways, true))
    // Stripe integration
    const stripe = Stripe('{{ config('services.stripe.key') }}');
    const elements = stripe.elements();
    const cardStyle = {
        base: {
            color: '#111827',
            fontFamily: 'ui-sans-serif, system-ui, sans-serif',
            fontSize: '15px',
            fontSmoothing: 'antialiased',
            '::placeholder': {
                color: '#9ca3af',
            },
        },
        invalid: {
            color: '#dc2626',
            iconColor: '#dc2626',
        },
    };
    const card = elements.create('card', { style: cardStyle });
    card.mount('#card-element');

    card.on('change', function(event) {
        const errorIcon = document.getElementById('card-error-icon');
        const errorMessage = document.getElementById('card-error-message');
        if (event.error) {
            errorIcon.classList.remove('hidden');
            errorMessage.textContent = event.error.message;
        } else {
            errorIcon.classList.add('hidden');
            errorMessage.textContent = '';
        }
    });

@endif
    const form = document.getElementById('checkout-form');
    const paymentMethodInput = document.getElementById('payment_method');
    const submitButton = document.getElementById('submit-button');
    let originalButtonHTML = null;

    function setButtonLoading(loading) {
        if (!submitButton) return;
        // Capture original HTML on first call (before any modification)
        if (!loading && originalButtonHTML !== null) {
            submitButton.disabled = false;
            submitButton.innerHTML = originalButtonHTML;
            return;
        }
        if (loading) {
            if (originalButtonHTML === null) {
                originalButtonHTML = submitButton.innerHTML;
            }
            submitButton.disabled = true;
            submitButton.innerHTML = '<svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Processing...';
        }
    }

    form.addEventListener('submit', async (event) => {
        if (paymentMethodInput.value === 'stripe') {
            event.preventDefault();
            setButtonLoading(true);

            const {token, error} = await stripe.createToken(card);

            if (error) {
                const errorIcon = document.getElementById('card-error-icon');
                const errorMessage = document.getElementById('card-error-message');
                errorIcon.classList.remove('hidden');
                errorMessage.textContent = error.message;
                setButtonLoading(false);
            } else {
                const hiddenInput = document.createElement('input');
                hiddenInput.setAttribute('type', 'hidden');
                hiddenInput.setAttribute('name', 'stripeToken');
                hiddenInput.setAttribute('value', token.id);
                form.appendChild(hiddenInput);
                form.submit();
            }
        }
    });

    // PayPal integration
    if (document.getElementById('paypal-button-container')) {
        paypal.Buttons({
            createOrder: function(data, actions) {
                return actions.order.create({
                    purchase_units: [{
                        amount: {
                            value: document.getElementById('total-amount').dataset.amount
                        }
                    }]
                });
            },
            onApprove: function(data, actions) {
                return actions.order.capture().then(function(details) {
                    document.getElementById('paypal_payment_id').value = details.id;
                    form.submit();
                });
            }
        }).render('#paypal-button-container');
    }
</script>
@endif
@endpush
@endsection

