{{-- One fieldset per attribute. The chosen combination fills variant_id; the
     server re-resolves it and prices it, so nothing here is trusted. --}}
@props(['product'])

@php
    $picker = app(\App\View\Storefront\ProductPresenter::class)->variantPicker($product, old('variant_id'));
    $attributes = array_column($picker['attributes'], 'name');
    $variants = $picker['variants'];
    $selected = $picker['selected'];
@endphp

<div x-data="{
        attributes: @js($attributes),
        variants: @js($variants),
        selected: @js((object) $selected),
        get variant() {
            return this.variants.find(v => this.attributes.every(a => v.values[a] === this.selected[a])) ?? null;
        },
        get complete() {
            return this.attributes.every(a => this.selected[a] !== null);
        },
        offered(name, value) {
            return this.variants.some(v => v.values[name] === value && v.available
                && this.attributes.every(a => a === name || this.selected[a] === null || v.values[a] === this.selected[a]));
        },
    }"
     x-init="$watch('variant', v => $dispatch('variant-changed', v))"
     class="mb-6 flex flex-col gap-5">
    @foreach ($picker['attributes'] as $attribute)
        <fieldset x-cloak>
            <legend class="form-label">
                {{ $attribute['name'] }}<span class="text-muted" x-show="selected[@js($attribute['name'])]">:
                    <span x-text="selected[@js($attribute['name'])]"></span></span>
            </legend>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($attribute['values'] as $value)
                    <label class="relative cursor-pointer">
                        <input type="radio"
                               class="peer sr-only"
                               name="variant_choice[{{ $loop->parent->index }}]"
                               value="{{ $value }}"
                               x-model="selected[@js($attribute['name'])]">
                        <span class="inline-flex min-h-11 min-w-11 items-center justify-center gap-1.5 rounded-md border border-stroke px-4 py-2 text-label
                                     peer-checked:border-primary-700 peer-checked:bg-primary-700 peer-checked:font-semibold peer-checked:text-white
                                     peer-focus-visible:ring-2 peer-focus-visible:ring-primary-600 peer-focus-visible:ring-offset-2"
                              :class="offered(@js($attribute['name']), @js($value)) ? 'text-ink' : 'text-muted line-through'">
                            <i class="fa-solid fa-check text-xs" aria-hidden="true" x-show="selected[@js($attribute['name'])] === @js($value)"></i>
                            {{ $value }}
                        </span>
                        <span class="sr-only" x-show="! offered(@js($attribute['name']), @js($value))">(unavailable with this selection)</span>
                    </label>
                @endforeach
            </div>
        </fieldset>
    @endforeach

    <input type="hidden" name="variant_id" :value="variant && variant.available ? variant.id : ''" x-cloak>

    <p class="text-label" aria-live="polite" x-cloak>
        <template x-if="! complete">
            <span class="text-muted">Choose {{ implode(' and ', array_map('strtolower', $attributes)) }} to see the price and availability.</span>
        </template>
        <template x-if="complete && ! variant">
            <span class="font-medium text-danger-700">This combination is not available.</span>
        </template>
        <template x-if="variant">
            <span>
                <span class="data font-semibold text-ink" x-text="variant.price"></span>
                <span class="text-muted">&middot;</span>
                <span x-show="variant.available" class="font-medium text-primary-700">In stock<span class="font-normal text-muted" x-show="variant.stock !== null"> &middot; <span class="data" x-text="variant.stock"></span> available</span></span>
                <span x-show="! variant.available" class="font-medium text-danger-700">Unavailable</span>
                <span x-show="variant.sku" class="block text-caption text-muted">SKU <span class="data" x-text="variant.sku"></span></span>
            </span>
        </template>
    </p>

    <noscript>
        <label for="variant_id" class="form-label">Choose a version</label>
        <select name="variant_id" id="variant_id" class="form-input" required>
            <option value="">Choose…</option>
            @foreach ($variants as $variant)
                <option value="{{ $variant['id'] }}" @disabled(! $variant['available'])>
                    {{ $variant['label'] }} — {{ $variant['price'] }}{{ $variant['available'] ? '' : ' (unavailable)' }}
                </option>
            @endforeach
        </select>
    </noscript>
</div>
