@props(['product'])

@php
    $defaults = app(\App\View\Storefront\ProductPresenter::class)->defaultChoiceIds($product);
@endphp

{{-- Prices shown here are a guide; the server prices the choices. --}}
@foreach ($product->optionGroups as $group)
    <fieldset class="mb-6">
        <legend class="form-label">
            {{ $group->name }}
            @if ($group->isRequired())
                <span class="text-muted">(required)</span>
            @elseif ($group->max_select !== null && ! $group->allowsOne())
                <span class="text-muted">(up to {{ $group->max_select }})</span>
            @endif
        </legend>
        <div class="mt-2 flex flex-col gap-2">
            @foreach ($group->choices as $choice)
                <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded-md border border-stroke px-3 py-2
                              has-checked:border-primary-700 has-checked:bg-primary-50 has-checked:font-medium
                              has-focus-visible:ring-2 has-focus-visible:ring-primary-600
                              @unless ($choice->is_available) cursor-not-allowed text-muted @endunless">
                    <input class="accent-primary-700"
                       data-price-adjustment="{{ (float) $choice->price_adjustment }}"
                           type="{{ $group->allowsOne() ? 'radio' : 'checkbox' }}"
                           name="options[]"
                           value="{{ $choice->id }}"
                           @if ($group->allowsOne() && $group->isRequired()) required @endif
                           @checked(in_array($choice->id, old('options', $defaults)))
                           @disabled(! $choice->is_available)>
                    <span>{{ $choice->name }}</span>
                    @if ((float) $choice->price_adjustment != 0)
                        <span class="data text-muted">{{ (float) $choice->price_adjustment > 0 ? '+' : '−' }}{{ \App\Support\Money::format(abs((float) $choice->price_adjustment)) }}</span>
                    @endif
                    @unless ($choice->is_available)
                        <span class="text-muted">(unavailable)</span>
                    @endunless
                </label>
            @endforeach
        </div>
    </fieldset>
@endforeach
