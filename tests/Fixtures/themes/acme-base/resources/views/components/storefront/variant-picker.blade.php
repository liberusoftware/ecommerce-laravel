@props(['product'])
<p data-acme-base-picker>{{ count(app(\App\View\Storefront\ProductPresenter::class)->variantPicker($product)['variants']) }} versions</p>
